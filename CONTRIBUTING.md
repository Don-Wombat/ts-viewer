# Contributing to ts-viewer

Thanks for considering a contribution! A quick note on conventions before you
dive in:

- **Code, comments, docs and commit messages are in English.** That's the
  project convention going forward (an earlier version of this file used to
  say comments were in German — that's no longer the case, everything has
  been translated). The one exception: `html/lib/i18n.php`'s `'de'` array is
  the actual German language pack shown to end users and must stay in
  German — that's content, not a comment.
- Keep changes minimal and focused. This project is intentionally a single
  small PHP app + Docker image, not a framework — please avoid introducing
  build tooling, dependencies, or an ORM/framework unless there's no
  reasonable alternative.

## Setup

```bash
cp .env.example .env   # fill in a test TeamSpeak server's credentials
docker compose -f docker-compose.example.yml up -d --build
```

See [README.md](README.md) for the full list of environment variables.

## Project layout

```
html/index.php           Entry point: bootstraps config/i18n, routes ?ajax=1/?health=1
html/config.php          Reads env vars into a $config array
html/lib/ts_protocol.php  ServerQuery text-protocol escaping/parsing (transport-agnostic)
html/lib/ts_transport*.php  SSH and raw-TCP ServerQuery transports (TsQueryTransport interface)
html/lib/ts_client.php   Builds the ServerQuery command bundle, calls the transport, parses the result
html/lib/cache.php       File-based cache with locking (avoids hammering the TS server)
html/lib/render.php      Turns parsed data into the HTML channel tree
html/lib/i18n.php        Translations (`ts_t()`) - see "Adding a UI string" below
bin/                     CLI test scripts (not shipped in the Docker image beyond the app itself)
docker/entrypoint.sh     Fixes TS_CACHE_DIR ownership at container start (survives volume mounts)
```

## Running the tests

There's no PHPUnit here — a couple of small, dependency-free scripts cover
the parts that matter most:

```bash
# Syntax-check every PHP file
php -l html/index.php html/config.php html/lib/*.php bin/*.php

# Protocol parser unit tests (escaping, list/single parsing, uptime formatting)
php bin/selftest_parser.php

# Integration test for the raw ServerQuery transport against a local mock socket
php bin/test_raw_transport.php
```

If you're changing `html/lib/ts_transport_raw.php` or `ts_transport_ssh.php`,
please also update `bin/mock_serverquery.php` / `bin/test_raw_transport.php`
so the new behavior has coverage — this project has already caught several
real bugs (permission issues, a missing escape rule, an uncaught exception)
purely because these tests exist. If you have access to a real TeamSpeak 3/5
server, testing against it (not just the mock) is even better — see the
"Raw" row in the transport table in the README for how to enable raw
ServerQuery on a test server.

### Browser E2E test (`bin/e2e_playwright.mjs`)

The tests above never render or execute anything in a real browser — they
check the PHP output/parsing directly. `bin/e2e_playwright.mjs` closes that
gap with [Playwright](https://playwright.dev/): it loads the actual page
against a running container backed by `bin/mock_serverquery.php`, and checks
the rendered DOM, the language switch, the security headers and the error
state (server unreachable), plus that the browser console stays free of
errors. Requires a running instance and Node with the `playwright` package
installed (not a dependency of the PHP app itself — only needed for this
check):

```bash
docker build -t ts-viewer:local .
php bin/mock_serverquery.php 10011 ok &                 # one connection, then exits
docker run -d --name ts-viewer-local --network host \
  -e TS_HOST=127.0.0.1 -e TS_TRANSPORT=raw -e TS_PORT=10011 \
  -e TS_USER=x -e TS_PASS=x -e TS_CACHE_TTL=600 ts-viewer:local

npm install --no-save playwright && npx playwright install --with-deps chromium
TS_VIEWER_URL=http://127.0.0.1/ node bin/e2e_playwright.mjs

docker rm -f ts-viewer-local
```

If you're changing anything under `html/` that affects what a visitor sees
(markup, CSS, i18n strings, the error/health states), please also update this
script so the new behavior has coverage.

CI (`.github/workflows/ci.yml`) runs the PHP checks, a real `docker build` +
HTTP smoke test, and the Playwright E2E check above, on every push/PR.

## Adding a UI string

User-facing text goes through `html/lib/i18n.php` (`ts_t('key')`), not
hardcoded strings — the UI supports German and English. Please add new
strings to both language arrays in that file.

## Reporting bugs / requesting features

Use the issue templates under `.github/ISSUE_TEMPLATE/`. Security issues:
please don't open a public issue — see below.

## Security

See [SECURITY.md](SECURITY.md) for how to report a vulnerability privately.
