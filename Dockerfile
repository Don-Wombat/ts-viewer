FROM php:8.3-apache
RUN apt-get update && apt-get install -y sshpass openssh-client && rm -rf /var/lib/apt/lists/*
# Eigenes, nicht world-writable Verzeichnis für Cache/Lock/known_hosts (statt /tmp).
RUN mkdir -p /var/cache/ts-viewer && chown www-data:www-data /var/cache/ts-viewer && chmod 700 /var/cache/ts-viewer
COPY html/ /var/www/html/
