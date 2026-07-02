# PSR Logging Mask*** Middleware

A logging middleware for any [PSR-7](https://www.php-fig.org/psr/psr-7/) HTTP
client that logs every request and response passing through it for later
debugging, with secrets redacted. Headers, query-string arguments and body keys
are masked according to a per-message configuration.

It is built on [PSR-7](https://www.php-fig.org/psr/psr-7/) (messages),
[PSR-17](https://www.php-fig.org/psr/psr-17/) (message factories) and
[PSR-3](https://www.php-fig.org/psr/psr-3/) (logging), so it stays
implementation-agnostic: it works with any PSR-3 logger and any PSR-7
implementation - [Guzzle](https://docs.guzzlephp.org/) is one supported client,
but not a dependency.

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

_Coming soon._

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
