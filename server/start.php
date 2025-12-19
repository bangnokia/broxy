<?php

/**
 * Broxy Server - Main entry point
 * 
 * Starts all Workerman servers:
 * - Channel Server (IPC)
 * - Control Server (WebSocket for bots)
 * - Proxy Server (HTTP proxy)
 */

require_once __DIR__ . '/vendor/autoload.php';

use Broxy\Server\ChannelServer;
use Broxy\Server\ControlServer;
use Broxy\Server\ProxyServer;
use Workerman\Worker;

// Load configuration
$config = require __DIR__ . '/config/config.php';

// Display startup banner
echo <<<BANNER
╔═══════════════════════════════════════════════════════════════╗
║                         BROXY SERVER                          ║
║           Distributed Browser-Based Proxy System              ║
╠═══════════════════════════════════════════════════════════════╣
║  Channel Server (IPC):     {$config['channel']['host']}:{$config['channel']['port']}                       ║
║  Control Server (WS):      {$config['control']['host']}:{$config['control']['port']}                        ║
║  Proxy Server (HTTP):      {$config['proxy']['host']}:{$config['proxy']['port']}                        ║
╚═══════════════════════════════════════════════════════════════╝

BANNER;

// Start Channel Server first (required for IPC)
$channelServer = new ChannelServer($config);

// Start Control Server (manages bot pool)
$controlServer = new ControlServer($config);

// Start Proxy Server (accepts HTTP requests)
$proxyServer = new ProxyServer($config);

// Run all workers
Worker::runAll();

