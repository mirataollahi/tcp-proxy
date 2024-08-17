<?php

declare(strict_types=1);

namespace App\Client;

use App\AppContext;
use App\Logger\Logger;
use Swoole\Client;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Swoole\Server;
use Swoole\Timer;
use Throwable;

class TCPClient
{
    /**
     * @var Server App master tcp server
     */
    public Server $masterServer;

    /**
     *  Client connection unique fd in master server
     */
    public int $fd;

    public float $connectedAt;

    /**
     * Target tcp connection
     */
    public Client $targetClient;

    /**
     * Application command line logger
     */
    public Logger $logger;

    /**
     * Client is freeing and wants to close
     */
    public bool $isFree = false;

    /**
     * Application context to access app instance properties
     */
    public AppContext $appContext;

    /**
     * Target tcp connection packet receiver coroutine id
     */
    public int $targetPacketReceiverCid;

    /**
     * Incoming messages from client tcp connection waiting to sent to target server
     */
    public Channel $tcpClientPacketsQueue;

    /**
     * @var int Create a coroutine and listen to incoming packets from tcp client connection to queue incoming packets
     */
    public int $clientPacketReceiverCid;

    /**
     * @var bool When first packet from target tcp connection received , the property change to true
     */
    public bool $targetStreamStarted = false;

    /**
     * @var int|null Last tcp packet received at this timestamp from target tcp connection
     */
    public ?int $lastTargetPacketReceivedAt = null;

    /**
     * @var int Heartbeat coroutine id
     */
    public int $heartbeatCid;

    /**
     * @param AppContext $app Application instance
     * @param int $fd Client unique fd in the master server
     * @param string $host Target tcp server host address
     * @param int $port Target tcp server port number
     */
    public function __construct(AppContext $app, int $fd, string $host, int $port)
    {
        $this->connectedAt = microtime(true);
        $this->logger = new Logger();
        $this->tcpClientPacketsQueue = new Channel(100);
        $this->logger->success("[Client $fd] [Initialize] => Initializing instance ....");
        $this->appContext = $app;
        $this->masterServer = $app->masterServer;
        $this->fd = $fd;
        $this->targetClient = new Client(SWOOLE_SOCK_TCP);
        $this->logger->info("[Client $this->fd] [Initialize] => Connection to target tcp server ....");

        if (!$this->targetClient->connect($host, $port, 2)) {
            $this->logger->error("[Client $this->fd] [Initialize] => Target connection failed because {$this->targetClient->errCode}");
            $this->free();
        } else {
            /** Successfully target connection established */
            $this->logger->success("[Client $this->fd] [Initialize] => Target tcp connection established");

            /** Create timer id */
            $this->createHeartbeat();

            /** Start running tcp client and target connection packet forwarder coroutines */
            $this->createTargetPacketReceiver();

            /** Create tcp client packet receiver */
            $this->createTcpClientPacketReceiver();
        }
    }

    /**
     * Send data packet to target tcp connection if connection exists and alive
     */
    public function sendPacketToTarget(?string $data): void
    {
        try {
            if (isset($this->targetClient)) {
                if ($this->targetClient->isConnected()) {
                    $this->targetClient->send($data);
                }
            }
        } catch (Throwable $exception) {
            $this->logger->error("[Client $this->fd] [Send Target Packet] => Error in sending to target tcp connection : {$exception->getMessage()}");
        }
    }

    /**
     * Receive packet from client tcp connection event handler
     */
    public function onReceiveFromClient(?string $packet): void
    {
        try {
            $packetLen = strlen($packet);
            $this->logger->info("[Client $this->fd] [On Receive Packet TCP Client] => Forwarded to target connection with length $packetLen");
            $this->sendPacketToTarget($packet);
        } catch (Throwable $exception) {
            $this->logger->error("[[Client $this->fd] [On Receive Packet TCP Client] [Error] => Forward to target error {$exception->getMessage()}");
        }
    }

