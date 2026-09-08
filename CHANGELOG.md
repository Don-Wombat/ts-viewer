# Changelog

Format angelehnt an [Keep a Changelog](https://keepachangelog.com/de/1.1.0/).

## [Unreleased]

## [v0.1.6.1] – Doku-Überarbeitung + Topic-Filter

### Behoben
- Triviale/Platzhalter-Channel-Topics (1 Zeichen, z.B. ein Überbleibsel-"1"
  aus Channel-Vorlagen/Kopiervorgängen) werden nicht mehr angezeigt. Gefunden
  live auf einem echten Server, auf dem ausnahmslos jeder Channel
  `channel_topic="1"` gesetzt hatte.

### Geändert
- README: CI-Badge, `servergrouplist`-Berechtigung, Security-Header und
  `?health=1`-Endpoint dokumentiert
- CONTRIBUTING.md: Projekt-Layout-Übersicht ergänzt
- GitHub-Release-Notes für v0.1.6 nachgetragen (vorher nur ein leeres,
  automatisch angelegtes Release)

## [v0.1.6] – Status-Icons, Topic, i18n, Docs, Review-Fixes

### Hinzugefügt
- `.dockerignore` (schließt `.env`, `.git`, `bin/deploy*` vom Docker-Build-Context aus)
- CI-Integrationstest (`bin/mock_serverquery.php` + `bin/test_raw_transport.php`)
  für den tatsächlichen Verbindungs-/Login-/Parsing-Pfad des Raw-Transports
- `restart: unless-stopped` in `docker-compose.example.yml`
- Security-Header (`X-Content-Type-Options`, `Referrer-Policy`,
  `X-Frame-Options`, `Content-Security-Policy: frame-ancestors`)
- `?health=1`-Endpoint ohne TS-Server-Roundtrip + Dockerfile-`HEALTHCHECK`
- Konfigurierbare Cache-TTL (`TS_CACHE_TTL`, `TS_CACHE_ERROR_TTL`)
- Channel-Topic-Anzeige
- Mute-Status-Icons (Mikrofon/Lautsprecher) und Server-Gruppen-Badge
  (z.B. "Server Admin") pro Client
- Sprachauswahl Deutsch/Englisch (`TS_DEFAULT_LANG`, `?lang=de|en`,
  Umschalter im Header)
- `CONTRIBUTING.md` und Issue-Templates
- Diese `CHANGELOG.md`

### Behoben
- `ts_create_transport()` warf bei unbekanntem `TS_TRANSPORT` eine
  `InvalidArgumentException`, die vom bestehenden `catch` nicht abgefangen
  wurde → rohe PHP-Fehlerseite statt Fehler-Box
- IPv6-Bug im Raw-Transport (`stream_socket_client` brauchte Klammer-Syntax
  für literale IPv6-Hosts)
- Verwaiste `pid`-Referenzen im Channel-Baum wurden stillschweigend nicht
  gerendert statt an die Wurzel gehängt zu werden
- Kritisch (High-Effort-Review): Upgrade-Pfad mit noch vorhandener
  Alt-Format-Cache-Datei (Fehler als String statt Key+Vars-Array) löste einen
  uncaught `TypeError` aus — echte Fatal-Error-Seite für Besucher für bis zu
  `error_ttl` Sekunden nach einem Deploy. `render.php` liest jetzt beide
  Formate.
- `TS_CACHE_TTL`/`TS_CACHE_ERROR_TTL` ohne Untergrenze: ein Tippfehler wie
  `TS_CACHE_TTL=abc` castete zu `0`, schaltete den Cache faktisch ab und
  erzeugte im Frontend Dauerpolling (`setInterval(fn, 0)`) gegen die eigene
  Seite. Jetzt mit `max(1, ...)` abgesichert.
- Ungültiger `ts_lang`-Cookie-Wert landete unvalidiert im `<html lang="...">`-
  Attribut, obwohl intern schon der Deutsch-Fallback griff.

Alle Punkte verifiziert: `php -l`, `bin/selftest_parser.php`,
`bin/test_raw_transport.php`, sowie ein echter `docker build` +
Container-Lauf gegen einen echten TeamSpeak-3-Server (DE und EN,
Health-Endpoint, konfigurierte Cache-TTL, Alt-Cache-Kompatibilität).

## [v0.1.5] – bin/deploy\* aus dem Repo ausgeschlossen
Rein organisatorisch: `bin/deploy.sh`/`bin/deploy.env(.example)` waren nur
für lokales Testen gedacht und enthielten reale Infrastruktur-Details
(IP, Port, Pfad) — jetzt per `.gitignore` komplett vom Repo ausgeschlossen.

## [v0.1.4] – Fix: `.env` wurde nie tatsächlich eingelesen
`docker-compose.example.yml` hatte feste Beispielwerte im
`environment:`-Block statt `env_file: .env` zu nutzen. Jede Änderung an
`.env` blieb dadurch wirkungslos (z.B. `TS_HOST ist nicht konfiguriert`
trotz gesetztem `TS_HOST`).

## [v0.1.3] – Fix: Cache-Verzeichnis-Rechte überleben jetzt Volume-/Bind-Mounts
`chown` im Dockerfile wirkt nur auf das Image — ein Volume oder Bind-Mount an
`TS_CACHE_DIR` überschreibt die dort gesetzten Rechte vollständig. Ein neuer
Container-Entrypoint (`docker/entrypoint.sh`) setzt die Rechte jetzt bei
jedem Container-Start neu.

## [v0.1.2] – Kritischer Fix: Dockerfile kopierte `html/` nie ins Image
Das `Dockerfile` installierte nur Pakete, ohne die App jemals per `COPY`
ins Image zu kopieren — jeder frische Build (auch v0.1.1) lieferte 403 statt
der eigentlichen Seite aus.

## [v0.1.1] – Initial Release
Erste öffentliche Version: Modulstruktur (`config.php`, `lib/ts_protocol.php`,
`lib/ts_transport*.php`, `lib/ts_client.php`, `lib/cache.php`, `lib/render.php`),
austauschbarer SSH- oder Raw-TCP-ServerQuery-Transport für TS3 (ab 3.3.0),
TS5 und TS6, vollständig über Umgebungsvariablen konfigurierbares Branding,
MIT-Lizenz.
