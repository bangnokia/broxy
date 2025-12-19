# Broxy - Distributed Browser-Based Proxy System

Broxy routes HTTP requests through real browser instances to make traffic appear organic and bypass anti-bot measures.

## Architecture

```
┌─────────────┐     HTTP      ┌──────────────┐    Channel    ┌────────────────┐
│   Client    │───────────────▶   Proxy      │◀─────────────▶│    Control     │
│  (curl/app) │    :8080      │   Server     │    (IPC)      │    Server      │
└─────────────┘               └──────────────┘               └───────┬────────┘
                                                                     │
                                                              WebSocket :9999
                                                                     │
                              ┌──────────────────────────────────────┼──────────────────────────────────┐
                              │                                      │                                  │
                        ┌─────▼─────┐                          ┌─────▼─────┐                      ┌─────▼─────┐
                        │  Browser  │                          │  Browser  │                      │  Browser  │
                        │   Bot 1   │                          │   Bot 2   │        ...           │   Bot N   │
                        └───────────┘                          └───────────┘                      └───────────┘
```

## Project Structure

```
broxy/
├── server/                 # PHP Workerman servers
│   ├── config/            # Configuration files
│   ├── src/
│   │   ├── Bot/           # Bot entity and pool management
│   │   ├── Request/       # Request queue management
│   │   └── Server/        # Workerman server implementations
│   ├── composer.json
│   └── start.php          # Main entry point
│
└── extension/             # Browser extension (Manifest V3)
    ├── manifest.json
    ├── background.js      # Service worker
    ├── popup.html         # Configuration UI
    └── popup.js
```

## Quick Start

### 1. Start the PHP Servers

```bash
cd server
composer install
php start.php start
```

### 2. Install the Browser Extension

1. Open Chrome and go to `chrome://extensions`
2. Enable "Developer mode"
3. Click "Load unpacked" and select the `extension/` folder
4. Click the extension icon and configure:
   - Server URL: `ws://localhost:9999`
   - API Key: `your-bot-api-key-change-me`
5. Click "Save Configuration" then "Connect"

### 3. Use the Proxy

```bash
# Test with curl
curl -x http://localhost:8080 https://httpbin.org/ip

# Or configure your application to use HTTP proxy at localhost:8080
```

## Configuration

### Server Configuration (`server/config/config.php`)

| Option | Default | Description |
|--------|---------|-------------|
| `proxy.port` | 8080 | HTTP proxy port |
| `control.port` | 9999 | WebSocket control server port |
| `channel.port` | 2206 | Internal IPC channel port |
| `bot.heartbeat_interval` | 25 | Heartbeat interval in seconds |
| `auth.bot_api_key` | - | API key for bot authentication |
| `auth.proxy_api_key` | - | API key for proxy authentication |

### Environment Variables

```bash
export BROXY_BOT_API_KEY="your-secure-bot-key"
export BROXY_PROXY_API_KEY="your-secure-proxy-key"
```

## Scaling

```php
// In config/config.php, adjust worker counts:
'proxy' => [
    'workers' => 8,  // cpu_cores * 2
],
'control' => [
    'workers' => 4,
],
```

## Proxy Authentication

Use HTTP Basic auth with the proxy API key as the password:

```bash
curl -x http://user:your-proxy-api-key@localhost:8080 https://example.com
```

## License

MIT
