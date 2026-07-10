<?php

namespace App\WebSocket;

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use Exception;

/**
 * App\WebSocket\Server
 * 
 * Minimal production-safe WebSocket controller that implements Ratchet's MessageComponentInterface.
 * Used for broadcasting real-time updates (e.g. inventory logs, notifications) to active clients.
 */
class Server implements MessageComponentInterface
{
    /**
     * Storage of all active connections
     * 
     * @var \SplObjectStorage
     */
    protected \SplObjectStorage $clients;

    public function __construct()
    {
        $this->clients = new \SplObjectStorage();
    }

    /**
     * Triggered when a new client connects
     * 
     * @param ConnectionInterface $conn
     */
    public function onOpen(ConnectionInterface $conn)
    {
        $this->clients->attach($conn);
        echo sprintf("[WebSocket] New connection opened: resourceId=%d\n", $conn->resourceId);
    }

    /**
     * Triggered when a message is received from a client
     * 
     * @param ConnectionInterface $from
     * @param string $msg
     */
    public function onMessage(ConnectionInterface $from, $msg)
    {
        echo sprintf("[WebSocket] Message received from resourceId=%d: len=%d\n", $from->resourceId, strlen($msg));
        
        // Broadcast the message to all other connected clients
        foreach ($this->clients as $client) {
            if ($from !== $client) {
                try {
                    $client->send($msg);
                } catch (Exception $e) {
                    echo sprintf("[WebSocket] Error broadcasting to resourceId=%d: %s\n", $client->resourceId, $e->getMessage());
                }
            }
        }
    }

    /**
     * Triggered when a client connection is closed
     * 
     * @param ConnectionInterface $conn
     */
    public function onClose(ConnectionInterface $conn)
    {
        $this->clients->detach($conn);
        echo sprintf("[WebSocket] Connection closed: resourceId=%d\n", $conn->resourceId);
    }

    /**
     * Triggered when an error occurs on a connection
     * 
     * @param ConnectionInterface $conn
     * @param Exception $e
     */
    public function onError(ConnectionInterface $conn, Exception $e)
    {
        echo sprintf("[WebSocket] Connection error on resourceId=%d: %s\n", $conn->resourceId, $e->getMessage());
        try {
            $conn->close();
        } catch (Exception $closeEx) {
            // Ignore close exceptions
        }
    }
}
