FROM php:8.3-apache
RUN apt-get update && apt-get install -y sshpass openssh-client && rm -rf /var/lib/apt/lists/*
COPY html/ /var/www/html/

# Setzt beim Container-Start (nicht nur beim Image-Build) die Besitzrechte auf
# TS_CACHE_DIR - ein Volume oder Bind-Mount an diesem Pfad ueberschreibt sonst
# die im Image gesetzten Rechte und die App findet ein nicht beschreibbares
# Verzeichnis vor.
COPY docker/entrypoint.sh /usr/local/bin/ts-viewer-entrypoint.sh
RUN chmod +x /usr/local/bin/ts-viewer-entrypoint.sh
ENTRYPOINT ["/usr/local/bin/ts-viewer-entrypoint.sh"]
CMD ["apache2-foreground"]
