# PSR-compatible Logging Masking Middleware

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

### Any PSR-18 client (decorator)

For a client with no handler stack, wrap it with the `LoggingClient` PSR-18
decorator instead:

```php
use Lezhnev74\PsrLoggingMaskingMiddleware\LoggingClient;

$client = new LoggingClient($innerPsr18Client, $logger);
```

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

## License

MIT - see [LICENSE](LICENSE).
