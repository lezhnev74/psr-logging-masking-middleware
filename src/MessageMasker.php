<?php

declare(strict_types=1);

namespace Lezhnev74\PsrLoggingMaskingMiddleware;

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
 * The original message is never mutated (masked clones are built via the PSR-7
 * with*() immutables) and its body is never consumed - it is read through a
 * string copy and a fresh stream is created via the injected PSR-17 factory.
 */
class MessageMasker
{
    private readonly StreamFactoryInterface $streamFactory;

    private readonly KeyPathMatcher $pathMatcher;

    public function __construct(
        ?StreamFactoryInterface $streamFactory = null,
        private readonly string $placeholder = '***',
        ?KeyPathMatcher $pathMatcher = null,
    ) {
        $this->streamFactory = $streamFactory ?? Psr17FactoryDiscovery::findStreamFactory();
        $this->pathMatcher = $pathMatcher ?? new KeyPathMatcher();
    }

    /**
     * The marker substituted for every redacted header, query arg and body key.
     * Set once via the constructor; override for a computed marker.
     */
    protected function placeholder(): string
    {
        return $this->placeholder;
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
            $message = $message->withUri($uri->withQuery($this->maskQuery($uri->getQuery(), $config)));
        }

        foreach ($config->headerNames as $name) {
            if ($message->hasHeader($name)) {
                $message = $message->withHeader($name, $this->placeholder());
            }
        }

        $body = $this->maskBody((string)$message->getBody(), $message->getHeaderLine('Content-Type'), $config);

        return $message->withBody($this->streamFactory->createStream($body));
    }

    /**
     * Masks the values of matching query args, preserving order and string structure.
     */
    public function maskQuery(string $query, MaskingConfig $config): string
    {
        return $this->maskFormEncoded($query, $config->queryNames);
    }

    /**
     * Masks a body per its Content-Type: JSON is masked by key recursively;
     * urlencoded (form) bodies are masked by field name against bodyKeys; any
     * other type is replaced with a size note to preclude leaking secrets.
     */
    public function maskBody(string $body, string $contentType, MaskingConfig $config): string
    {
        if ($body === '') {
            return '';
        }

        return $this->maskBodyByType($this->mediaType($contentType), $body, $config);
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
    protected function maskBodyByType(string $type, string $body, MaskingConfig $config): string
    {
        // JSON (incl. "+json" structured suffixes) is masked by key recursively.
        if ($type === 'application/json' || str_ends_with($type, '+json')) {
            return $this->maskJsonBody($body, $type, $config);
        }

        // Urlencoded form bodies are masked by field name.
        if ($type === 'application/x-www-form-urlencoded') {
            return $this->maskFormEncoded($body, $config->bodyKeys);
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
    protected function maskJsonBody(string $body, string $type, MaskingConfig $config): string
    {
        try {
            $decoded = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->nonLoggableNote($type, $body);
        }

        return is_array($decoded)
            ? (string)json_encode($this->maskArray($decoded, $config))
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
     * body), preserving order and structure. Names match case-insensitively.
     *
     * @param  list<string>  $names
     */
    protected function maskFormEncoded(string $encoded, array $names): string
    {
        if ($encoded === '') {
            return '';
        }

        $pairs = array_map(function (string $pair) use ($names): string {
            // Split into name and value; a missing "=" means a valueless flag.
            [$name, $value] = array_pad(explode('=', $pair, 2), 2, null);
            if ($value !== null && $this->matchesInsensitive(rawurldecode($name), $names)) {
                return $name.'='.rawurlencode($this->placeholder());
            }

            return $pair;
        }, explode('&', $encoded));

        return implode('&', $pairs);
    }

    /**
     * Recursively redacts values whose root-to-node path matches a configured
     * body key (flat name at any depth, or a dot-path with "*"/"**" wildcards).
     *
     * @param  array<mixed>  $data
     * @param  list<string>  $path  keys from the JSON root to this array
     * @return array<mixed>
     */
    protected function maskArray(array $data, MaskingConfig $config, array $path = []): array
    {
        $result = [];
        foreach ($data as $key => $value) {
            $childPath = [...$path, (string)$key];
            if ($this->pathMatcher->matches($config->bodyKeys, $childPath)) {
                $result[$key] = $this->placeholder();

                continue;
            }
            $result[$key] = is_array($value) ? $this->maskArray($value, $config, $childPath) : $value;
        }

        return $result;
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
