<?php

declare(strict_types=1);

namespace SecurityDrama;

final class Config
{
    private static ?self $instance = null;
    private array $dbCache = [];
    private bool $dbLoaded = false;

    private function __construct()
    {
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Get a config value. Checks $_ENV first, then the DB config table.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        if (isset($_ENV[$key])) {
            return $_ENV[$key];
        }

        $this->loadFromDb();

        return $this->dbCache[$key] ?? $default;
    }

    /**
     * Write a config value to the DB config table (upsert).
     */
    public function set(string $key, string $value): void
    {
        $db = Database::getInstance();
        $db->execute(
            'INSERT INTO config (config_key, config_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)',
            [$key, $value]
        );
        $this->dbCache[$key] = $value;
    }

    public function getAll(): array
    {
        $this->loadFromDb();

        $merged = $this->dbCache;
        foreach ($_ENV as $key => $value) {
            $merged[$key] = $value;
        }
        return $merged;
    }

    private function loadFromDb(): void
    {
        if ($this->dbLoaded) {
            return;
        }

        try {
            $rows = Database::getInstance()->fetchAll('SELECT config_key, config_value FROM config');
            foreach ($rows as $row) {
                $this->dbCache[$row['config_key']] = $row['config_value'];
            }
        } catch (\PDOException $e) {
            // Table may not exist yet during initial setup
        }

        $this->dbLoaded = true;
    }
}
