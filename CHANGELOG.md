# Changelog

Format based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

### Added
- GitHub Actions workflow (`publish.yml`), publishes the Docker image as a
  GitHub Package under
  [`ghcr.io/don-wombat/ts-viewer`](https://github.com/Don-Wombat/ts-viewer/pkgs/container/ts-viewer)
  on every `v*` tag (public, no login required to pull) — an alternative to
  the local build from `docker-compose.example.yml`

## [v0.1.6.2] – German translation fix

### Fixed
- `Clients`/`Channels`/`Uptime`/`Away` were accidentally left in English in
  the German language pack (a mistake in the original i18n setup in v0.1.6)
  — now correctly "Nutzer", "Kanäle", "Laufzeit", "Abwesend".

## [v0.1.6.1] – Docs rework + topic filter

### Fixed
- Trivial/placeholder channel topics (1 character, e.g. a leftover "1" from
  channel templates/copy operations) are no longer shown. Found live on a
  real server where literally every channel had `channel_topic="1"` set.

### Changed
- README: documented the CI badge, the `servergrouplist` permission, the
  security headers and the `?health=1` endpoint
- CONTRIBUTING.md: added a project layout overview
- Added GitHub release notes for v0.1.6 (previously just an empty,
  auto-created release)

## [v0.1.6] – Status icons, topic, i18n, docs, review fixes

### Added
- `.dockerignore` (excludes `.env`, `.git`, `bin/deploy*` from the Docker build context)
- CI integration test (`bin/mock_serverquery.php` + `bin/test_raw_transport.php`)
  covering the actual connect/login/parsing path of the raw transport
- `restart: unless-stopped` in `docker-compose.example.yml`
- Security headers (`X-Content-Type-Options`, `Referrer-Policy`,
  `X-Frame-Options`, `Content-Security-Policy: frame-ancestors`)
- `?health=1` endpoint without a TS server roundtrip + Dockerfile `HEALTHCHECK`
- Configurable cache TTL (`TS_CACHE_TTL`, `TS_CACHE_ERROR_TTL`)
- Channel topic display
- Mute status icons (microphone/speaker) and a server group badge
  (e.g. "Server Admin") per client
- Language selection German/English (`TS_DEFAULT_LANG`, `?lang=de|en`,
  toggle in the header)
- `CONTRIBUTING.md` and issue templates
- This `CHANGELOG.md`

### Fixed
- `ts_create_transport()` threw an `InvalidArgumentException` on an unknown
  `TS_TRANSPORT`, which wasn't caught by the existing `catch` → a raw PHP
  error page instead of the error box
- IPv6 bug in the raw transport (`stream_socket_client` needed bracket
  syntax for literal IPv6 hosts)
- Orphaned `pid` references in the channel tree were silently not rendered
  instead of being attached to the root
- Critical (found during a high-effort review): an upgrade path with an
  old-format cache file still present (error as a string instead of a
  key+vars array) triggered an uncaught `TypeError` — a real fatal-error
  page for visitors for up to `error_ttl` seconds after a deploy.
  `render.php` now reads both formats.
- `TS_CACHE_TTL`/`TS_CACHE_ERROR_TTL` had no floor: a typo like
  `TS_CACHE_TTL=abc` cast to `0`, effectively disabling the cache and
  producing constant polling (`setInterval(fn, 0)`) against the page itself
  in the frontend. Now guarded with `max(1, ...)`.
- An invalid `ts_lang` cookie value ended up unvalidated in the
  `<html lang="...">` attribute, even though the German fallback already
  applied internally.

All points verified: `php -l`, `bin/selftest_parser.php`,
`bin/test_raw_transport.php`, as well as a real `docker build` + container
run against a real TeamSpeak 3 server (DE and EN, health endpoint,
configured cache TTL, old-cache compatibility).

## [v0.1.5] – bin/deploy\* excluded from the repo
Purely organizational: `bin/deploy.sh`/`bin/deploy.env(.example)` were only
meant for local testing and contained real infrastructure details (IP,
port, path) — now fully excluded from the repo via `.gitignore`.

## [v0.1.4] – Fix: `.env` was never actually read
`docker-compose.example.yml` had fixed example values in the
`environment:` block instead of using `env_file: .env`. Any change to
`.env` was therefore ineffective (e.g. `TS_HOST is not configured` despite
`TS_HOST` being set).

## [v0.1.3] – Fix: cache directory permissions now survive volume/bind mounts
`chown` in the Dockerfile only affects the image — a volume or bind mount
at `TS_CACHE_DIR` completely overrides the permissions set there. A new
container entrypoint (`docker/entrypoint.sh`) now resets the permissions on
every container start.

## [v0.1.2] – Critical fix: Dockerfile never copied `html/` into the image
The `Dockerfile` only installed packages without ever `COPY`ing the app
into the image — every fresh build (including v0.1.1) served a 403 instead
of the actual page.

## [v0.1.1] – Initial release
First public version: module structure (`config.php`, `lib/ts_protocol.php`,
`lib/ts_transport*.php`, `lib/ts_client.php`, `lib/cache.php`,
`lib/render.php`), swappable SSH or raw-TCP ServerQuery transport for TS3
(from 3.3.0), TS5 and TS6, branding fully configurable via environment
variables, MIT license.
