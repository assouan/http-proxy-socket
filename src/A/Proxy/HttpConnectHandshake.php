<?php

declare(strict_types=1);

namespace A\Proxy;

use A\Http\Request;
use A\Http\Response;
use Fiber;

trait HttpConnectHandshake
{
    protected string $proxy_buffer = '';

    protected function write_http_connect_request(string $host, int $port) : Response
    {
        $authority = Request::authority_for($host, $port);
        $headers = [
            'Host' => $authority,
            'Proxy-Connection' => 'keep-alive',
        ];

        if (($authorization = $this->proxy_authorization()) !== null)
        {
            $headers['Proxy-Authorization'] = $authorization;
        }

        $request = new Request('CONNECT', $authority, '1.1', $headers);
        $this->raw_write($request->to_packet());
        $response = $this->read_http_response(false);

        if (!$response->ok)
        {
            throw new \RuntimeException("HTTP proxy CONNECT failed: HTTP/{$response->version} {$response->status} {$response->reason}");
        }

        return $response;
    }

    protected function proxy_authorization() : ?string
    {
        if ($this->username === null)
        {
            return null;
        }

        return 'Basic ' . base64_encode($this->username . ':' . ($this->password ?? ''));
    }

    protected function read_http_response(bool $body_allowed = true) : Response
    {
        $packet = '';

        while (true)
        {
            $parsed = Response::try_parse_packet($packet, $body_allowed);

            if ($parsed !== null)
            {
                $this->proxy_buffer .= $parsed[1];

                return $parsed[0];
            }

            $chunk = $this->raw_read();

            if ($chunk !== '')
            {
                $packet .= $chunk;
                continue;
            }

            if (!$this->is_open)
            {
                throw new \RuntimeException('Proxy connection closed during handshake.');
            }

            $this->wait_read();
        }
    }

    protected function raw_read(int $length = 8192) : string
    {
        if ($length <= 0)
        {
            return '';
        }

        if ($this->proxy_buffer !== '')
        {
            $data = substr($this->proxy_buffer, 0, $length);
            $this->proxy_buffer = substr($this->proxy_buffer, strlen($data));

            return $data;
        }

        return parent::raw_read($length);
    }

    protected function emit_proxy_buffer() : void
    {
        if ($this->proxy_buffer === '')
        {
            return;
        }

        async(function () : void
        {
            Fiber::suspend();

            if ($this->is_open and $this->proxy_buffer !== '')
            {
                $this->tick();
            }
        });
    }
}
