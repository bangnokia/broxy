<?php

return [
    // Proxy server configuration
    'proxy' => [
        'host' => '0.0.0.0',
        'port' => 8080,
        'workers' => 4, // cpu_cores * 2
    ],

    // WebSocket control server configuration
    'control' => [
        'host' => '0.0.0.0',
        'port' => 9999,
        'workers' => 2,
    ],

    // Channel server for IPC
    'channel' => [
        'host' => '127.0.0.1',
        'port' => 2206,
    ],

    // Bot configuration
    'bot' => [
        'heartbeat_interval' => 25, // seconds
        'heartbeat_timeout' => 60, // seconds - consider bot dead if no heartbeat
        'request_timeout' => 30, // seconds - timeout for individual requests
    ],



    // Request queue settings
    'queue' => [
        'max_pending' => 1000, // Maximum pending requests
        'request_ttl' => 60, // seconds - request timeout
    ],
];

