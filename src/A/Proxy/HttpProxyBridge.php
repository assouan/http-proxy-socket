<?php

declare(strict_types=1);

namespace A\Proxy;

final class HttpProxyBridge implements \Stringable
{
    protected(set) string $host = '127.0.0.1';

    protected(set) int $port;

    protected mixed $process = null;

    protected bool $stopped = false;

    protected function __construct(
        protected(set) string $root,
        protected(set) string $config_path,
        protected(set) string $ready_path,
        protected(set) string $stop_path,
        protected(set) string $log_path,
        int $port,
        protected(set) bool $preserve_runtime_directory = false,
    )
    {
        $this->port = $port;
    }

    public static function start(
        ProxyConfig|string $proxy,
        ?string $runtime_root = null,
        float $timeout_seconds = 5.0,
        bool $preserve_runtime_directory = false,
    ) : static
    {
        $proxy = is_string($proxy) ? ProxyConfig::create_from_uri($proxy) : $proxy;
        $root = static::runtime_root($runtime_root);
        $config_path = $root . DIRECTORY_SEPARATOR . 'config.json';
        $ready_path = $root . DIRECTORY_SEPARATOR . 'ready';
        $stop_path = $root . DIRECTORY_SEPARATOR . 'stop';
        $log_path = $root . DIRECTORY_SEPARATOR . 'bridge.log';
        $port = static::free_tcp_port();
        $config = [
            'listen_host' => '127.0.0.1',
            'listen_port' => $port,
            'ready_path' => $ready_path,
            'stop_path' => $stop_path,
            'deadline' => time() + 600,
            'preserve_runtime' => $preserve_runtime_directory,
            'proxy' => (string)$proxy,
        ];

        file_put_contents($config_path, json_encode($config, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), LOCK_EX);

        $bridge = new static($root, $config_path, $ready_path, $stop_path, $log_path, $port, $preserve_runtime_directory);

        try
        {
            $bridge->launch($timeout_seconds);
        }
        catch (\Throwable $error)
        {
            $log = is_file($log_path) ? trim((string)file_get_contents($log_path)) : '';
            $bridge->stop();

            if ($log !== '')
            {
                throw new \RuntimeException($error->getMessage() . ' Child output: ' . $log, 0, $error);
            }

            throw $error;
        }

        return $bridge;
    }

    public function proxy_server() : string
    {
        return "http://{$this->host}:{$this->port}";
    }

    public function __toString() : string
    {
        return $this->proxy_server();
    }

    public function stop() : void
    {
        if ($this->stopped)
        {
            return;
        }

        $this->stopped = true;

        if ($this->preserve_runtime_directory)
        {
            @file_put_contents($this->stop_path, 'stop', LOCK_EX);
        }
        else
        {
            @unlink($this->config_path);
        }

        if (is_resource($this->process))
        {
            @proc_terminate($this->process);
            @proc_close($this->process);
        }

        $this->process = null;

        if (!$this->preserve_runtime_directory)
        {
            $this->remove_directory($this->root);
        }
    }

    public function __destruct()
    {
        $this->stop();
    }

    public static function run_child(string $config_path) : void
    {
        $config = json_decode((string)file_get_contents($config_path), true, flags: JSON_THROW_ON_ERROR);

        if (!is_array($config) || !is_string($config['proxy'] ?? null))
        {
            throw new \RuntimeException('Invalid local HTTP proxy bridge config.');
        }

        $listen_host = (string)($config['listen_host'] ?? '127.0.0.1');
        $listen_port = (int)($config['listen_port'] ?? 0);
        $ready_path = (string)($config['ready_path'] ?? '');
        $stop_path = (string)($config['stop_path'] ?? '');
        $deadline = (int)($config['deadline'] ?? (time() + 600));
        $preserve_runtime = (bool)($config['preserve_runtime'] ?? false);
        $proxy = ProxyConfig::create_from_uri($config['proxy']);
        $server = @stream_socket_server("tcp://{$listen_host}:{$listen_port}", $error_code, $error);

        if (!is_resource($server))
        {
            throw new \RuntimeException($error !== '' ? $error : 'Unable to start local HTTP proxy bridge.', $error_code);
        }

        stream_set_blocking($server, false);

        if ($ready_path !== '')
        {
            file_put_contents($ready_path, 'ready', LOCK_EX);
        }

        try
        {
            static::serve($server, $proxy, $config_path, $stop_path, $deadline);
        }
        finally
        {
            fclose($server);

            if (!$preserve_runtime)
            {
                @unlink($config_path);
                @unlink($ready_path);
                @unlink($stop_path);
            }
        }
    }

