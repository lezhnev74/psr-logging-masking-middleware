# Guzzle Logging Masking Middleware

A [Guzzle](https://docs.guzzlephp.org/) middleware that logs every request and
response passing through an HTTP client for later debugging, with secrets
redacted. Headers, query-string arguments and body keys are masked according to
a per-message configuration.

It is built on [PSR-7](https://www.php-fig.org/psr/psr-7/) (messages) and
[PSR-3](https://www.php-fig.org/psr/psr-3/) (logging), so it stays
framework-agnostic and works with any PSR-3 logger.

## Requirements

- PHP 8.1 - 8.5

## Installation

```bash
composer require lezhnev74/guzzle-logging-masking-middleware
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
