<?php

namespace App\Domain\Backup;

/**
 * Keeps the newest backups and deletes older ones. Only files named exactly like the app's own backups
 * (smm-backup-YYYYMMDD-HHMM.zip) are ever considered; anything else in the folder is left alone.
 */
final class BackupPruner
{
    public const PATTERN = '/^smm-backup-\d{8}-\d{4}\.zip$/';

    /**
     * @return array<int, string> the deleted file names, oldest first
     */
    public static function prune(string $dir, int $keep): array
    {
        $ours = [];
        foreach (scandir($dir) ?: [] as $name) {
            if (preg_match(self::PATTERN, $name) && is_file($dir.DIRECTORY_SEPARATOR.$name)) {
                $ours[] = $name;
            }
        }
        sort($ours); // the timestamp in the name sorts oldest first

        $deleted = [];
        foreach (array_slice($ours, 0, max(0, count($ours) - $keep)) as $name) {
            if (unlink($dir.DIRECTORY_SEPARATOR.$name)) {
                $deleted[] = $name;
            }
        }

        return $deleted;
    }
}
