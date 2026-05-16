<?php
require __DIR__ . '/../vendor/autoload.php';

use Ratchet\Http\HttpServer;
use Ratchet\Server\IoServer;
use Ratchet\WebSocket\WsServer;
use App\WebSocket\Server;

$server = IoServer::factory(
    new HttpServer(new WsServer(new Server())),
    8090 // port
);
echo "WebSocket server running on port 8090\n";
$server->run();
