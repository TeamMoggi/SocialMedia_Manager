<?php

namespace App\Domain\Backup;

use Carbon\CarbonImmutable;
use FilesystemIterator;
use Illuminate\Support\Facades\DB;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use ZipArchive;

/**
 * Makes one password-protected (AES-256) zip of the data folder: a consistent copy of the database,
 * the settings file (.env, which holds the app key) and every photo and video in media/.
 */
final class BackupMaker
{
    public function make(string $targetDir, string $password, CarbonImmutable $now): string
    {
        if (! is_dir($targetDir) && ! mkdir($targetDir, 0777, true) && ! is_dir($targetDir)) {
            throw new RuntimeException("Cannot create the backup folder {$targetDir}");
        }

        $stamp = $now->setTimezone((string) config('smm.display_timezone'))->format('Ymd-Hi');
        $path = $targetDir.DIRECTORY_SEPARATOR."smm-backup-{$stamp}.zip";
        $dataDir = app('smm.data_dir');

        // VACUUM INTO writes a consistent copy even while the app is using the database.
        $dbCopy = $targetDir.DIRECTORY_SEPARATOR.".smm-backup-{$stamp}.sqlite.tmp";
        @unlink($dbCopy);
        DB::statement('VACUUM INTO '.DB::getPdo()->quote($dbCopy));

        try {
            $zip = new ZipArchive;
            if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException("Cannot write {$path}");
            }
            $this->addEncrypted($zip, $dbCopy, 'database.sqlite', $password);

            $env = $dataDir.DIRECTORY_SEPARATOR.'.env';
            if (is_file($env)) {
                $this->addEncrypted($zip, $env, '.env', $password);
            }

            $media = $dataDir.DIRECTORY_SEPARATOR.'media';
            if (is_dir($media)) {
                $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($media, FilesystemIterator::SKIP_DOTS));
                foreach ($files as $file) {
                    if ($file->isFile()) {
                        $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($media) + 1));
                        $this->addEncrypted($zip, $file->getPathname(), 'media/'.$relative, $password);
                    }
                }
            }

            if (! $zip->close()) {
                throw new RuntimeException("Could not finish {$path}");
            }
        } finally {
            @unlink($dbCopy);
        }

        return $path;
    }

    private function addEncrypted(ZipArchive $zip, string $file, string $name, string $password): void
    {
        if (! $zip->addFile($file, $name) || ! $zip->setEncryptionName($name, ZipArchive::EM_AES_256, $password)) {
            throw new RuntimeException("Could not add {$name} to the backup");
        }
    }
}
