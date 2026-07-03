# PSR-compatible Logging Masking Middleware

[![tests](https://github.com/lezhnev74/psr-logging-masking-middleware/actions/workflows/tests.yml/badge.svg)](https://github.com/lezhnev74/psr-logging-masking-middleware/actions/workflows/tests.yml)
[![codecov](https://codecov.io/gh/lezhnev74/psr-logging-masking-middleware/branch/main/graph/badge.svg)](https://codecov.io/gh/lezhnev74/psr-logging-masking-middleware)
[![PHPStan](https://img.shields.io/badge/PHPStan-level%20max-brightgreen)](https://phpstan.org/)

A logging middleware for any [PSR-7](https://www.php-fig.org/psr/psr-7/) HTTP
client that logs every request and response passing through it for later
debugging, with **secrets redacted**. Headers, query-string arguments and body keys
are masked according to a per-message configuration.

It is built on [PSR-7](https://www.php-fig.org/psr/psr-7/) (messages),
[PSR-17](https://www.php-fig.org/psr/psr-17/) (message factories) and
[PSR-3](https://www.php-fig.org/psr/psr-3/) (logging), so it stays
implementation-agnostic: it works with any PSR-3 logger and any PSR-7
implementation, none of which is a hard dependency.

## Requirements

- PHP 8.1 - 8.5
- Any PSR-7 / PSR-17 implementation installed in your app (e.g.
  `guzzlehttp/psr7` or `nyholm/psr7`) - discovered automatically via
  [php-http/discovery](https://docs.php-http.org/en/latest/discovery.html).

## Installation

```bash
composer require lezhnev74/psr-logging-masking-middleware
```

## Usage

> Every snippet below is backed by a green test - the linked `tests/*Test.php`
> file is the executable, always-current spec for that example.

The core is a `MessageLogger`: give it a PSR-3 logger and, optionally, a
`MaskingConfig` for the request and one for the response. It masks each message
and writes the exchange as a single debug record. A `null` config logs that
message unmasked.

```php
use Lezhnev74\PsrLoggingMaskingMiddleware\MaskingConfig;
use Lezhnev74\PsrLoggingMaskingMiddleware\MessageLogger;

$logger = new MessageLogger(
    $psr3Logger,
    // request: mask secrets you send
    MaskingConfig::create(
        headerNames: ['Authorization'],
        queryNames: ['api_key'],
        bodyKeys: ['password'],
    ),
    // response: mask secrets you receive
    MaskingConfig::create(headerNames: ['Set-Cookie']),
);
```

`MessageLogger` discovers a PSR-17 stream factory automatically. To pin one,
pass a `MessageMasker` built with your factory as the 4th argument.

See examples at [tests/MessageLoggerTest.php](tests/MessageLoggerTest.php).

### Per-message masking fields

The constructor `MaskingConfig` is the default; to vary the masked fields per
request or response, subclass `MessageLogger` and override the
`resolveRequestConfig()` / `resolveResponseConfig()` seams. They receive the
message (and, for responses, the request too), so you can key the config on
path, method, headers, or anything else - returning `null` logs that message
unmasked.

```php
use Lezhnev74\PsrLoggingMaskingMiddleware\MaskingConfig;
use Lezhnev74\PsrLoggingMaskingMiddleware\MessageLogger;
use Psr\Http\Message\RequestInterface;

$logger = new class ($psr3Logger) extends MessageLogger {
    // Mask Authorization only on /secure; log every other path unmasked.
    protected function resolveRequestConfig(RequestInterface $request): ?MaskingConfig
    {
        return $request->getUri()->getPath() === '/secure'
            ? MaskingConfig::create(headerNames: ['Authorization'])
            : null;
    }
};
```

See examples at [tests/MessageLoggerTest.php](tests/MessageLoggerTest.php)
(`testResolvesMaskingConfigPerMessage`, `testResolveResponseConfigIsKeyedOnRequest`).

### Custom replacement values (replacer closure)

The name-lists in `MaskingConfig` decide *which* locations are masked; what
string lands there is decided by a **replacer** closure. Out of the box a match
becomes `'***'`, so nothing extra is needed. Pass a `replacer:` to compute the
replacement per location - it receives a single `MaskTarget` with the message,
the surface (`MaskKind::Header` / `Query` / `Body`), the path, and the current
value:

```php
use Lezhnev74\PsrLoggingMaskingMiddleware\{MaskKind, MaskTarget, MessageMasker};

// Defaults - '***' everywhere.
$masker = new MessageMasker();

// A different fixed marker - just return it (there is no placeholder argument).
$masker = new MessageMasker(replacer: fn (MaskTarget $t): string => '[REDACTED]');

// Format-preserving: keep the last 4 chars of any masked value.
$masker = new MessageMasker(replacer: fn (MaskTarget $t): string
    => strlen($t->value) > 4
        ? str_repeat('*', strlen($t->value) - 4) . substr($t->value, -4)
        : '***');

// Location-aware: hash the Authorization header, star everything else.
$masker = new MessageMasker(replacer: fn (MaskTarget $t): string
    => $t->kind === MaskKind::Header && strcasecmp($t->path, 'Authorization') === 0
        ? 'sha256:' . substr(hash('sha256', $t->value), 0, 12)
        : '***');
```

Then hand the masker to the logger as usual (4th `MessageLogger` argument).

Notes:

- **No separate placeholder.** The redaction string is whatever the replacer
  returns; the default replacer returns `'***'`. To change the fixed marker,
  return a different string.
- **Scalar leaves only.** For a JSON body the closure runs at each matched
  scalar; a matched *array/object* node is redacted wholesale with `'***'` and
  never reaches the closure (so you never have to reason about a subtree).
- **Values arrive as strings.** Non-string JSON scalars are string-cast before
  the closure sees them (`42` -> `"42"`; `false` and `null` -> `""`). Masking is
  lossy by intent, so the original type is not preserved.

### Generic clients (handler stacks)

`HandlerMiddleware::for()` turns the logger into a middleware closure - the
`fn(callable $handler): callable` shape that Guzzle and other handler-stack
clients expect. It taps the returned promise: it logs the masked exchange on a
resolved response, and logs the failure (then re-throws) on a rejection. It
depends on PSR types only - no concrete client is imported.

```php
use Lezhnev74\PsrLoggingMaskingMiddleware\HandlerMiddleware;

$middleware = HandlerMiddleware::for($logger);
// $stack->push($middleware);  // push onto any compatible handler stack
```

See examples at [tests/HandlerMiddlewareTest.php](tests/HandlerMiddlewareTest.php).

### Guzzle

Push the middleware onto Guzzle's `HandlerStack`:

```php
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use Lezhnev74\PsrLoggingMaskingMiddleware\HandlerMiddleware;

$stack = HandlerStack::create();
$stack->push(HandlerMiddleware::for($logger));

$client = new Client(['handler' => $stack]);
```

Every request and response now flows through your PSR-3 logger with secrets
masked.

See examples at [tests/GuzzleClientTest.php](tests/GuzzleClientTest.php).

### Laravel (`Http` facade)

Laravel's HTTP client wraps Guzzle, so pass the same middleware-carrying
handler stack through `withOptions`:

```php
use GuzzleHttp\HandlerStack;
use Illuminate\Support\Facades\Http;
use Lezhnev74\PsrLoggingMaskingMiddleware\HandlerMiddleware;

$stack = HandlerStack::create();
$stack->push(HandlerMiddleware::for($logger));

$response = Http::withOptions(['handler' => $stack])
    ->get('https://example.com/');
```

Apply it to every outgoing request app-wide by setting it as the default in a
service provider's `boot()` with `Http::globalOptions(['handler' => $stack])`.

See examples at [tests/LaravelHttpFacadeTest.php](tests/LaravelHttpFacadeTest.php).

### Any PSR-18 client (decorator)

For a client with no handler stack, wrap it with the `LoggingClient` PSR-18
decorator instead:

```php
use Lezhnev74\PsrLoggingMaskingMiddleware\LoggingClient;

$client = new LoggingClient($innerPsr18Client, $logger);
```

See examples at [tests/LoggingClientTest.php](tests/LoggingClientTest.php).

## Local development

All QA tooling runs inside a PHP 8.5 Docker container (with Xdebug) via the
`dev` wrapper script - no PHP is needed on the host. Each command tunnels to the
matching [Composer script](composer.json) inside the container, so
`./dev <script>` is just `composer <script>` run in Docker.

```bash
cp .env.dist .env  # set DOCKER_USER_ID / DOCKER_GROUP_ID (see `id -u` / `id -g`)
./dev install      # composer install
./dev test         # run the test suite
./dev check        # code style + static analysis + tests
./dev cs-fix       # auto-fix code style
./dev debug-test   # run tests under Xdebug (step-debugging)
```

Anything unrecognized is passed to `composer`, so `./dev require <pkg>`,
`./dev stan`, `./dev rector` etc. all work. Use `./dev php ...` for a raw PHP
call, `./dev sh` for a shell, and `./dev compose ...` for raw `docker compose`.

## Contributing

A few conventions, kept light:

- **TDD.** Every new branch needs a covering test; the masking/serialization
  suite must stay green against both Guzzle and Nyholm PSR-7 impls.
- **Conventional Commits** ([spec](https://www.conventionalcommits.org/)) for
  messages, e.g. `feat(masking): ...`, `fix: ...`, `test: ...`.
- **Semantic versioning.** Tags are `vMAJOR.MINOR.PATCH`; the commit type drives
  the bump (`fix` → patch, `feat` → minor, a `!`/`BREAKING CHANGE` → major).
- Run `./dev check` (style + static analysis + tests) before opening a PR.

## License

MIT - see [LICENSE](LICENSE).
