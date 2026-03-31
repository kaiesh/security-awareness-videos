<?php

declare(strict_types=1);

namespace SecurityDrama;

final class Logger
{
    private const LEVELS = [
        'debug'    => 0,
        'info'     => 1,
        'warning'  => 2,
        'error'    => 3,
        'critical' => 4,
    ];

    private static bool $initialized = false;

    public static function init(): void
    {
        self::$initialized = true;
    }

    public static function log(string $module, string $level, string $message, array $context = []): void
    {
        $level = strtolower($level);
        if (!isset(self::LEVELS[$level])) {
            $level = 'info';
        }

        // Check configured log_level threshold
        $configLevel = strtolower($_ENV['LOG_LEVEL'] ?? 'debug');
        $threshold = self::LEVELS[$configLevel] ?? 0;
        if (self::LEVELS[$level] < $threshold) {
            return;
        }

        $contextJson = !empty($context) ? json_encode($context, JSON_UNESCAPED_SLASHES) : null;

        try {
            Database::getInstance()->execute(
                'INSERT INTO pipeline_log (module, level, message, context, created_at) VALUES (?, ?, ?, ?, NOW())',
                [$module, $level, $message, $contextJson]
            );
        } catch (\PDOException $e) {
            // Fallback if DB is unavailable
            error_log("[SecurityDrama] [{$level}] [{$module}] {$message}");
        }

        if ($level === 'critical') {
            error_log("[SecurityDrama CRITICAL] [{$module}] {$message}");
        }
    }

    public static function debug(string $module, string $message, array $context = []): void
    {
        self::log($module, 'debug', $message, $context);
    }

    public static function info(string $module, string $message, array $context = []): void
    {
        self::log($module, 'info', $message, $context);
    }

    public static function warning(string $module, string $message, array $context = []): void
    {
        self::log($module, 'warning', $message, $context);
    }

    public static function error(string $module, string $message, array $context = []): void
    {
        self::log($module, 'error', $message, $context);
    }

    public static function critical(string $module, string $message, array $context = []): void
    {
        self::log($module, 'critical', $message, $context);
    }

    /**
     * Mask a secret string, showing only first 4 and last 4 characters.
     */
    public static function maskSecret(string $secret): string
    {
        $len = strlen($secret);
        if ($len <= 8) {
            return str_repeat('*', $len);
        }
        return substr($secret, 0, 4) . str_repeat('*', $len - 8) . substr($secret, -4);
    }
}
