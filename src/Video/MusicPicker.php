<?php

declare(strict_types=1);

namespace SecurityDrama\Video;

use RuntimeException;
use SecurityDrama\Database;
use SecurityDrama\Logger;
use SecurityDrama\Storage;

final class MusicPicker
{
    private const MODULE   = 'music';
    private const TEMP_DIR = '/tmp/securitydrama';

    private Database $db;
    private Storage $storage;

    public function __construct()
    {
        $this->db      = Database::getInstance();
        $this->storage = Storage::getInstance();

        if (!is_dir(self::TEMP_DIR)) {
            mkdir(self::TEMP_DIR, 0750, true);
        }
    }

    /**
     * Pick a random active background music track for the given content type
     * and download it locally. Returns null if no active track exists for the
     * category — the caller should compose without music in that case.
     *
     * @return array{id:int,name:string,local_path:string,volume:float,credit_text:?string}|null
     */
    public function pickForCategory(string $contentType): ?array
    {
        $row = $this->db->fetchOne(
            'SELECT * FROM background_music
             WHERE category = ? AND is_active = 1
             ORDER BY RAND() LIMIT 1',
            [$contentType]
        );

        if ($row === null) {
            Logger::debug(self::MODULE, 'No active music for category', ['category' => $contentType]);
            return null;
        }

        $ext = pathinfo($row['storage_path'], PATHINFO_EXTENSION) ?: 'mp3';
        $localPath = self::TEMP_DIR . '/music_' . (int) $row['id'] . '.' . $ext;

        $this->storage->download($row['storage_path'], $localPath);
        if (!file_exists($localPath)) {
            throw new RuntimeException("Failed to download music track {$row['id']} from Spaces");
        }

        Logger::info(self::MODULE, 'Picked music track', [
            'category' => $contentType,
            'id'       => (int) $row['id'],
            'name'     => $row['name'],
        ]);

        return [
            'id'          => (int) $row['id'],
            'name'        => (string) $row['name'],
            'local_path'  => $localPath,
            'volume'      => (float) $row['volume'],
            'credit_text' => $row['credit_text'] !== null ? (string) $row['credit_text'] : null,
        ];
    }
}
