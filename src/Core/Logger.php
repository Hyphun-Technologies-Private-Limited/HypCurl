<?php

declare(strict_types=1);

namespace Hyphun\HypCurl\Core;

/**
 * Leveled logger.
 *  - CLI: writes to stderr (stdout stays clean for response output).
 *  - Web (FPM, FrankenPHP, ...): silent unless you attach a PSR-3 logger:
 *      HypCurl::boot(['logger' => $monolog]);
 * Level comes from HYPCURL_LOG_LEVEL (DEBUG, INFO, WARN, ERROR, OFF).
 */
final class Logger
{
    use Singleton;

    private const LEVELS = ['DEBUG' => 10, 'INFO' => 20, 'WARN' => 30, 'ERROR' => 40, 'OFF' => 100];
    private const COLORS = ['DEBUG' => "\033[90m", 'INFO' => "\033[36m", 'WARN' => "\033[33m", 'ERROR' => "\033[31m"];
    private const PSR_LEVELS = ['DEBUG' => 'debug', 'INFO' => 'info', 'WARN' => 'warning', 'ERROR' => 'error'];

    private int $threshold;
    /** @var resource|null */
    private $stream = null;
    private bool $color = false;
    /** PSR-3 compatible logger (anything with log($level, $message, $context)). */
    private ?object $psr = null;

    private function __construct()
    {
        $this->setLevel(Config::instance()->get('HYPCURL_LOG_LEVEL', 'INFO'));
        if (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg') {
            $this->stream = fopen('php://stderr', 'wb') ?: null;
            $this->color = $this->stream !== null && stream_isatty($this->stream) && getenv('NO_COLOR') === false;
        }
    }

    public function setLevel(string $level): void
    {
        $this->threshold = self::LEVELS[strtoupper($level)] ?? self::LEVELS['INFO'];
    }

    /** Send logs to a PSR-3 logger (e.g. Monolog, Laravel's Log) instead of stderr. */
    public function useLogger(?object $logger): void
    {
        if ($logger !== null && !method_exists($logger, 'log')) {
            throw new \InvalidArgumentException('Logger must be PSR-3 compatible (have a log() method).');
        }
        $this->psr = $logger;
    }

    public function debug(string $message): void { $this->log('DEBUG', $message); }
    public function info(string $message): void  { $this->log('INFO', $message); }
    public function warn(string $message): void  { $this->log('WARN', $message); }
    public function error(string $message): void { $this->log('ERROR', $message); }

    private function log(string $level, string $message): void
    {
        if (self::LEVELS[$level] < $this->threshold) {
            return;
        }
        if ($this->psr !== null) {
            $this->psr->log(self::PSR_LEVELS[$level], "[HypCurl] $message");

            return;
        }
        if ($this->stream !== null) {
            [$start, $end] = $this->color ? [self::COLORS[$level], "\033[0m"] : ['', ''];
            fwrite($this->stream, sprintf("%s[%s] %-5s%s %s\n", $start, date('H:i:s'), $level, $end, $message));
        }
    }
}
