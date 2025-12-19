<?php

namespace Broxy\Server;

use Channel\Server;

/**
 * Channel Server for Inter-Process Communication between Proxy and Control servers
 */
class ChannelServer
{
    private Server $server;
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->server = new Server(
            $config['channel']['host'],
            $config['channel']['port']
        );
    }

    public function getServer(): Server
    {
        return $this->server;
    }
}