    /**
     * Receive packet from target tcp server event handler
     */
    public function onReceiveFromTarget(?string $packet): void
    {
        $packetLen = strlen($packet);
        $this->appContext->masterServer->send($this->fd, $packet);
        $this->logger->info("[Client $this->fd] [On Receive Packet Target] => forwarded to client connection with length $packetLen");
    }

    /**
     * Create target tcp connection packet receiver and pass to packet handler
     */
    public function createTargetPacketReceiver(): void
    {
        $this->targetPacketReceiverCid = Coroutine::create(function () {
            $this->logger->success("[Client $this->fd] [Target Packet Receiver] => Starting target packet receiver ... ");
            while (!$this->isFree) {
                try {
                    $packet = $this->targetClient->recv();
                    if ($packet) {
                        Coroutine::create(function () use ($packet) {
                            $this->lastTargetPacketReceivedAt = microtime(true);
                            $this->onReceiveFromTarget($packet);
                        });
                    }
                    /** Break target connection if socket stream have non-normal error message */
                    else if ($this->targetClient->errCode !== SOCKET_ETIMEDOUT && $this->targetClient->errCode !== 0) {
                        $this->logger->info("[Client $this->fd] [Target Packet Receiver] => stopping because received target connection gracefully closed");
                        $this->free();
                        break;
                    }
                } catch (Throwable $exception) {
                    $this->logger->error("[Client $this->fd] [Target Packet Receiver] [Error] => target receiver error {$exception->getMessage()}");
                }
            }
            $this->logger->info("[Client $this->fd] [Target Packet Receiver]  => Target packet receiver stopped");
        });
    }

    /**
     * @return void Create a coroutine and listen to incoming packets from client tcp connection
     */
    public function createTcpClientPacketReceiver(): void
    {
        $this->clientPacketReceiverCid = Coroutine::create(function () {
            $this->logger->success("[Client $this->fd] [TCP Packet Receiver] => Starting TCP client packet receiver coroutine ... ");
            while (!$this->isFree) {
                $packet = $this->tcpClientPacketsQueue->pop(1);
                if ($packet) {
                    $this->logger->success("[Client $this->fd] [TCP Packet Receiver] => Tcp client packet receiver successfully received new packet");
                    $this->onReceiveFromClient($packet);
                }
            }
            $this->logger->info("[Client $this->fd] [TCP Packet Receiver] [Stopped] => TCP client packet listener stopped");
        });
    }

    /**
     * Close target tcp connection and remove client connection from master server and stop coroutines
     */
    public function free(): void
    {
        /** Check if freeing  */
        if ($this->isFree) {
            $this->logger->info("[Client $this->fd] [Freeing] => Already is in free and close process ...");
            return;
        }
        $this->isFree = true;

        /** Close target tcp connection if exists and connected */
        $this->closeTargetTcpConnection();
        /** Close client connection if exists and connected */
        $this->closeClientConnection();
        /** Close Coroutine for receive packets from target server */
        $this->closeTargetPacketReceiverCoroutine();
        /** Close Heartbeat and health checker coroutine */
        $this->closeHeartbeatCoroutine();
        $this->logger->info("[Client $this->fd] [Freeing] [Finished] => Free and close process finished");
    }

    /**
     * Close and unset tcp connection to target tcp server if exists
     */
    public function closeTargetTcpConnection(): void
    {
        try {
            if (isset($this->targetClient)) {
                if ($this->targetClient->isConnected()) {
                    $this->logger->info("[Client $this->fd] [Freeing] => Closing target tcp connection ...");
                    $this->targetClient->close();
                    $this->logger->success("[Client $this->fd] [Freeing] => Target tcp connection closed successfully");
                }
                unset($this->targetClient);
            }
        } catch (Throwable $exception) {
            $this->logger->error("[Client $this->fd] [Freeing] [Error] => Error in closing target connection because {$exception->getMessage()}");
        }
    }

