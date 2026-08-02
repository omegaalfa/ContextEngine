<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Bootstrap\Config;

use InvalidArgumentException;

final readonly class DatabaseConfig
{
    /**
     * @param string $host
     * @param string $database
     * @param int $port
     * @param string $username
     * @param string $password
     */
    public function __construct(
        public string $host,
        public string $database,
        public int    $port,
        public string $username,
        public string $password,
    ) {
        if (trim($host) === '' || trim($database) === '' || trim($username) === '' || $password === '') {
            throw new InvalidArgumentException('Database configuration values cannot be empty.');
        }
        if ($port < 1 || $port > 65_535) {
            throw new InvalidArgumentException('Database port must be between 1 and 65535.');
        }
    }
}
