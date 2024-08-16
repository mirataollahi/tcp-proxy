<?php

declare(strict_types=1);
namespace App\Exceptions;

use Exception;

class StartupException extends Exception
{
    public function __construct(?string $message = null)
    {
        parent::__construct("Start up error : $message");
    }
}
