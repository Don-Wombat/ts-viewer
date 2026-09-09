#!/bin/sh
set -e

# TS_CACHE_DIR can be overlaid by a volume or bind mount - a mount completely
# overrides the ownership set in the image. So make sure here, at container
# start (not just once at image build time), that the directory exists and
# is owned by www-data, regardless of what's mounted there.
CACHE_DIR="${TS_CACHE_DIR:-/var/cache/ts-viewer}"
mkdir -p "$CACHE_DIR"
chown www-data:www-data "$CACHE_DIR"
chmod 700 "$CACHE_DIR"

exec docker-php-entrypoint "$@"
