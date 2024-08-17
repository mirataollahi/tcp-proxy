<?php


/**  Application config */


use App\Logger\LogLevel;

return [

    /** Show more details and app logs in debug mode */
    'is_debug' => true,
    'daemonize' => false ,
    'worker_number' => 1,
    'log_levels' => LogLevel::INFO|LogLevel::SUCCESS|LogLevel::WARNING|LogLevel::ERROR|LogLevel::DEBUG,


    /** Master tcp server configs */
    'server' => [
        'host' => '0.0.0.0',
        'port' => 7000
    ],


    /** Target tcp server . packet forwarded to this server */
    'target' => [
        'host' => '192.168.66.62',
        'port' => 7001,
    ],
];
