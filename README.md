# ts-viewer

Schlanke, self-hostbare PHP-Webseite, die per ServerQuery live anzeigt, wer
gerade auf einem TeamSpeak-Server verbunden ist (Channel-Baum, Online-Clients,
Away-Status). Ein PHP-Prozess + Docker, keine Node-Toolchain, kein
Admin-Panel — bewusst nur eine read-only Live-Anzeige.

Unterstützt TeamSpeak 3 (ab Serverversion 3.3.0), TeamSpeak 5 und TeamSpeak 6.

## Setup

```bash
cp .env.example .env    # ausfüllen
docker compose -f docker-compose.example.yml up -d --build
```

Auf dem TS-Server wird ein dedizierter ServerQuery-Login empfohlen (nicht der
Admin-Account) — die App braucht nur Lesezugriff auf `serverinfo`,
`channellist` und `clientlist`.

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
| `TS_TIMEZONE` | nein | `Europe/Berlin` | Zeitzone für die "Aktualisiert um"-Anzeige |
| `TS_BRAND_TITLE` | nein | `TeamSpeak Viewer` | `<title>` + Header-Text |
| `TS_BRAND_SUBTITLE` | nein | `TeamSpeak Server` | Untertitel im Header |
| `TS_CONNECT_URL` | nein | leer (Connect-Button ausgeblendet) | z.B. `ts3server://ts.example.org` |
| `TS_THEME_CSS_OVERRIDE` | nein | leer | roher CSS-Block, überschreibt die `:root`-Variablen aus `html/assets/style.css` |

Committet niemals echte Zugangsdaten in dieses Repo (z.B. in einer `.env`).

## Sicherheitshinweise

- Dedizierten, leseberechtigten ServerQuery-Login statt Admin-Account nutzen.
- `ssh`-Transport bevorzugen; `raw` überträgt Passwort und Serverdaten im
  Klartext.
- Beim `ssh`-Transport wird der Host-Key beim ersten Connect gepinnt
  (`StrictHostKeyChecking=accept-new`) und in `TS_CACHE_DIR/known_hosts`
  abgelegt — ändert sich der Key danach (z.B. durch einen MITM), schlägt die
  Verbindung fehl statt kommentarlos durchzulaufen.
- Cache, Lock-Datei und `known_hosts` liegen in einem dedizierten, nicht
  world-writable Verzeichnis (`0700`, `www-data`) statt in `/tmp`.

## Entwicklung

```bash
php -l html/index.php html/config.php html/lib/*.php   # Syntax-Check
php bin/selftest_parser.php                              # Protokoll-Selbsttest
```

## Lizenz

MIT, siehe [LICENSE](LICENSE).
