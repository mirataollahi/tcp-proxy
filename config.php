<?php


/**  Application config */


use App\Logger\LogLevel;

return [

    /** Show more details and app logs in debug mode */
    'is_debug' => true,
    'daemonize' => false ,
    'worker_number' => 2,
    'log_levels' => LogLevel::INFO|LogLevel::SUCCESS|LogLevel::WARNING|LogLevel::ERROR|LogLevel::DEBUG,


    /** Master tcp server configs */
    'server' => [
        'host' => '0.0.0.0',
        'port' => 8500
    ],


    /** Target tcp server . packet forwarded to this server */
    'target' => [
        'host' => '127.0.0.1',
        'port' => 22,
    ],
];
