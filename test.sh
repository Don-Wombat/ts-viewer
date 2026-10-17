#!/usr/bin/env bash
# Quick local test run - PHP syntax check + the two PHP test suites, same
# commands as the "lint-and-test" job in .github/workflows/ci.yml, wrapped
# via qtest (~/.claude/CLAUDE.md section 9) to keep output terse.
#
# The slow CI-only parts (Docker image build + browser-e2e against a mock
# ServerQuery server) stay out of the default run - this sandbox's Docker
# daemon can't reach deb.debian.org's IPv6-only address to install the
# transport's SSH deps, and the full E2E pass takes minutes. Run
# "./test.sh full" to also exercise those (works fine on a machine with
# normal internet access, e.g. the actual CI runner).
#
# Usage: ./test.sh [full]
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")"

QTEST="$HOME/.claude/bin/qtest"
IMAGE="ts-viewer-qtest"

"$QTEST" --name "lint image" -- docker build -q -t "$IMAGE" -f - . <<'EOF'
FROM php:8.3-cli
WORKDIR /app
COPY html html
COPY bin bin
COPY cli cli
EOF

"$QTEST" --name "php -l" -- docker run --rm "$IMAGE" sh -c '
  for f in html/*.php html/lib/*.php bin/*.php cli/*.php; do php -l "$f" || exit 1; done
'
"$QTEST" --name "selftest_parser.php" -- docker run --rm "$IMAGE" php bin/selftest_parser.php
"$QTEST" --name "test_raw_transport.php" -- docker run --rm "$IMAGE" php bin/test_raw_transport.php

if [ "${1:-}" = "full" ]; then
  "$QTEST" --name "docker build (real Dockerfile)" -- docker build -q -t ts-viewer-full-test .

  "$QTEST" --name "mock image" -- docker build -q -t ts-viewer-mock -f - bin <<'EOF'
FROM php:8.3-cli
WORKDIR /app
COPY mock_serverquery.php mock_serverquery.php
CMD ["php", "mock_serverquery.php", "10011", "ok"]
EOF

  "$QTEST" --name "browser e2e" -- bash -c '
    set -e
    docker rm -f ts-viewer-full-mock ts-viewer-full-happy ts-viewer-full-error >/dev/null 2>&1 || true
    docker run -d --name ts-viewer-full-mock --network host ts-viewer-mock >/dev/null
    sleep 1
    docker run -d --name ts-viewer-full-happy --network host \
      -e TS_HOST=127.0.0.1 -e TS_TRANSPORT=raw -e TS_PORT=10011 -e TS_USER=testuser -e TS_PASS=testpass \
      -e TS_CACHE_TTL=600 -e TS_CACHE_ERROR_TTL=600 ts-viewer-full-test >/dev/null
    docker run -d --name ts-viewer-full-error -p 18099:80 \
      -e TS_HOST=203.0.113.1 -e TS_TRANSPORT=raw -e TS_CONNECT_TIMEOUT=1 -e TS_USER=x -e TS_PASS=x \
      ts-viewer-full-test >/dev/null
    for i in $(seq 1 10); do curl -sf http://docker:80/?health=1 >/dev/null && break; sleep 1; done
    TS_VIEWER_URL=http://docker:80/ TS_VIEWER_ERROR_URL=http://docker:18099/ node bin/e2e_playwright.mjs
    status=$?
    docker rm -f ts-viewer-full-mock ts-viewer-full-happy ts-viewer-full-error >/dev/null 2>&1 || true
    exit $status
  '
  docker rmi "$IMAGE" ts-viewer-full-test ts-viewer-mock >/dev/null 2>&1 || true
else
  docker rmi "$IMAGE" >/dev/null 2>&1 || true
fi
