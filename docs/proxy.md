# A\Proxy HTTP API contract

HTTP proxy sockets connect to the final target through an upstream proxy.

## HttpProxySocket

`HttpProxySocket` opens a TCP HTTP CONNECT tunnel.

## HttpsProxySocket

`HttpsProxySocket` opens HTTP CONNECT, then enables TLS toward the target.

## ProxyConfig

`ProxyConfig` stores a proxy URI as typed data and can expose curl options.

## HttpProxyBridge

`HttpProxyBridge` starts a local HTTP CONNECT bridge for clients that need a
local proxy endpoint.
