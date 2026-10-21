# Pinned to a digest for reproducible builds (currently php:8.3.36-apache - fixes CVE-2026-109618/-109619,
# RCE in mod_php before 8.3.36; keep >= 8.3.36).
# Dependabot (.github/dependabot.yml) proposes PRs when this changes.
FROM php:8.5.0-apache@sha256:1d825469e3c69b10baf6a8a63b1e766b81c6edb44a01f91509fc148bfd0e5f3e
RUN apt-get update && apt-get install -y sshpass openssh-client && rm -rf /var/lib/apt/lists/*
# Don't announce the PHP version in an X-Powered-By header.
RUN echo 'expose_php = Off' > /usr/local/etc/php/conf.d/zz-hide-version.ini
COPY html/ /var/www/html/
# Online-time leaderboard poller (see docker/entrypoint.sh) - not part of
# the web root, and not bin/ (dev/test-only, never copied into the image).
COPY cli/ /var/www/cli/

# Fixes the ownership of TS_CACHE_DIR at container start (not just at image
# build time) - a volume or bind mount at this path would otherwise override
# the ownership set in the image and the app would find a non-writable directory.
COPY docker/entrypoint.sh /usr/local/bin/ts-viewer-entrypoint.sh
RUN chmod +x /usr/local/bin/ts-viewer-entrypoint.sh
ENTRYPOINT ["/usr/local/bin/ts-viewer-entrypoint.sh"]
CMD ["apache2-foreground"]

# No curl needed: php -r uses the http stream wrapper that's already available.
HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
    CMD php -r "exit(@file_get_contents('http://127.0.0.1/?health=1') === 'ok' ? 0 : 1);"
