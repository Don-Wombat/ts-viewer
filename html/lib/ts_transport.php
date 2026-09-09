<?php
// ─── Transport abstraction ──────────────────────────────────────────────────────
// A transport takes a newline-separated ServerQuery command bundle (WITHOUT
// "login" - auth happens, if needed, transport-internally) and returns the
// raw, unmodified ServerQuery response text. Everything downstream
// (ts_client.php, parser, rendering) stays identical for both transports.

interface TsQueryTransport {
    /** @throws TsTransportException on connect/auth/IO errors */
    public function query(string $commandBundle): string;
}

class TsTransportException extends RuntimeException {}
class TsAuthException extends TsTransportException {}

function ts_create_transport(array $config): TsQueryTransport {
    switch ($config['transport']) {
        case 'ssh':
            return new TsSshTransport($config);
        case 'raw':
            return new TsRawTransport($config);
        default:
            // TsTransportException instead of InvalidArgumentException, so the
            // existing catch(TsTransportException) in ts_client.php catches it
            // instead of a raw PHP error page being served.
            throw new TsTransportException("Unknown TS_TRANSPORT: {$config['transport']}");
    }
}
