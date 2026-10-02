# Pinned to a digest for reproducible builds (currently php:8.3-apache).
# Dependabot (.github/dependabot.yml) proposes PRs when this changes.
FROM php:8.5-apache@sha256:70d80539dcacae817d9a1320518b95c86bb9568835ef3a7a024d57a4898c90e4
RUN apt-get update && apt-get install -y sshpass openssh-client && rm -rf /var/lib/apt/lists/*
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