    protected function launch(float $timeout_seconds) : void
    {
        $autoload = static::project_root() . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';

        if (!is_file($autoload))
        {
            throw new \RuntimeException('Composer autoload file is missing. Run composer dump-autoload.');
        }

        $code = 'require ' . var_export($autoload, true) . '; '
            . static::class . '::run_child(' . var_export($this->config_path, true) . ');';
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['file', $this->log_path, 'a'],
            2 => ['file', $this->log_path, 'a'],
        ];

        $this->process = proc_open([PHP_BINARY, '-r', $code], $descriptors, $pipes, null, null, [
            'bypass_shell' => true,
        ]);

        if (!is_resource($this->process))
        {
            throw new \RuntimeException('Unable to start local HTTP proxy bridge.');
        }

        foreach ($pipes as $pipe)
        {
            if (is_resource($pipe))
            {
                fclose($pipe);
            }
        }

        $deadline = hrtime(true) + (int)floor($timeout_seconds * 1_000_000_000);

        while (hrtime(true) < $deadline)
        {
            if (is_file($this->ready_path))
            {
                return;
            }

            $status = proc_get_status($this->process);

            if (($status['running'] ?? false) === false)
            {
                throw new \RuntimeException("Local HTTP proxy bridge exited before becoming ready. See {$this->log_path}");
            }

            asleep(0.05);
        }

