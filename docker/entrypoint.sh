#!/bin/sh
set -e

# TS_CACHE_DIR kann per Volume oder Bind-Mount ueberlagert sein - ein Mount
# ueberschreibt die im Image gesetzten Besitzrechte vollstaendig. Deshalb hier
# beim Container-Start (statt nur einmalig beim Image-Build) sicherstellen,
# dass das Verzeichnis existiert und www-data gehoert, egal was gemountet ist.
CACHE_DIR="${TS_CACHE_DIR:-/var/cache/ts-viewer}"
mkdir -p "$CACHE_DIR"
chown www-data:www-data "$CACHE_DIR"
chmod 700 "$CACHE_DIR"

exec docker-php-entrypoint "$@"
