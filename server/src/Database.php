<?php

declare(strict_types=1);

namespace AlpesEx\Portal;

use PDO;

final class Database
{
    public static function connect(Config $config): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $config->string('DB_HOST'),
            $config->integer('DB_PORT'),
            $config->string('DB_NAME')
        );

        return new PDO(
            $dsn,
            $config->string('DB_USERNAME'),
            $config->string('DB_PASSWORD'),
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_TIMEOUT => 5,
            ]
        );
    }
}