        throw new \RuntimeException("Timed out while starting local HTTP proxy bridge. See {$this->log_path}");
    }

    protected static function serve(mixed $server, ProxyConfig $proxy, string $config_path, string $stop_path, int $deadline) : void
    {
        $connections = [];

        while (is_file($config_path) && ($stop_path === '' || !is_file($stop_path)) && time() < $deadline)
        {
            $read = [$server];
            $write = [];

            foreach ($connections as $connection)
            {
                if (is_resource($connection['client']))
                {
                    $read[] = $connection['client'];

                    if ($connection['to_client'] !== '')
                    {
                        $write[] = $connection['client'];
                    }
                }

                if (is_resource($connection['upstream'] ?? null))
                {
                    $read[] = $connection['upstream'];

                    if ($connection['to_upstream'] !== '')
                    {
                        $write[] = $connection['upstream'];
                    }
                }
            }

            $except = [];
            $ready = @stream_select($read, $write, $except, 0, 200_000);

            if ($ready === false)
            {
                continue;
            }

            if (in_array($server, $read, true))
            {
                while (is_resource($client = @stream_socket_accept($server, 0)))
                {
                    stream_set_blocking($client, false);
                    $connections[(int)get_resource_id($client)] = [
                        'client' => $client,
                        'headers' => '',
                        'upstream' => null,
                        'to_client' => '',
                        'to_upstream' => '',
                    ];
                }
            }

            foreach (array_keys($connections) as $id)
            {
                if (isset($connections[$id]))
                {
                    static::pump_connection($connections, $id, $proxy, $read, $write);
                }
            }
        }

        foreach (array_keys($connections) as $id)
        {
            static::close_connection($connections, $id);
        }
    }

    protected static function pump_connection(array &$connections, int $id, ProxyConfig $proxy, array $read, array $write) : void
    {
        $client = $connections[$id]['client'];
        $upstream = $connections[$id]['upstream'];

        if (is_resource($client) && in_array($client, $read, true))
        {
            $chunk = @fread($client, 16384);

            if ($chunk === false || ($chunk === '' && feof($client)))
            {
                static::close_connection($connections, $id);
                return;
            }

            if ($chunk !== '')
            {
                if (!is_resource($upstream))
                {
                    $connections[$id]['headers'] .= $chunk;

                    if (strlen($connections[$id]['headers']) > 65536)
                    {
                        @fwrite($client, "HTTP/1.1 431 Request Header Fields Too Large\r\nConnection: close\r\nContent-Length: 0\r\n\r\n");
                        static::close_connection($connections, $id);
                        return;
                    }

                    static::maybe_open_tunnel($connections, $id, $proxy);
                }
                else
                {
                    $connections[$id]['to_upstream'] .= $chunk;
                }
            }
        }

        if (!isset($connections[$id]))
        {
            return;
        }

        $client = $connections[$id]['client'];
        $upstream = $connections[$id]['upstream'];

        if (is_resource($upstream) && in_array($upstream, $read, true))
        {
            $chunk = @fread($upstream, 16384);

            if ($chunk === false || ($chunk === '' && feof($upstream)))
            {
                static::close_connection($connections, $id);
                return;
            }

            if ($chunk !== '')
            {
                $connections[$id]['to_client'] .= $chunk;
            }
        }

        if (!isset($connections[$id]))
        {
            return;
        }

        static::flush_buffer($connections, $id, 'to_client', $client, $write);

        if (isset($connections[$id]))
        {
            static::flush_buffer($connections, $id, 'to_upstream', $upstream, $write);
        }
    }

    protected static function maybe_open_tunnel(array &$connections, int $id, ProxyConfig $proxy) : void
    {
        $headers = (string)$connections[$id]['headers'];
        $end = strpos($headers, "\r\n\r\n");

        if ($end === false)
        {
            return;
        }

        $head = substr($headers, 0, $end + 4);
        $remaining = substr($headers, $end + 4);
        $request_line = strtok($head, "\r\n") ?: '';
        $client = $connections[$id]['client'];

        if (!preg_match('~^CONNECT\s+(\[[^\]]+\]|[^:\s]+):(\d+)\s+HTTP/\d+(?:\.\d+)?$~i', $request_line, $match))
        {
            @fwrite($client, "HTTP/1.1 405 Method Not Allowed\r\nConnection: close\r\nContent-Length: 0\r\n\r\n");
            static::close_connection($connections, $id);
            return;
        }

        $host = trim((string)$match[1], '[]');
        $port = (int)$match[2];

        try
        {
            $upstream = static::open_upstream($proxy, $host, $port);
            stream_set_blocking($upstream, false);
        }
        catch (\Throwable)
        {
            @fwrite($client, "HTTP/1.1 502 Bad Gateway\r\nConnection: close\r\nContent-Length: 0\r\n\r\n");
            static::close_connection($connections, $id);
            return;
        }

        @fwrite($client, "HTTP/1.1 200 Connection Established\r\nProxy-Agent: bofus-auth\r\n\r\n");
        $connections[$id]['headers'] = '';
        $connections[$id]['upstream'] = $upstream;

        if ($remaining !== '')
        {
            $connections[$id]['to_upstream'] .= $remaining;
        }
    }

    protected static function open_upstream(ProxyConfig $proxy, string $host, int $port) : mixed
    {
        return match ($proxy->type)
        {
            ProxyType::HTTP, ProxyType::HTTPS => static::open_http_tunnel($proxy, $host, $port),
            ProxyType::SOCKS4 => static::open_socks4_tunnel($proxy, $host, $port),
            ProxyType::SOCKS5 => static::open_socks5_tunnel($proxy, $host, $port),
        };
    }

    protected static function open_http_tunnel(ProxyConfig $proxy, string $host, int $port) : mixed
    {
        $transport = $proxy->type === ProxyType::HTTPS ? 'tls' : 'tcp';
        $proxy_host = static::stream_host($proxy->host);
        $stream = @stream_socket_client("{$transport}://{$proxy_host}:{$proxy->port}", $error_code, $error, 30.0);

        if (!is_resource($stream))
        {
            throw new \RuntimeException($error !== '' ? $error : "Unable to connect to proxy {$proxy->host}:{$proxy->port}", $error_code);
        }

        stream_set_timeout($stream, 30);
        stream_set_blocking($stream, true);

        $authority = static::authority($host, $port);
        $headers = [
            "CONNECT {$authority} HTTP/1.1",
            "Host: {$authority}",
            'Proxy-Connection: Keep-Alive',
            'Connection: Keep-Alive',
        ];

        if ($proxy->username !== '' || $proxy->password !== '')
        {
            $headers[] = 'Proxy-Authorization: Basic ' . base64_encode($proxy->username . ':' . $proxy->password);
        }

        static::write_all($stream, implode("\r\n", $headers) . "\r\n\r\n");
        $response = static::read_until($stream, "\r\n\r\n", 30.0);
        $status_line = strtok($response, "\r\n") ?: '';

        if (!preg_match('/^HTTP\/\d(?:\.\d)?\s+2\d\d\b/i', $status_line))
        {
            fclose($stream);
            throw new \RuntimeException('Proxy CONNECT failed: ' . ($status_line !== '' ? $status_line : 'empty response'));
        }

        return $stream;
    }

    protected static function open_socks5_tunnel(ProxyConfig $proxy, string $host, int $port) : mixed
    {
        $stream = static::open_tcp_proxy_stream($proxy);
        $authenticated = $proxy->username !== '' || $proxy->password !== '';

        static::write_all($stream, $authenticated ? "\x05\x02\x00\x02" : "\x05\x01\x00");
        $method = static::read_exactly($stream, 2, 30.0);

        if ($method[0] !== "\x05" || $method[1] === "\xff")
        {
            fclose($stream);
            throw new \RuntimeException('SOCKS5 proxy rejected all authentication methods.');
        }

        if ($method[1] === "\x02")
        {
            if (strlen($proxy->username) > 255 || strlen($proxy->password) > 255)
            {
                fclose($stream);
                throw new \RuntimeException('SOCKS5 credentials are too long.');
            }

            static::write_all($stream, "\x01" . chr(strlen($proxy->username)) . $proxy->username . chr(strlen($proxy->password)) . $proxy->password);
            $result = static::read_exactly($stream, 2, 30.0);

            if ($result !== "\x01\x00")
            {
                fclose($stream);
                throw new \RuntimeException('SOCKS5 authentication failed.');
            }
        }
        else if ($method[1] !== "\x00")
        {
            fclose($stream);
            throw new \RuntimeException('SOCKS5 proxy selected an unsupported authentication method.');
        }

        static::write_all($stream, "\x05\x01\x00" . static::socks5_address($host) . pack('n', $port));
        $header = static::read_exactly($stream, 4, 30.0);

        if ($header[0] !== "\x05" || $header[1] !== "\x00")
        {
            fclose($stream);
            throw new \RuntimeException('SOCKS5 proxy CONNECT failed.');
        }

        $address_length = match ($header[3])
        {
            "\x01" => 4,
            "\x04" => 16,
            "\x03" => ord(static::read_exactly($stream, 1, 30.0)),
            default => throw new \RuntimeException('SOCKS5 proxy returned an invalid address type.'),
        };

        static::read_exactly($stream, $address_length + 2, 30.0);

        return $stream;
    }

    protected static function open_socks4_tunnel(ProxyConfig $proxy, string $host, int $port) : mixed
    {
        $stream = static::open_tcp_proxy_stream($proxy);
        $ipv4 = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);
        $user = $proxy->username;
        $request = "\x04\x01" . pack('n', $port);

        if ($ipv4)
        {
            $request .= inet_pton($host) . $user . "\x00";
        }
        else
        {
            $request .= "\x00\x00\x00\x01" . $user . "\x00" . $host . "\x00";
        }

        static::write_all($stream, $request);
        $response = static::read_exactly($stream, 8, 30.0);

        if ($response[1] !== "\x5a")
        {
            fclose($stream);
            throw new \RuntimeException('SOCKS4 proxy CONNECT failed.');
        }

        return $stream;
    }

    protected static function open_tcp_proxy_stream(ProxyConfig $proxy) : mixed
    {
        $proxy_host = static::stream_host($proxy->host);
        $stream = @stream_socket_client("tcp://{$proxy_host}:{$proxy->port}", $error_code, $error, 30.0);

        if (!is_resource($stream))
        {
            throw new \RuntimeException($error !== '' ? $error : "Unable to connect to proxy {$proxy->host}:{$proxy->port}", $error_code);
        }

        stream_set_timeout($stream, 30);
        stream_set_blocking($stream, true);

        return $stream;
    }

    protected static function socks5_address(string $host) : string
    {
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4))
        {
            return "\x01" . inet_pton($host);
        }

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6))
        {
            return "\x04" . inet_pton($host);
        }

        if (strlen($host) > 255)
        {
            throw new \RuntimeException('SOCKS5 host name is too long.');
        }

        return "\x03" . chr(strlen($host)) . $host;
    }

    protected static function flush_buffer(array &$connections, int $id, string $key, mixed $stream, array $write) : void
    {
        if (!isset($connections[$id]) || !is_resource($stream) || !in_array($stream, $write, true))
        {
            return;
        }

        $buffer = (string)$connections[$id][$key];

        if ($buffer === '')
        {
            return;
        }

        $written = @fwrite($stream, $buffer);

        if ($written === false || ($written === 0 && feof($stream)))
        {
            static::close_connection($connections, $id);
            return;
        }

        if ($written > 0)
        {
            $connections[$id][$key] = substr($buffer, $written);
        }
    }

    protected static function close_connection(array &$connections, int $id) : void
    {
        if (!isset($connections[$id]))
        {
            return;
        }

        foreach (['client', 'upstream'] as $key)
        {
            if (is_resource($connections[$id][$key] ?? null))
            {
                @fclose($connections[$id][$key]);
            }
        }

        unset($connections[$id]);
    }

    protected static function write_all(mixed $stream, string $data) : void
    {
        $offset = 0;
        $length = strlen($data);

        while ($offset < $length)
        {
            $written = @fwrite($stream, substr($data, $offset));

            if ($written === false)
            {
                throw new \RuntimeException('Unable to write to proxy stream.');
            }

            if ($written === 0)
            {
                if (feof($stream))
                {
                    throw new \RuntimeException('Proxy stream closed while writing.');
                }

                continue;
            }

            $offset += $written;
        }
    }

    protected static function read_until(mixed $stream, string $delimiter, float $timeout_seconds) : string
    {
        $buffer = '';

        while (!str_contains($buffer, $delimiter))
        {
            $buffer .= static::read_exactly($stream, 1, $timeout_seconds);
        }

        return $buffer;
    }

    protected static function read_exactly(mixed $stream, int $length, float $timeout_seconds) : string
    {
        $buffer = '';
        $deadline = hrtime(true) + (int)floor($timeout_seconds * 1_000_000_000);

        while (strlen($buffer) < $length)
        {
            if (hrtime(true) >= $deadline)
            {
                throw new \RuntimeException('Timed out while reading from proxy stream.');
            }

            $chunk = @fread($stream, $length - strlen($buffer));

            if ($chunk === false)
            {
                throw new \RuntimeException('Unable to read from proxy stream.');
            }

            if ($chunk === '')
            {
                if (feof($stream))
                {
                    throw new \RuntimeException('Proxy stream closed while reading.');
                }

                asleep(0.001);
                continue;
            }

            $buffer .= $chunk;
        }

        return $buffer;
    }

    protected static function authority(string $host, int $port) : string
    {
        return str_contains($host, ':') && !str_starts_with($host, '[') ? "[{$host}]:{$port}" : "{$host}:{$port}";
    }

    protected static function stream_host(string $host) : string
    {
        return str_contains($host, ':') && !str_starts_with($host, '[') ? "[{$host}]" : $host;
    }

    protected static function runtime_root(?string $runtime_root) : string
    {
        $root = $runtime_root === null
            ? static::project_root() . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'temp' . DIRECTORY_SEPARATOR . 'chrome-bridge-' . bin2hex(random_bytes(8))
            : rtrim($runtime_root, "\\/");

        if (!is_dir($root) && !mkdir($root, 0777, true) && !is_dir($root))
        {
            throw new \RuntimeException("Unable to create temporary directory: {$root}");
        }

        return $root;
    }

    protected static function free_tcp_port() : int
    {
        $server = @stream_socket_server('tcp://127.0.0.1:0', $error_code, $error);

        if (!is_resource($server))
        {
            throw new \RuntimeException($error !== '' ? $error : 'Unable to allocate a local bridge port.', $error_code);
        }

        $name = stream_socket_get_name($server, false);
        fclose($server);

        if (!is_string($name) || !str_contains($name, ':'))
        {
            throw new \RuntimeException('Unable to detect allocated bridge port.');
        }

        return (int)substr(strrchr($name, ':'), 1);
    }

    protected function remove_directory(string $directory) : void
    {
        if (!is_dir($directory))
        {
            return;
        }

        $root = realpath($directory);
        $temp = realpath(static::project_root() . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'temp');

        if (
            $root === false
            || $temp === false
            || !static::same_path(dirname($root), $temp)
            || !str_starts_with(basename($root), 'chrome-bridge-')
        ) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item)
        {
            if ($item->isLink() || $item->isFile())
            {
                @unlink($item->getPathname());
            }
            else if ($item->isDir())
            {
                @rmdir($item->getPathname());
            }
        }

        @rmdir($root);
    }

    protected static function same_path(string $left, string $right) : bool
    {
        $normalize = static fn (string $path) : string => rtrim(str_replace('\\', '/', strtolower($path)), '/');

        return $normalize($left) === $normalize($right);
    }

    protected static function project_root() : string
    {
        return dirname(__DIR__, 3);
    }
}
