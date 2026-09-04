<?php
require_once __DIR__ . '/ts_transport.php';

// SSH-ServerQuery (TS3 ab 3.3.0, TS5, TS6 - Standardport 10022). Auth passiert
// auf SSH-Ebene selbst, das Kommando-Bundle beginnt direkt mit "use port=...".
class TsSshTransport implements TsQueryTransport {
    public function __construct(private array $config) {}

    public function query(string $commandBundle): string {
        $config = $this->config;

        // Passwort über die Umgebungsvariable SSHPASS statt als Kommandozeilen-
        // argument übergeben ("sshpass -e" statt "sshpass -p ..."): sonst wäre
        // es für jeden lokalen Prozess über `ps aux` / /proc/<pid>/cmdline lesbar.
        putenv('SSHPASS=' . $config['pass']);
        // StrictHostKeyChecking=accept-new statt "no": pinnt den Host-Key beim
        // ersten Connect und schlägt danach fehl, falls er sich ändert (z.B.
        // durch MITM), statt jede Host-Identität kommentarlos zu akzeptieren.
        $cmd = sprintf(
            'sshpass -e ssh -T -o StrictHostKeyChecking=accept-new -o UserKnownHostsFile=%s -o ConnectTimeout=%d -p %d %s@%s 2>&1',
            escapeshellarg($config['known_hosts_file']), $config['connect_timeout'], $config['port'],
            escapeshellarg($config['user']), escapeshellarg($config['host'])
        );

        $desc = [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']];
        $p = proc_open($cmd, $desc, $pipes);
        putenv('SSHPASS'); // sofort wieder aus dem eigenen Prozess-Environment entfernen
        if (!is_resource($p)) {
            throw new TsTransportException('proc_open fehlgeschlagen');
        }

        fwrite($pipes[0], $commandBundle);
        fclose($pipes[0]);
        stream_set_timeout($pipes[1], 8);
        $out = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($p);

        return $out;
    }
}
