<?php

declare(strict_types=1);

namespace Lezhnev74\PsrLoggingMaskingMiddleware;

/**
 * The surface a masked location sits on. Passed to the replacer closure via
 * MaskTarget so a client can key its replacement on where the match was found.
 *
 * Header and query names are flat; a body path is a flat field name for a
 * form-encoded body or a dot-path (e.g. "data.0.name") for a JSON body.
 */
enum MaskKind: string
{
    case Header = 'header';
    case Query = 'query';
    case Body = 'body';
}
