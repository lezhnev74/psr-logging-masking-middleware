<?php

declare(strict_types=1);

namespace Lezhnev74\PsrLoggingMaskingMiddleware;

use Psr\Http\Message\MessageInterface;

/**
 * A {@see Masker} that binds one {@see MaskingConfig} to a {@see MessageMasker}
 * engine, turning the two-argument engine call into the one-argument contract.
 */
final class ConfiguredMasker implements Masker
{
    private function __construct(
        private readonly MessageMasker $engine,
        private readonly MaskingConfig $config,
    ) {
    }

    /**
     * Builds a masker bound to one masking config. A custom engine (different
     * stream factory, replacer or body handling) is injectable; the default
     * engine discovers a PSR-17 stream factory and redacts with '***'.
     */
    public static function create(MaskingConfig $config, ?MessageMasker $engine = null): self
    {
        return new self($engine ?? new MessageMasker(), $config);
    }

    /**
     * @template T of MessageInterface
     *
     * @param  T  $message
     * @return T
     */
    public function mask(MessageInterface $message): MessageInterface
    {
        return $this->engine->mask($message, $this->config);
    }
}
