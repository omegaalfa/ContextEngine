<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Omegaalfa\Utils\ScriptRunner\ScriptRunner;

$runner = new ScriptRunner([
    __DIR__ . '/examples'
]);

$runner->run();