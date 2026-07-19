<?php

declare(strict_types=1);

namespace App\Logging;

use App\Support\Config;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Level;
use Monolog\Logger;
use Monolog\Processor\PsrLogMessageProcessor;

final class LoggerFactory
{
    public function create(Config $config): Logger
    {
        $path = $config->requireString('logging.path');
        $directory = dirname($path);

        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new \RuntimeException('Unable to create the log directory.');
        }

        $level = Level::fromName((string) $config->get('logging.level', 'info'));
        $handler = new RotatingFileHandler($path, (int) $config->get('logging.max_files', 14), $level, true, 0640);
        $logger = new Logger('ask-archie');
        $logger->pushProcessor(new SecretRedactionProcessor());
        $logger->pushProcessor(new PsrLogMessageProcessor());
        $logger->pushHandler($handler);

        return $logger;
    }
}
