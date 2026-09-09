<?php
require_once __DIR__ . '/ts_transport.php';
require_once __DIR__ . '/ts_protocol.php';

// Classic raw-TCP ServerQuery (telnet-like, default port 10011). After
// connecting, the server sends a two-line banner, then the client MUST
// explicitly send "login <user> <pass>" before it can send any other
// commands. Unencrypted - see README for security recommendations.
class TsRawTransport implements TsQueryTransport {
    public function __construct(private array $config) {}

    public function query(string $commandBundle): string {
        $config = $this->config;

        // IPv6 literals need bracket syntax in the tcp:// URI (e.g.
        // tcp://[::1]:10011), otherwise the host gets parsed incorrectly.
        // IPv6 literals always contain ":", hostnames/IPv4 never do - a
        // simple, reliable distinction without extra validation.
        $host = str_contains($config['host'], ':') ? "[{$config['host']}]" : $config['host'];
        $sock = @stream_socket_client(
            "tcp://{$host}:{$config['port']}", $errno, $errstr, $config['connect_timeout']
        );
        if ($sock === false) {
            throw new TsTransportException("Connection failed: $errstr ($errno)");
        }

        try {
            stream_set_timeout($sock, $config['connect_timeout']);
            $this->readLines($sock, 2); // welcome banner (2 lines)
            $this->login($sock, $config['user'], $config['pass']);

            stream_set_timeout($sock, 8); // the rest of the command bundle is allowed to take longer
            fwrite($sock, $commandBundle);
            return stream_get_contents($sock);
        } finally {
            fclose($sock);
        }
    }

    // Reads exactly $count lines, timeout-guarded instead of assuming a fixed
    // byte count - banner length can vary slightly between TS versions.
    private function readLines($sock, int $count): string {
        $buf = '';
        for ($i = 0; $i < $count; $i++) {
            $line = fgets($sock);
            if ($line === false) {
                $meta = stream_get_meta_data($sock);
                throw new TsTransportException($meta['timed_out'] ? 'Timeout while receiving banner' : 'Connection aborted while receiving banner');
            }
            $buf .= $line;
        }
        return $buf;
    }

    private function login($sock, string $user, string $pass): void {
        // ServerQuery escaping (no shell escaping - there is no shell on the raw path).
        fwrite($sock, sprintf("login %s %s\n", ts_escape($user), ts_escape($pass)));

        // The login response is always exactly one status line ("error id=... msg=...").
        $line = fgets($sock);
        if ($line === false) {
            $meta = stream_get_meta_data($sock);
            throw new TsAuthException($meta['timed_out'] ? 'Timeout during login' : 'Connection aborted during login');
        }
        if (strpos($line, 'error id=0 ') === false) {
            // The password must never end up in an exception message or a log.
            throw new TsAuthException('ServerQuery login failed');
        }
    }
}
