<?php
// ─── Transport-Abstraktion ──────────────────────────────────────────────────────
// Ein Transport nimmt ein newline-getrenntes ServerQuery-Kommando-Bundle (OHNE
// "login" - Auth passiert, falls noetig, transport-intern) entgegen und gibt
// den rohen, unveraenderten ServerQuery-Response-Text zurueck. Alles, was
// danach kommt (ts_client.php, Parser, Rendering), bleibt fuer beide
// Transporte identisch.

interface TsQueryTransport {
    /** @throws TsTransportException bei Connect-/Auth-/IO-Fehlern */
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
            throw new InvalidArgumentException("Unbekannter TS_TRANSPORT: {$config['transport']}");
    }
}
