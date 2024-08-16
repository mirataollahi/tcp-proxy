<?php

declare(strict_types=1);
namespace App\Tools;

class Utils
{
    /**
     * Get path base on app base or root path
     */
    public static function basePath(?string $path = null): string
    {
        if ($path) {
            return BASE_PATH.'/'.ltrim($path, '/');
        }
        return BASE_PATH;
    }
}
