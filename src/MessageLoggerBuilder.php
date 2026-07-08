<?php

declare(strict_types=1);

namespace Lezhnev74\PsrLoggingMaskingMiddleware;

use Closure;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * Fluent builder for a MessageLogger.
 *
 * Collapses the full wiring (a MessageMasker with its MaskingConfig, stream
 * factory and replacer, plus an optional MessageSerializer and the log level)
 * into one readable chain:
 *
 *     $logger = MessageLoggerBuilder::for($psr3Logger)
 *         ->withMaskingConfig(MaskingConfig::create(
 *             headerNames: ['Authorization', 'Set-Cookie'],
 *             queryNames: ['api_key'],
 *             bodyKeys: ['password', 'card.number'],
 *         ))
 *         ->placeholder('[redacted]')
 *         ->logLevel(LogLevel::INFO)
 *         ->build();
 *
 * The builder is mutable and single-use: each setter records state and returns
 * $this, and build() assembles the immutable collaborators once. Masking targets
 * come from a MaskingConfig via withMaskingConfig() (called more than once, each
 * config merges in - deduped case-insensitively), and placeholder()/replaceWith()
 * share one replacement slot so the last one set wins. It is purely additive -
 * the raw MessageLogger / MessageMasker constructors remain the path for one-off
 * subclassing or a custom KeyPathMatcher.
 *
 * @phpstan-type MaskReplacer Closure(MaskTarget): string
 */
final class MessageLoggerBuilder
{
    private ?MaskingConfig $config = null;

    /** @var MaskReplacer|null */
    private ?Closure $replacer = null;

    private ?StreamFactoryInterface $streamFactory = null;

    private ?MessageSerializer $serializer = null;

    private string $logLevel = LogLevel::DEBUG;

    private function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Starts a builder that logs through the given PSR-3 logger.
     */
    public static function for(LoggerInterface $logger): self
    {
        return new self($logger);
    }

    /**
     * Sets the masking config (header names, query names, body keys). Called more
     * than once, each config merges into the previous via MaskingConfig::merge(),
     * which dedupes case-insensitively.
     */
    public function withMaskingConfig(MaskingConfig $config): self
    {
        $this->config = $this->config?->merge($config) ?? $config;

        return $this;
    }

    /**
     * Redacts every matched value to a fixed marker. Overrides any earlier
     * placeholder()/replaceWith() - the last replacement policy set wins.
     */
    public function placeholder(string $marker): self
    {
        $this->replacer = static fn (MaskTarget $target): string => $marker;

        return $this;
    }

    /**
     * Computes each matched value's replacement from a MaskTarget. Overrides any
     * earlier placeholder()/replaceWith() - the last replacement policy set wins.
     *
     * @param  MaskReplacer  $replacer
     */
    public function replaceWith(Closure $replacer): self
    {
        $this->replacer = $replacer;

        return $this;
    }

    /**
     * Pins the PSR-17 stream factory the masker rebuilds bodies with; when unset
     * the masker discovers one via php-http/discovery.
     */
    public function streamFactory(StreamFactoryInterface $factory): self
    {
        $this->streamFactory = $factory;

        return $this;
    }

    /**
     * Overrides the message serializer; defaults to a plain MessageSerializer.
     */
    public function serializer(MessageSerializer $serializer): self
    {
        $this->serializer = $serializer;

        return $this;
    }

    /**
     * Sets the PSR-3 level every record is emitted at; defaults to debug.
     */
    public function logLevel(string $level): self
    {
        $this->logLevel = $level;

        return $this;
    }

    /**
     * Assembles the configured MessageLogger. Without a withMaskingConfig() call
     * an empty MaskingConfig is used, logging both messages unmasked.
     */
    public function build(): MessageLogger
    {
        return new MessageLogger(
            $this->logger,
            new MessageMasker(
                $this->config ?? MaskingConfig::create(),
                $this->streamFactory,
                null,
                $this->replacer,
            ),
            $this->serializer ?? new MessageSerializer(),
            $this->logLevel,
        );
    }
}
