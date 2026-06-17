# Broxy - Distributed Browser-Based URL Render API

Broxy accepts URL render requests over HTTP and routes them through real browser instances to make traffic appear organic and bypass anti-bot measures.

## Architecture

```
┌─────────────┐     HTTP      ┌──────────────┐    Channel    ┌────────────────┐
│   Client    │───────────────▶    API       │◀─────────────▶│    Control     │
│  (curl/app) │    :8080/api  │   Server     │    (IPC)      │    Server      │
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
5. Click "Save Configuration" then "Connect"

### 3. Use the API

```bash
# GET
curl "http://localhost:8080/api?url=https://example.com"

# POST JSON
curl -X POST http://localhost:8080/api \
  -H "Content-Type: application/json" \
  -d '{"url":"https://example.com"}'
```

The API accepts both `http://` and `https://` URLs. The connected browser extension opens the URL in a browser tab and returns the captured page content.

## Configuration

### Server Configuration (`server/config/config.php`)

| Option | Default | Description |
|--------|---------|-------------|
| `api.port` | 8080 | HTTP API port |
| `control.port` | 9999 | WebSocket control server port |
| `channel.port` | 2206 | Internal IPC channel port |
| `bot.heartbeat_interval` | 25 | Heartbeat interval in seconds |

## Scaling

```php
// In config/config.php, adjust worker counts:
'api' => [
    'workers' => 8,  // cpu_cores * 2
],
'control' => [
    'workers' => 4,
],
```

## API Response

Successful API requests return JSON:

```json
{
  "request_id": "req_...",
  "ok": true,
  "status": 200,
  "headers": {
    "content-type": "text/html; charset=utf-8",
    "x-broxy-title": "Example Domain",
    "x-broxy-final-url": "https://example.com/"
  },
  "body": "<html>...</html>",
  "error": null
}
```

## License

MIT
