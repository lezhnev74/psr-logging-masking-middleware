<?php

declare(strict_types=1);

namespace Lezhnev74\PsrLoggingMaskingMiddleware;

use Closure;
use Http\Discovery\Psr17FactoryDiscovery;
use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Stateless PSR-7 masking engine.
 *
 * Given a PSR-7 message (request or response) and a MaskingConfig, returns a
 * masked clone with header values, request-URI query args and body keys
 * redacted per the config. The body is masked per its Content-Type: JSON by
 * key (recursively), urlencoded form by field name; any other type is replaced
 * with a size note so an opaque body is never logged. Names are matched
 * case-insensitively.
 *
 * What lands at each masked location is decided by a replacer closure. The
 * name-lists still *select* which locations are masked; the closure computes the
 * replacement string for each selected scalar leaf, with the message, surface and
 * path in hand (see MaskTarget). The default replacer returns '***', so out of
 * the box a match becomes '***' with no wiring. To redact with a different fixed
 * string, or with any computed value, supply a replacer.
 *
 * The closure runs only at scalar leaves. A matched array/object node is redacted
 * wholesale with '***' (never partially), so a closure never has to reason about
 * a JSON subtree and its MaskTarget->value is always a real scalar.
 *
 * The original message is never mutated (masked clones are built via the PSR-7
 * with*() immutables) and its body is never consumed - it is read through a
 * string copy and a fresh stream is created via the injected PSR-17 factory.
 *
 * @phpstan-type MaskReplacer Closure(MaskTarget): string
 */
class MessageMasker
{
    private readonly StreamFactoryInterface $streamFactory;

    private readonly KeyPathMatcher $pathMatcher;

    /** @var MaskReplacer */
    private readonly Closure $replacer;

    /**
     * @param  ?(Closure(MaskTarget): string)  $replacer  computes the replacement
     *         for each masked scalar leaf; null falls back to the '***' marker.
     */
    public function __construct(
        ?StreamFactoryInterface $streamFactory = null,
        ?KeyPathMatcher $pathMatcher = null,
        ?Closure $replacer = null,
    ) {
        $this->streamFactory = $streamFactory ?? Psr17FactoryDiscovery::findStreamFactory();
        $this->pathMatcher = $pathMatcher ?? new KeyPathMatcher();
        $this->replacer = $replacer ?? static fn (MaskTarget $target): string => '***';
    }

    /**
     * The replacer closure: given a MaskTarget, returns the string to place at
     * that location. The seam a subclass overrides to swap the replacement policy;
     * defaults to returning the '***' marker.
     *
     * @return MaskReplacer
     */
    protected function replacer(): Closure
    {
        return $this->replacer;
    }

    /**
     * Computes the replacement for one matched location by invoking the replacer -
     * the single choke point every redaction write site routes through.
     */
    protected function replace(MaskTarget $target): string
    {
        return ($this->replacer())($target);
    }

    /**
     * Returns a masked clone of a PSR-7 message: request-URI query args, headers
     * and body are redacted. The original message is not mutated.
     *
     * @template T of MessageInterface
     *
     * @param  T  $message
     * @return T
     */
    public function mask(MessageInterface $message, MaskingConfig $config): MessageInterface
    {
        // Mask the query string only for requests (responses have no URI).
        if ($message instanceof RequestInterface) {
            $uri = $message->getUri();
            $message = $message->withUri(
                $uri->withQuery($this->maskFormEncoded($uri->getQuery(), $config->queryNames, $message, MaskKind::Query)),
            );
        }

        foreach ($config->headerNames as $name) {
            if ($message->hasHeader($name)) {
                $message = $message->withHeader(
                    $name,
                    $this->replace(new MaskTarget($message, MaskKind::Header, $name, $message->getHeaderLine($name))),
                );
            }
        }

        $body = $this->maskBody((string)$message->getBody(), $message->getHeaderLine('Content-Type'), $config, $message);

        return $message->withBody($this->streamFactory->createStream($body));
    }

    /**
     * Masks a body per its Content-Type: JSON is masked by key recursively;
     * urlencoded (form) bodies are masked by field name against bodyKeys; any
     * other type is replaced with a size note to preclude leaking secrets.
     */
    public function maskBody(string $body, string $contentType, MaskingConfig $config, MessageInterface $message): string
    {
        if ($body === '') {
            return '';
        }

        return $this->maskBodyByType($this->mediaType($contentType), $body, $config, $message);
    }

    /**
     * Normalises a Content-Type header to its bare media type: drops any
     * ";charset=..." parameters and lower-cases the result.
     */
    protected function mediaType(string $contentType): string
    {
        return strtolower(trim(explode(';', $contentType, 2)[0]));
    }

