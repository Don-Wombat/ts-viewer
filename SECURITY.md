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
- See the "Security notes" section in [README.md](README.md) for the
  security-relevant design decisions already in place (credential handling,
  host-key pinning, output escaping, HTTP security headers, cache directory
  permissions).
