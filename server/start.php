<?php

/**
 * Broxy Server - Main entry point
 * 
 * Starts all Workerman servers:
 * - Channel Server (IPC)
 * - Control Server (WebSocket for bots)
 * - API Server (HTTP URL render API)
 */

require_once __DIR__ . '/vendor/autoload.php';

use Broxy\Server\ChannelServer;
use Broxy\Server\ControlServer;
use Broxy\Server\ApiServer;
use Workerman\Worker;

// Load configuration
$config = require __DIR__ . '/config/config.php';

// Display startup banner
echo <<<BANNER
╔═══════════════════════════════════════════════════════════════╗
║                         BROXY SERVER                          ║
║              Distributed Browser URL Render API               ║
╠═══════════════════════════════════════════════════════════════╣
║  Channel Server (IPC):     {$config['channel']['host']}:{$config['channel']['port']}                       ║
║  Control Server (WS):      {$config['control']['host']}:{$config['control']['port']}                        ║
║  API Server (HTTP):        {$config['api']['host']}:{$config['api']['port']}                        ║
╚═══════════════════════════════════════════════════════════════╝

BANNER;

// Start Channel Server first (required for IPC)
$channelServer = new ChannelServer($config);

// Start Control Server (manages bot pool)
$controlServer = new ControlServer($config);

// Start API Server (accepts URL render requests)
$apiServer = new ApiServer($config);

// Run all workers
Worker::runAll();
