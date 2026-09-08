# ts-viewer

[![CI](https://github.com/Don-Wombat/ts-viewer/actions/workflows/ci.yml/badge.svg)](https://github.com/Don-Wombat/ts-viewer/actions/workflows/ci.yml)

Schlanke, self-hostbare PHP-Webseite, die per ServerQuery live anzeigt, wer
gerade auf einem TeamSpeak-Server verbunden ist (Channel-Baum inkl. Topic,
Online-Clients mit Away-/Mute-Status und Rollen-Badge). Ein PHP-Prozess +
Docker, keine Node-Toolchain, kein Admin-Panel — bewusst nur eine read-only
Live-Anzeige. UI auf Deutsch und Englisch.

Unterstützt TeamSpeak 3 (ab Serverversion 3.3.0), TeamSpeak 5 und TeamSpeak 6.

## Setup

```bash
cp .env.example .env    # ausfüllen
docker compose -f docker-compose.example.yml up -d --build
```

Auf dem TS-Server wird ein dedizierter ServerQuery-Login empfohlen (nicht der
Admin-Account) — die App braucht nur Lesezugriff auf `serverinfo`,
`channellist`, `clientlist` und `servergrouplist` (für den Rollen-Badge, z.B.
"Server Admin").

Für Uptime-Monitoring: `?health=1` liefert `ok` (Text, HTTP 200) ohne
TS-Server-Roundtrip — nur ein Check, dass PHP/Apache laufen. Der
Docker-Container hat zusätzlich einen eingebauten `HEALTHCHECK` auf
demselben Endpoint.

### Transport wählen

| `TS_TRANSPORT` | Port (Default) | Verschlüsselt | Verfügbar ab |
|---|---|---|---|
| `ssh` (empfohlen) | 10022 | ja | TS3 ≥ 3.3.0, TS5, TS6 |
| `raw` | 10011 | **nein** | alle TS3/TS5-Versionen |

- **`ssh`**: Der TS-Server muss SSH-ServerQuery aktiviert haben
  (`query_protocols=raw,ssh` in der Server-Config). TS6 unterstützt
  (Stand jetzt) ausschließlich diesen Transport.
- **`raw`**: klassisches, unverschlüsseltes Telnet-artiges ServerQuery.
  Standardmäßig bei vielen älteren TS3/TS5-Installationen aktiv, überträgt
  Passwort und alle Serverdaten aber im Klartext — nur in einem
  vertrauenswürdigen/lokalen Netz verwenden, sonst `ssh` bevorzugen.

TS6-Support ist erwartet funktionsfähig, aber nicht extensiv gegen
verschiedene TS6-Serverversionen verifiziert.

## Konfiguration (Umgebungsvariablen)

| Variable | Pflicht | Default | Bedeutung |
|---|---|---|---|
| `TS_HOST` | ja | – | Hostname/IP des TS-Servers |
| `TS_USER` | ja | – | ServerQuery-Login-Name |
| `TS_PASS` | ja | – | ServerQuery-Passwort |
| `TS_TRANSPORT` | nein | `ssh` | `ssh` \| `raw` |
| `TS_PORT` | nein | `10022` (ssh) / `10011` (raw) | ServerQuery-Port |
| `TS_VPORT` | nein | `9987` | virtueller Server (Voice-Port) |
| `TS_QUERY_NICKNAME` | nein | `TS-Viewer` | Nickname, mit dem die Query-Verbindung im Client-Fenster sichtbar ist |
| `TS_CONNECT_TIMEOUT` | nein | `5` | Timeout in Sekunden für den Verbindungsaufbau |
| `TS_CACHE_DIR` | nein | `/var/cache/ts-viewer` | Verzeichnis für Cache/Lock/known_hosts |
| `TS_CACHE_TTL` | nein | `30` | Cache-Gültigkeit bei Erfolg (Sekunden) |
| `TS_CACHE_ERROR_TTL` | nein | `10` | Cache-Gültigkeit bei Fehlern (kürzer, verhindert Verbindungssturm) |
| `TS_TIMEZONE` | nein | `Europe/Berlin` | Zeitzone für die "Aktualisiert um"-Anzeige |
| `TS_BRAND_TITLE` | nein | `TeamSpeak Viewer` | `<title>` + Header-Text |
| `TS_BRAND_SUBTITLE` | nein | `TeamSpeak Server` | Untertitel im Header |
| `TS_CONNECT_URL` | nein | leer (Connect-Button ausgeblendet) | z.B. `ts3server://ts.example.org` |
| `TS_THEME_CSS_OVERRIDE` | nein | leer | roher CSS-Block, überschreibt die `:root`-Variablen aus `html/assets/style.css` |
| `TS_DEFAULT_LANG` | nein | `de` | Standardsprache (`de`\|`en`), siehe [Sprache](#sprache) |

Committet niemals echte Zugangsdaten in dieses Repo (z.B. in einer `.env`).

## Sprache

Die UI gibt es auf Deutsch und Englisch. Jeder Besucher kann über den
Umschalter im Header ("DE · EN") umschalten — die Wahl wird per Cookie
gemerkt. Ohne Auswahl gilt `TS_DEFAULT_LANG` (Default `de`). Neue UI-Strings
werden in `html/lib/i18n.php` gepflegt, siehe [CONTRIBUTING.md](CONTRIBUTING.md).

## Sicherheitshinweise

- Dedizierten, leseberechtigten ServerQuery-Login statt Admin-Account nutzen.
- `ssh`-Transport bevorzugen; `raw` überträgt Passwort und Serverdaten im
  Klartext.
- Beim `ssh`-Transport wird der Host-Key beim ersten Connect gepinnt
  (`StrictHostKeyChecking=accept-new`) und in `TS_CACHE_DIR/known_hosts`
  abgelegt — ändert sich der Key danach (z.B. durch einen MITM), schlägt die
  Verbindung fehl statt kommentarlos durchzulaufen.
- Cache, Lock-Datei und `known_hosts` liegen in einem dedizierten, nicht
  world-writable Verzeichnis (`0700`, `www-data`) statt in `/tmp`. Der
  Container-Entrypoint setzt diese Rechte bei jedem Start neu (nicht nur beim
  Image-Build), da ein Volume oder Bind-Mount an `TS_CACHE_DIR` die im Image
  gesetzten Rechte sonst überschreiben würde.
- HTTP-Security-Header (`X-Content-Type-Options: nosniff`,
  `Referrer-Policy: no-referrer`, `X-Frame-Options: DENY`,
  `Content-Security-Policy: frame-ancestors 'none'`) auf jeder Antwort,
  auch `?ajax=1`/`?health=1`.
- Alle Werte aus dem TS-Server (Channel-/Nicknamen, Topics, Gruppennamen)
  laufen vor der Ausgabe durch `htmlspecialchars()` — auch Rollen-Badges und
  der Channel-Topic (neu seit v0.1.6).

## Entwicklung

```bash
php -l html/index.php html/config.php html/lib/*.php bin/*.php   # Syntax-Check
php bin/selftest_parser.php                                        # Protokoll-Selbsttest
php bin/test_raw_transport.php                                     # Transport-Integrationstest gegen Mock-Socket
```

Siehe [CONTRIBUTING.md](CONTRIBUTING.md) für mehr Details (Konventionen,
i18n-Strings, Sicherheitsmeldungen).

## Changelog

Siehe [CHANGELOG.md](CHANGELOG.md).

## Lizenz

MIT, siehe [LICENSE](LICENSE).
