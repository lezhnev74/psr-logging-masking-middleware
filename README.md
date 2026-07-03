# PSR-compatible Logging Masking Middleware

[![tests](https://github.com/lezhnev74/psr-logging-masking-middleware/actions/workflows/tests.yml/badge.svg)](https://github.com/lezhnev74/psr-logging-masking-middleware/actions/workflows/tests.yml)
[![codecov](https://codecov.io/gh/lezhnev74/psr-logging-masking-middleware/branch/main/graph/badge.svg)](https://codecov.io/gh/lezhnev74/psr-logging-masking-middleware)
[![PHPStan](https://img.shields.io/badge/PHPStan-level%20max-brightgreen)](https://phpstan.org/)

A logging middleware for any [PSR-7](https://www.php-fig.org/psr/psr-7/) HTTP
client that logs every request and response for later debugging, with **secrets
redacted** - headers, query-string args and body keys masked per message. Built
on PSR-7 (messages), [PSR-17](https://www.php-fig.org/psr/psr-17/) (factories,
auto-discovered) and [PSR-3](https://www.php-fig.org/psr/psr-3/) (logging), so it
works with any PSR-7 impl and PSR-3 logger - none a hard dependency.

## Requirements

- PHP 8.1 - 8.5
- Any PSR-7 / PSR-17 implementation in your app (e.g. `guzzlehttp/psr7` or
  `nyholm/psr7`), discovered via
  [php-http/discovery](https://docs.php-http.org/en/latest/discovery.html).

## Installation

```bash
composer require lezhnev74/psr-logging-masking-middleware
```

## Usage

The core is a `MessageLogger`: give it a PSR-3 logger and, optionally, a
`MaskingConfig` for the request and one for the response. It masks each message
and writes the exchange as a single debug record. A `null` config logs that
message unmasked. Bodies are masked by content-type - JSON by key (recursively),
`x-www-form-urlencoded` by field name, any other type replaced with a
`<content-type: N bytes>` note so an opaque body never leaks.

```php
use Lezhnev74\PsrLoggingMaskingMiddleware\MaskingConfig;
use Lezhnev74\PsrLoggingMaskingMiddleware\MessageLogger;

$logger = new MessageLogger(
    $psr3Logger,
    MaskingConfig::create(                       // applied to request AND response
        headerNames: ['Authorization', 'Set-Cookie'],
        queryNames: ['api_key'],
        bodyKeys: ['password'],
    ),
);
```

One config masks both messages - list every secret name wherever it may appear.
Pass a `MessageMasker` as the 3rd argument to pin a PSR-17 stream factory or
customize the replacement string. See
[tests/MessageLoggerTest.php](tests/MessageLoggerTest.php).

### Guzzle

`HandlerMiddleware::for($logger)` returns a generic `fn(callable): callable`
middleware for any handler stack. Push it onto Guzzle's:

```php
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use Lezhnev74\PsrLoggingMaskingMiddleware\HandlerMiddleware;

$stack = HandlerStack::create();
$stack->push(HandlerMiddleware::for($logger));

$client = new Client(['handler' => $stack]);
```

See [tests/GuzzleClientTest.php](tests/GuzzleClientTest.php) and
[tests/HandlerMiddlewareTest.php](tests/HandlerMiddlewareTest.php).

### More

Each is a green test - the executable, always-current spec:

- **Per-message masking** - subclass `MessageLogger` and override the single
  `resolveConfig()` seam to vary masked fields by path, method, headers, etc.; it
  receives the message being masked and the exchange's request, so it can key on
  either (return an empty `MaskingConfig::create()` = unmasked):
  [tests/MessageLoggerTest.php](tests/MessageLoggerTest.php).
- **Custom replacement** - pass a `replacer:` closure to `MessageMasker`; it
  receives a `MaskTarget` (message, kind, path, value) and returns the string to
  substitute (default `'***'`): [tests/MessageMaskerTest.php](tests/MessageMaskerTest.php).
- **Laravel `Http` facade** - pass the same handler stack via
  `Http::withOptions(['handler' => $stack])` (or `Http::globalOptions(...)` in a
  provider): [tests/LaravelHttpFacadeTest.php](tests/LaravelHttpFacadeTest.php).
- **Any PSR-18 client** (no handler stack) - wrap it with the `LoggingClient`
  decorator: [tests/LoggingClientTest.php](tests/LoggingClientTest.php).

## Local development

QA tooling runs in a PHP 8.5 Docker container (with Xdebug) via the `dev`
wrapper - no host PHP needed. `./dev <script>` is `composer <script>` in Docker;
anything unrecognized is passed to `composer`.

```bash
cp .env.dist .env  # set DOCKER_USER_ID / DOCKER_GROUP_ID (see `id -u` / `id -g`)
./dev install      # composer install
./dev test         # run the test suite
./dev check        # code style + static analysis + tests
./dev cs-fix       # auto-fix code style
```

## Contributing

- **TDD.** Every new branch needs a covering test; the masking/serialization
  suite must stay green against both Guzzle and Nyholm PSR-7 impls.
- **Conventional Commits** ([spec](https://www.conventionalcommits.org/)) drive
  **semantic versioning** (`fix` → patch, `feat` → minor, `!`/`BREAKING CHANGE`
  → major).
- Run `./dev check` before opening a PR.

## License

MIT - see [LICENSE](LICENSE).
