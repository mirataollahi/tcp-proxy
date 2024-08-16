<?php

ini_set('display_errors',true);
error_reporting(E_ERROR | E_PARSE);
require_once __DIR__.'/composer/vendor/autoload.php';

const BASE_PATH = __DIR__;

/**
 * Start base application context and run proxies servers
 */
new \App\AppContext();
