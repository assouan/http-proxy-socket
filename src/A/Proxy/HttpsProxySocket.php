<?php

declare(strict_types=1);

namespace A\Proxy;

use A\Network\TlsSocket;

class HttpsProxySocket extends TlsSocket
{
    use HttpConnectHandshake;

    protected(set) string $proxy_host;

    protected(set) int $proxy_port;

    protected(set) ?string $username;

    protected(set) ?string $password;

    public function __construct(
        string $proxy_host,
        int $proxy_port = 8080,
        ?string $username = null,
        ?string $password = null,
        array $options = [],
        ?\Socket $socket = null,
    ) {
        $this->proxy_host = $proxy_host;
        $this->proxy_port = $proxy_port;
        $this->username = $username;
        $this->password = $password;

        parent::__construct($options, $socket);
    }

    public static function create(
        string $host,
        int $port = 0,
        mixed $third = null,
        mixed $fourth = null,
        array $options = [],
    ) : static {
        return new static($host, $port ?: 8080, is_string($third) ? $third : null, is_string($fourth) ? $fourth : null, $options);
    }

    protected function connect_transport(string $host, int $port) : void
    {
        try
        {
            $this->connect_tcp_transport($this->proxy_host, $this->proxy_port);
            $this->stop_selecting();
            $this->write_http_connect_request($host, $port);
            $this->remote_host = $host;
            $this->remote_port = $port;

            $context = $this->ssl_context;
            $context['SNI_enabled'] ??= true;
            $context['peer_name'] ??= $host;
            $this->enable_encryption($context);
            $this->refresh_selector();
        }
        catch (\Throwable $error)
        {
            $this->close();

            throw $error;
        }
    }
}