    /**
     * Dispatches a (non-empty) body to the handler for its media type: JSON
     * (incl. "+json" suffixes) masked by key, urlencoded form masked by field,
     * anything else routed to the unknown-type handler. Override to add a type.
     */
    protected function maskBodyByType(string $type, string $body, MaskingConfig $config, MessageInterface $message): string
    {
        // JSON (incl. "+json" structured suffixes) is masked by key recursively.
        if ($type === 'application/json' || str_ends_with($type, '+json')) {
            return $this->maskJsonBody($body, $type, $config, $message);
        }

        // Urlencoded form bodies are masked by field name.
        if ($type === 'application/x-www-form-urlencoded') {
            return $this->maskFormEncoded($body, $config->bodyKeys, $message, MaskKind::Body);
        }

        return $this->maskUnknownType($type, $body, $config);
    }

    /**
     * Handles a body whose media type has no built-in masker. Default replaces
     * it with a size note so an opaque body is never logged; the primary seam
     * for a subclass to add masking for extra content types (XML, multipart, ...).
     */
    protected function maskUnknownType(string $type, string $body, MaskingConfig $config): string
    {
        return $this->nonLoggableNote($type, $body);
    }

    /**
     * Masks a JSON body by key: objects/arrays are redacted recursively, a valid
     * scalar has no keys so it is logged verbatim, and a body that fails to decode
     * falls through to the non-loggable note.
     */
    protected function maskJsonBody(string $body, string $type, MaskingConfig $config, MessageInterface $message): string
    {
        try {
            $decoded = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->nonLoggableNote($type, $body);
        }

        return is_array($decoded)
            ? (string)json_encode($this->maskArray($decoded, $config, $message))
            : $body;
    }

    /**
     * Builds the placeholder note for an opaque body, keeping the media type
     * (safe metadata) but never the content itself.
     */
    protected function nonLoggableNote(string $type, string $body): string
    {
        return $type === ''
            ? sprintf('<non-loggable body: %d bytes>', strlen($body))
            : sprintf('<non-loggable %s body: %d bytes>', $type, strlen($body));
    }

    /**
     * Masks the values of matching pairs in a form-encoded string (query or
     * body), preserving order and structure. Names match case-insensitively;
     * each matched value is replaced through the replacer with the given kind.
     *
     * @param  list<string>  $names
     */
    protected function maskFormEncoded(string $encoded, array $names, MessageInterface $message, MaskKind $kind): string
    {
        if ($encoded === '') {
            return '';
        }

        $pairs = array_map(function (string $pair) use ($names, $message, $kind): string {
            // Split into name and value; a missing "=" means a valueless flag.
            [$name, $value] = array_pad(explode('=', $pair, 2), 2, null);
            if ($name !== null && $value !== null && $this->matchesInsensitive(rawurldecode($name), $names)) {
                $replacement = $this->replace(
                    new MaskTarget($message, $kind, rawurldecode($name), rawurldecode($value)),
                );

                return $name.'='.rawurlencode($replacement);
            }

            return $pair;
        }, explode('&', $encoded));

        return implode('&', $pairs);
    }

    /**
     * Recursively redacts values whose root-to-node path matches a configured
     * body key (flat name at any depth, or a dot-path with "*"/"**" wildcards).
     * A matched scalar leaf is replaced through the replacer; a matched
     * array/object node is redacted wholesale with '***' (the closure never sees
     * a subtree) and recursion stops there.
     *
     * @param  array<mixed>  $data
     * @param  list<string>  $path  keys from the JSON root to this array
     * @return array<mixed>
     */
    protected function maskArray(array $data, MaskingConfig $config, MessageInterface $message, array $path = []): array
    {
        $result = [];
        foreach ($data as $key => $value) {
            $childPath = [...$path, (string)$key];
            if ($this->pathMatcher->matches($config->bodyKeys, $childPath)) {
                $result[$key] = is_array($value)
                    ? '***'
                    : $this->replace(new MaskTarget($message, MaskKind::Body, implode('.', $childPath), $this->scalarToString($value)));

                continue;
            }
            $result[$key] = is_array($value) ? $this->maskArray($value, $config, $message, $childPath) : $value;
        }

        return $result;
    }

    /**
     * Coerces a matched non-array JSON leaf to the string the replacer receives:
     * strings pass through, other scalars and null take the PHP string cast
     * (`42` -> "42", `false`/`null` -> ""). Masking is lossy by intent, so the
     * original type is not preserved.
     */
    protected function scalarToString(mixed $value): string
    {
        return match (true) {
            is_string($value) => $value,
            is_int($value), is_float($value) => (string)$value,
            $value === true => '1',
            default => '',   // false and null both redact to the empty string
        };
    }

    /**
     * @param  list<string>  $names
     */
    protected function matchesInsensitive(string $name, array $names): bool
    {
        foreach ($names as $candidate) {
            if (strcasecmp($name, $candidate) === 0) {
                return true;
            }
        }

        return false;
    }
}
