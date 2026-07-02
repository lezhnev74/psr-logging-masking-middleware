<?php

declare(strict_types=1);

namespace Lezhnev74\PsrLoggingMaskingMiddleware;

/**
 * Single source of the redaction marker substituted for masked secrets.
 */
final class Redaction
{
    public const PLACEHOLDER = '***';
}
