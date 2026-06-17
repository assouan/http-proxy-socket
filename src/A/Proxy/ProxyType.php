<?php

namespace A\Proxy;

enum ProxyType : string
{
    case HTTP = 'http';
    case HTTPS = 'https';
    case SOCKS4 = 'socks4';
    case SOCKS5 = 'socks5';
}