    /**
     * Close client connection from master server if exists in clients list
     */
    public function closeClientConnection(): void
    {
        try {
            if (array_key_exists($this->fd, $this->appContext->clients)) {
                $this->logger->info("[Client $this->fd] [Freeing] => Trying closing client tcp connection ...");
                $this->masterServer->close($this->fd);
                $this->logger->info("[Client $this->fd] [Freeing] => TCP client connection closed successfully");
                unset($this->appContext->clients[$this->fd]);
            }
        } catch (Throwable $exception) {
            $this->logger->error("[Client #$this->fd] [Freeing] => Close tcp client connection error : {$exception->getMessage()}");
        }
    }

    /**
     * Close target connection packet receiver coroutine if exist and running
     */
    public function closeTargetPacketReceiverCoroutine(): void
    {
        try {
            $this->logger->info("[Client #$this->fd] [Freeing] => Trying to cancel target packet receiver coroutine ...");
            if (isset($this->targetPacketReceiverCid)) {
                Coroutine::cancel($this->targetPacketReceiverCid);
                unset($this->targetPacketReceiverCid);
                $this->logger->success("[Client #$this->fd] [Freeing] => successfully cancel target packet receiver coroutine");
            }
        } catch (Throwable $exception) {
            $this->logger->error("[Client #$this->fd] [Freeing] [Error] => error in close target packet receiver coroutine because {$exception->getMessage()}");
        }
    }

    /**
     * Close heartbeat coroutine if exists and running
     */
    public function closeHeartbeatCoroutine(): void
    {
        try {
            $this->logger->info("[Client #$this->fd] [Freeing] => Trying close heartbeat coroutine ... ");
            if (isset($this->heartbeatCid)) {
                Coroutine::cancel($this->heartbeatCid);
                unset($this->heartbeatCid);
                $this->logger->success("[Client #$this->fd] [Freeing] => successfully cleared heartbeat timer");
            }
        } catch (Throwable $exception) {
            $this->logger->error("[Client #$this->fd] [Freeing] [Error] => error in cancel heartbeat timer because {$exception->getMessage()}");
        }
    }

    /**
     * Create heartbeat timer and start checking
     */
    public function createHeartbeat(): void
    {
        $this->logger->info("[Client #$this->fd] [Heartbeat] => Create heartbeat coroutine ... ");
        $this->heartbeatCid = Coroutine::create(function () {
            while (!$this->isFree){
                $aliveDuration = microtime(true) - $this->connectedAt;

                /** Wait client target and client connection starting up */
                if ($aliveDuration <= 2.0) {
                    return;
                }

                /** Break if client connection closed */
                if (!array_key_exists($this->fd, $this->appContext->clients)) {
                    $this->logger->info("[Client $this->fd] [Target Packet Receiver] => stopping because client connection closed");
                    $this->free();
                    break;
                }


                /** Break if target connection closed */
                if (!$this->targetClient->isConnected()) {
                    $this->logger->info("[Client $this->fd] [Target Packet Receiver] => stopping because target connection closed");
                    $this->free();
                    break;
                }

                /** Check last target packet received timestamp passed seconds */
                $lastTargetPacketDuration = $this->lastTargetPacketReceivedAt
                    ? microtime(true) - $this->lastTargetPacketReceivedAt
                    : null;

                /** Free up client if last packet from target received is null*/
                if (is_null($lastTargetPacketDuration)){
                    $this->logger->info("[Client #$this->fd] [Heartbeat] => Start freeing client because do not received target packet");
                    $this->free();
                    break;
                }

                /** Free up client if last packet from target received more than 1.7 second*/
                else if ($lastTargetPacketDuration >= 2){
                    $this->logger->info("[Client #$this->fd] [Heartbeat] => Start freeing client because last target packet received $lastTargetPacketDuration seconds ago");
                    $this->free();
                    break;
                }

                Coroutine::sleep(0.2);
            }
        });
    }
}

