<?php

declare(strict_types=1);

namespace SecurityDrama;

final class Bootstrap
{
    private static bool $initialized = false;
    private static string $basePath;
    private static bool $isCli;

    public static function init(?string $basePath = null): void
    {
        if (self::$initialized) {
            return;
        }

        self::$isCli = PHP_SAPI === 'cli';
        self::$basePath = $basePath ?? dirname(__DIR__);

        self::loadEnv();
        self::registerAutoloader();

        Database::getInstance();
        Config::getInstance();
        Logger::init();

        self::$initialized = true;
    }

    public static function basePath(): string
    {
        return self::$basePath;
    }

    public static function isCli(): bool
    {
        return self::$isCli;
    }

    private static function loadEnv(): void
    {
        $envFile = self::$basePath . '/.env';
        if (!file_exists($envFile)) {
            return;
        }

        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $eqPos = strpos($line, '=');
            if ($eqPos === false) {
                continue;
            }

            $key = trim(substr($line, 0, $eqPos));
            $value = trim(substr($line, $eqPos + 1));

            // Strip surrounding quotes
            if (strlen($value) >= 2) {
                if (($value[0] === '"' && $value[-1] === '"') || ($value[0] === "'" && $value[-1] === "'")) {
                    $value = substr($value, 1, -1);
                }
            }

            $_ENV[$key] = $value;
            putenv("{$key}={$value}");
        }
    }

    private static function registerAutoloader(): void
    {
        spl_autoload_register(function (string $class): void {
            $prefix = 'SecurityDrama\\';
            if (!str_starts_with($class, $prefix)) {
                return;
            }

            $relativeClass = substr($class, strlen($prefix));
            $file = self::$basePath . '/src/' . str_replace('\\', '/', $relativeClass) . '.php';

            if (file_exists($file)) {
                require_once $file;
            }
        });
    }
}

/**
 * HTML-escape shorthand.
 */
function e(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}
