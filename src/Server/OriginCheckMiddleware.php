<?php
/**
 * Grape - Validacao de Origin no handshake WebSocket (anti-CSWSH)
 *
 * Cross-Site WebSocket Hijacking: um site malicioso abre um WebSocket para o
 * nosso servidor e o navegador envia os cookies automaticamente. Como as salas
 * identificam o participante apenas pela sessao, um atacante poderia escutar
 * sinalizacoes de salas que a vitima tem abertas.
 *
 * Defesa: no handshake, exigir que o cabecalho Origin aponte para o mesmo host
 * do endpoint WebSocket (Host da requisicao) ou conste na allowlist
 * (websocket.allowed_origins). Clientes sem Origin (tools/scripts locais)
 * passam; navegadores SEMPRE enviam Origin.
 *
 * Colocar entre HttpServer e WsServer:
 *
 *     $ws  = new WsServer($signaling);
 *     $chk = new OriginCheckMiddleware($ws);
 *     $io  = new IoServer(new HttpServer($chk), $socket, $loop);
 */

namespace GamingCalls\Server;

use Ratchet\ConnectionInterface;
use Ratchet\Http\HttpServerInterface;
use Ratchet\Http\CloseResponseTrait;
use Psr\Http\Message\RequestInterface;

class OriginCheckMiddleware implements HttpServerInterface
{
    use CloseResponseTrait;

    private HttpServerInterface $next;
    private bool $enabled;
    private array $allowedOrigins;

    /**
     * @param HttpServerInterface $next           Componente decorado (normalmente WsServer)
     * @param bool                $enabled        websocket.check_origin
     * @param string[]            $allowedOrigins websocket.allowed_origins (origem completa, ex.: https://jogos.local)
     */
    public function __construct(HttpServerInterface $next, bool $enabled = true, array $allowedOrigins = [])
    {
        $this->next = $next;
        $this->enabled = $enabled;
        $this->allowedOrigins = array_values(array_filter(array_map('trim', $allowedOrigins)));
    }

    public function onOpen(ConnectionInterface $conn, RequestInterface $request = null): void
    {
        if (!$this->enabled || $request === null) {
            $this->next->onOpen($conn, $request);
            return;
        }

        $origin = trim((string) $request->getHeaderLine('Origin'));

        // Sem Origin: nao e um navegador; permite clientes locais/tests.
        // Navegadores sempre enviam Origin em handshakes WebSocket.
        if ($origin !== '' && !$this->isAllowed($origin, $request)) {
            error_log(sprintf(
                'WS handshake rejected: Origin "%s" not allowed (Host "%s", remote %s)',
                $origin,
                (string) $request->getHeaderLine('Host'),
                $conn->remoteAddress ?? 'unknown'
            ));
            $this->close($conn, 403, ['Connection' => 'close']);
            return;
        }

        $this->next->onOpen($conn, $request);
    }

    public function onMessage(ConnectionInterface $from, $msg): void
    {
        $this->next->onMessage($from, $msg);
    }

    public function onClose(ConnectionInterface $conn): void
    {
        $this->next->onClose($conn);
    }

    public function onError(ConnectionInterface $conn, \Exception $e): void
    {
        $this->next->onError($conn, $e);
    }

    /**
     * Regras, em ordem:
     *  1. match exato na allowlist;
     *  2. mesma origem: host do Origin == host do cabecalho Host.
     *
     * Compara-se apenas o hostname (ignora porta): em desenvolvimento a pagina
     * roda em http://host:8080 e o WebSocket em ws://host:8081. O hostname ainda
     * distingue atacante de endpoint valido, pois o navegador deriva ambos
     * (Origin e Host) da URL real da conexao.
     */
    private function isAllowed(string $origin, RequestInterface $request): bool
    {
        foreach ($this->allowedOrigins as $allowed) {
            if (strcasecmp($origin, $allowed) === 0 || strcasecmp(rtrim($origin, '/'), rtrim($allowed, '/')) === 0) {
                return true;
            }
        }

        $originHost = parse_url($origin, PHP_URL_HOST);
        if (!is_string($originHost) || $originHost === '') {
            return false;
        }

        $hostHeader = trim((string) $request->getHeaderLine('Host'));
        if ($hostHeader === '') {
            return false;
        }

        $hostHeaderHost = parse_url('//' . $hostHeader, PHP_URL_HOST);
        if (!is_string($hostHeaderHost) || $hostHeaderHost === '') {
            return false;
        }

        if (strcasecmp($originHost, $hostHeaderHost) === 0) {
            return true;
        }

        // localhost, 127.0.0.1 e ::1 sao a mesma maquina: a pagina pode estar em
        // http://localhost:8080 e o WS em ws://127.0.0.1:8081 (dev local).
        $loopback = ['localhost', '127.0.0.1', '::1', '[::1]'];
        return in_array(strtolower($originHost), $loopback, true)
            && in_array(strtolower($hostHeaderHost), $loopback, true);
    }
}
