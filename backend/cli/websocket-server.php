<?php
require __DIR__ . '/../vendor/autoload.php';

use Ratchet\Http\HttpServer;
use Ratchet\Server\IoServer;
use Ratchet\WebSocket\WsServer;
use App\WebSocket\Server;

// Read the port from WS_PORT environment variable, falling back to 8090
$port = getenv('WS_PORT') ?: 8090;

$server = IoServer::factory(
    new HttpServer(new WsServer(new Server())),
    (int)$port // port
);
echo "WebSocket server running on port $port\n";
$server->run();

