# Security Policy

## Supported versions

This is a single-maintainer, small-scope project. Only the latest tagged
release is supported — please update before reporting an issue if you're
running an older version.

## Reporting a vulnerability

Please **do not** open a public issue for security vulnerabilities. Instead,
use GitHub's private vulnerability reporting for this repo: go to the
[Security tab](https://github.com/Don-Wombat/ts-viewer/security) →
"Report a vulnerability". This opens a private draft advisory visible only
to the maintainer until it's resolved.

This is a best-effort project — there's no formal SLA, but security reports
will be prioritized over regular feature/bug work.

## Scope notes

- ts-viewer is a read-only viewer with no admin functionality and no
  database — the main attack surface is the PHP app itself (`html/`) and
  the Docker/CI supply chain (`Dockerfile`, `.github/workflows/`).

## Security notes

Security-relevant design decisions already in place:

- Use a dedicated, read-only ServerQuery login instead of the admin account.
- Prefer the `ssh` transport; `raw` transmits the password and server data
  in plain text.
- With the `ssh` transport, the host key is pinned on first connect
  (`StrictHostKeyChecking=accept-new`) and stored in
  `TS_CACHE_DIR/known_hosts` — if the key changes afterwards (e.g. due to a
  MITM), the connection fails instead of silently going through.
- Cache, lock file and `known_hosts` live in a dedicated, non-world-writable
  directory (`0700`, `www-data`) instead of `/tmp`. The container entrypoint
  resets these permissions on every start (not just at image build time),
  since a volume or bind mount at `TS_CACHE_DIR` would otherwise override
  the permissions set in the image.
- HTTP security headers (`X-Content-Type-Options: nosniff`,
  `Referrer-Policy: no-referrer`, `X-Frame-Options: DENY`,
  `Content-Security-Policy: frame-ancestors 'none'`) on every response,
  including `?ajax=1`/`?health=1`.
- All values coming from the TS server (channel/nicknames, topics, group
  names) pass through `htmlspecialchars()` before output — including role
  badges and the channel topic.
- The online-time tracker's background poller (if enabled) runs as
  `www-data`, not root, and each poll is bounded by an outer `timeout` so a
  wedged subprocess (e.g. the SSH transport's own child process) can't hang
  the loop forever. `TS_CACHE_DIR`'s ownership is corrected recursively on
  every container start, not just the directory itself — a file left behind
  from a previous container generation (e.g. under a different user) is
  fixed too, instead of silently staying unwritable.
