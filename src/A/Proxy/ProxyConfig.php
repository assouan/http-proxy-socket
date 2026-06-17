<?php

declare(strict_types=1);

namespace A\Proxy;

class ProxyConfig implements \Stringable
{
    public function __construct(
        protected(set) ProxyType $type,
        protected(set) string $host,
        protected(set) int $port,
        protected(set) string $username = '',
        protected(set) string $password = '',
        protected(set) array $options = [],
    ) {
    }

    public function to_curlopt() : array
    {
        $options = $this->options + [
            CURLOPT_PROXY => $this->host,
            CURLOPT_PROXYPORT => $this->port,
            CURLOPT_PROXYTYPE => match ($this->type)
            {
                ProxyType::HTTP => CURLPROXY_HTTP,
                ProxyType::HTTPS => CURLPROXY_HTTPS,
                ProxyType::SOCKS4 => CURLPROXY_SOCKS4,
                ProxyType::SOCKS5 => CURLPROXY_SOCKS5,
            },
        ];

        if ($this->username !== '' or $this->password !== '')
        {
            $options[CURLOPT_PROXYUSERPWD] = "{$this->username}:{$this->password}";
        }

        return $options;
    }

    public static function create_from_uri(string $uri) : static
    {
        $parts = parse_url($uri);

        if ($parts === false or !isset($parts['scheme'], $parts['host']))
        {
            throw new \InvalidArgumentException("Invalid proxy URI: {$uri}");
        }

        $type = ProxyType::tryFrom(strtolower($parts['scheme']));

        if ($type === null)
        {
            throw new \InvalidArgumentException("Unsupported proxy type: {$parts['scheme']}");
        }

        return new static(
            $type,
            $parts['host'],
            isset($parts['port']) ? (int)$parts['port'] : static::default_port($type),
            isset($parts['user']) ? rawurldecode($parts['user']) : '',
            isset($parts['pass']) ? rawurldecode($parts['pass']) : '',
        );
    }

    public function __toString() : string
    {
        $auth = '';

        if ($this->username !== '' or $this->password !== '')
        {
            $auth = rawurlencode($this->username);

            if ($this->password !== '')
            {
                $auth .= ':' . rawurlencode($this->password);
            }

            $auth .= '@';
        }

        return "{$this->type->value}://{$auth}{$this->host_for_uri()}:{$this->port}";
    }

    protected static function default_port(ProxyType $type) : int
    {
        return match ($type)
        {
            ProxyType::HTTP => 80,
            ProxyType::HTTPS => 443,
            ProxyType::SOCKS4 => 1080,
            ProxyType::SOCKS5 => 1080,
        };
    }

    protected function host_for_uri() : string
    {
        if (str_contains($this->host, ':') and !str_starts_with($this->host, '['))
        {
            return "[{$this->host}]";
        }

        return $this->host;
    }
}
