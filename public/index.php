<?php

declare(strict_types=1);

use Benzina\App;
use Benzina\Config;

require __DIR__ . '/../vendor/autoload.php';

App::create(Config::fromEnv(Config::loadEnv(__DIR__ . '/../.env')))->run();
