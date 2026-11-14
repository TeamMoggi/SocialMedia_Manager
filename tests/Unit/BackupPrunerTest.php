<?php

namespace Tests\Unit;

use App\Domain\Backup\BackupPruner;
use PHPUnit\Framework\TestCase;

class BackupPrunerTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'smm-prune-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
        parent::tearDown();
    }

    public function test_prune_keeps_newest_14_and_ignores_other_files(): void
    {
        $backups = [];
        for ($day = 1; $day <= 16; $day++) {
            $name = sprintf('smm-backup-202611%02d-2100.zip', $day);
            file_put_contents($this->dir.DIRECTORY_SEPARATOR.$name, 'zip');
            $backups[] = $name;
        }
        file_put_contents($this->dir.DIRECTORY_SEPARATOR.'notes.txt', 'mine');
        file_put_contents($this->dir.DIRECTORY_SEPARATOR.'smm-backup-old.zip', 'not ours');

        $deleted = BackupPruner::prune($this->dir, 14);

        $this->assertSame(['smm-backup-20261101-2100.zip', 'smm-backup-20261102-2100.zip'], $deleted);
        $left = array_map('basename', glob($this->dir.DIRECTORY_SEPARATOR.'*'));
        sort($left);
        $expected = array_merge(array_slice($backups, 2), ['notes.txt', 'smm-backup-old.zip']);
        sort($expected);
        $this->assertSame($expected, $left);
    }

    public function test_prune_with_few_backups_deletes_nothing(): void
    {
        file_put_contents($this->dir.DIRECTORY_SEPARATOR.'smm-backup-20261114-2100.zip', 'zip');

        $this->assertSame([], BackupPruner::prune($this->dir, 14));
        $this->assertFileExists($this->dir.DIRECTORY_SEPARATOR.'smm-backup-20261114-2100.zip');
    }
}
