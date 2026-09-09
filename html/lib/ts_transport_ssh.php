<?php
require_once __DIR__ . '/ts_transport.php';

// SSH ServerQuery (TS3 from 3.3.0, TS5, TS6 - default port 10022). Auth
// happens at the SSH layer itself, the command bundle starts directly with
// "use port=...".
class TsSshTransport implements TsQueryTransport {
    public function __construct(private array $config) {}

    public function query(string $commandBundle): string {
        $config = $this->config;

        // Pass the password via the SSHPASS environment variable instead of a
        // command-line argument ("sshpass -e" instead of "sshpass -p ..."):
        // otherwise it would be readable by any local process via `ps aux` /
        // /proc/<pid>/cmdline.
        putenv('SSHPASS=' . $config['pass']);
        // StrictHostKeyChecking=accept-new instead of "no": pins the host key
        // on first connect and fails afterwards if it changes (e.g. due to a
        // MITM), instead of silently accepting any host identity.
        $cmd = sprintf(
            'sshpass -e ssh -T -o StrictHostKeyChecking=accept-new -o UserKnownHostsFile=%s -o ConnectTimeout=%d -p %d %s@%s 2>&1',
            escapeshellarg($config['known_hosts_file']), $config['connect_timeout'], $config['port'],
            escapeshellarg($config['user']), escapeshellarg($config['host'])
        );

        $desc = [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']];
        $p = proc_open($cmd, $desc, $pipes);
        putenv('SSHPASS'); // remove it from our own process environment again immediately
        if (!is_resource($p)) {
            throw new TsTransportException('proc_open failed');
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
