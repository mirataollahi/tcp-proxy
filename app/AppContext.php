<?php

declare(strict_types=1);
namespace App;

use App\Client\TCPClient;
use App\Exceptions\StartupException;
use App\Logger\Logger;
use App\Tools\Utils;
use Swoole\Coroutine;
use Swoole\Server;
use Swoole\Server\StatusInfo;
use Throwable;


class AppContext
{
    /**
     * @var bool Application is running in debug mode or not
     */
    public bool $isDebug = false;

    /**
     * @var Server Master tcp server
     */
    public Server $masterServer;

    /**
     * @var array Application configs
     */
    public array $appConfigs = [];

    /**
     * @var Logger Application logger service
     */
    public Logger $logger;

    /**
     * @var array Current client connection alive in this tcp server and worker
     */
    public array $clients = [];

    /**
     * Target tcp server host address
     */
    public string $targetHost;

    /**
     * @var int Target tcp server port number
     */
    public int $targetPort;


    /**
     * Create an instance of tcp stream proxy
     */
    public function __construct()
    {
        try {
            $this->logger = new Logger($this->appConfigs);
            $this->importConfigs();
            $this->initMasterServer();
        }
        catch (Throwable $exception){
            $this->logger->exception($exception);
        }
    }

    /**
     * @return void Import application and proxies configs from config.php file
     * @throws StartupException
     */
    public function importConfigs(): void
    {
        try {
            $configs = require_once Utils::basePath('config.php');
            if (is_array($configs)){
                $this->appConfigs = $configs;
            }

            $this->targetHost = $this->appConfigs['target']['host'];
            $this->targetPort = $this->appConfigs['target']['port'];
        }
        catch (Throwable $exception){
            throw new StartupException("import config failed because {$exception->getMessage()}");
        }
    }

    /**
     * @return void Initialize app master http server
     */
    public function initMasterServer(): void
    {
        $masterConfigs = $this->appConfigs['server'];
        $this->masterServer = new Server($masterConfigs['host'],$masterConfigs['port']);
        $this->masterServer->set([
            'display_errors' => true,
            'max_coroutine' => 50000 ,
            'worker_num' => $this->appConfigs['worker_number'] ,
            'open_tcp_keepalive' => true,
            'tcp_fastopen' => true,
            'enable_coroutine' => true ,
            'daemonize' => $this->appConfigs['daemonize'],
        ]);
        $this->masterServer->on('Start', [$this, 'onStart']);
        $this->masterServer->on('ManagerStart', [$this, 'onManagerStart']);
        $this->masterServer->on('ManagerStop', [$this, 'onManagerStop']);
        $this->masterServer->on('WorkerStart', [$this, 'onWorkerStart']);
        $this->masterServer->on('WorkerStop', [$this, 'onWorkerStop']);
        $this->masterServer->on('WorkerError', [$this, 'onWorkerError']);
        $this->masterServer->on('Connect', [$this, 'onConnect']);
        $this->masterServer->on('Close', [$this, 'onClose']);
        $this->masterServer->on('Receive', [$this, 'onReceive']);
        $this->masterServer->start();
    }

    /**
     * Master tcp server start event handler
     */
    public function onStart(Server $server): void
    {
        $this->logger->info("[TCP Server] [Event Start] => TCP Server started at {$this->masterServer->host}:{$this->masterServer->port}");
    }

    /**
     * Master tcp server a worker start event handler
     */
    public function onWorkerStart(Server $server, int $workerId): void
    {
        $this->logger->info("[TCP Server]  [Event  Worker Start] Worker $workerId started with pid {$this->masterServer->getWorkerPid()}");
    }

    /**
     * Master tcp server worker process stopped
     */
    public function onWorkerStop(Server $server,int $workerId): void
    {
        $this->logger->warning("[TCP Server]  [Event Worker Stopped]  Worker $workerId worker stopped");
    }

    /**
     * Master tcp server manager process started event handler
     */
    public function onManagerStart(Server $server): void
    {
        $this->logger->info("[TCP Server]  [Event Manager start]  Manager process started with pid {$this->masterServer->getManagerPid()}");
    }

    /**
     * Master tcp server manager process stopped event handler
     */
    public function onManagerStop(Server $server): void
    {
        $this->logger->info("[TCP Server]  [Event Manager stop]  Manager process stopped");
    }

    public function onWorkerError(Server $server,StatusInfo $statusInfo): void
    {
        $this->logger->error("[TCP Server] [Event Worker Error] => ");
    }

    /**
     * TCP Client connected to master server event handler
     */
    public function onConnect(Server $server, int $fd): void
    {
        Coroutine::create(function () use ($fd){
            $this->logger->info("[TCP Server]  [Event Connect] => 🟢 Client $fd connected to server");
        });

        if (!array_key_exists($fd, $this->clients)) {
            $this->clients [$fd] = new TCPClient($this, $fd, $this->targetHost, $this->targetPort);
        }
    }

    /**
     * Master tcp server start event handler
     */
    public function onClose(Server $server, int $fd): void
    {
        $this->logger->info("[TCP Server]  [Event Close] => 🔴 Client $fd closed connection");
        if (array_key_exists($fd, $this->clients)) {
            /** @var TCPClient $client */
            $client = $this->clients[$fd];
            $client->free();
        }
    }

    /**
     * Receive packet from a tcp client connection event handler
     */
    public function onReceive(Server $server, int $fd, int $reactorId, string $data): void
    {
        // $this->logger->success("[TCP Server]  [Event Receive] => Received data from client  #$fd");
        if (array_key_exists($fd, $this->clients)) {
            try {
                /** @var TCPClient $tcpClient */
                $tcpClient = $this->clients[$fd];
                $tcpClient->tcpClientPacketsQueue->push($data);
            }
            catch (Throwable $exception){
                $this->logger->error("[TCP Server]  [Event Receive] Error in receive packet : {$exception->getMessage()}");
            }
        }
    }
}
