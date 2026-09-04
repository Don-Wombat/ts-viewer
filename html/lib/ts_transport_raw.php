<?php
require_once __DIR__ . '/ts_transport.php';
require_once __DIR__ . '/ts_protocol.php';

// Klassisches Raw-TCP-ServerQuery (Telnet-artig, Standardport 10011). Nach dem
// Connect sendet der Server einen zweizeiligen Banner, danach MUSS der Client
// explizit "login <user> <pass>" senden, bevor er andere Kommandos schicken
// darf. Unverschlüsselt - siehe README fuer Sicherheitsempfehlungen.
class TsRawTransport implements TsQueryTransport {
    public function __construct(private array $config) {}

    public function query(string $commandBundle): string {
        $config = $this->config;

        $sock = @stream_socket_client(
            "tcp://{$config['host']}:{$config['port']}", $errno, $errstr, $config['connect_timeout']
        );
        if ($sock === false) {
            throw new TsTransportException("Verbindung fehlgeschlagen: $errstr ($errno)");
        }

        try {
            stream_set_timeout($sock, $config['connect_timeout']);
            $this->readLines($sock, 2); // Welcome-Banner (2 Zeilen)
            $this->login($sock, $config['user'], $config['pass']);

            stream_set_timeout($sock, 8); // das restliche Kommando-Bundle darf laenger dauern
            fwrite($sock, $commandBundle);
            return stream_get_contents($sock);
        } finally {
            fclose($sock);
        }
    }

    // Liest exakt $count Zeilen, timeout-bewacht statt eine feste Bytezahl
    // anzunehmen - Banner-Laenge kann zwischen TS-Versionen leicht variieren.
    private function readLines($sock, int $count): string {
        $buf = '';
        for ($i = 0; $i < $count; $i++) {
            $line = fgets($sock);
            if ($line === false) {
                $meta = stream_get_meta_data($sock);
                throw new TsTransportException($meta['timed_out'] ? 'Timeout beim Banner-Empfang' : 'Verbindung beim Banner-Empfang abgebrochen');
            }
            $buf .= $line;
        }
        return $buf;
    }

    private function login($sock, string $user, string $pass): void {
        // ServerQuery-Escaping (kein Shell-Escaping - es gibt keine Shell im Raw-Pfad).
        fwrite($sock, sprintf("login %s %s\n", ts_escape($user), ts_escape($pass)));

        // Die Login-Antwort ist immer genau eine Statuszeile ("error id=... msg=...").
        $line = fgets($sock);
        if ($line === false) {
            $meta = stream_get_meta_data($sock);
            throw new TsAuthException($meta['timed_out'] ? 'Timeout beim Login' : 'Verbindung beim Login abgebrochen');
        }
        if (strpos($line, 'error id=0 ') === false) {
            // Passwort darf niemals in eine Exception-Message oder ins Log wandern.
            throw new TsAuthException('ServerQuery-Login fehlgeschlagen');
        }
    }
}
