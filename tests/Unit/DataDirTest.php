<?php

namespace Tests\Unit;

use App\Support\DataDir;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class DataDirTest extends TestCase
{
    private string $root;
    private string|false $savedEnv;

    protected function setUp(): void
    {
        parent::setUp();
        $this->savedEnv = getenv('SMM_DATA_DIR');
        putenv('SMM_DATA_DIR');
        $this->root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'smm-datadir-'.bin2hex(random_bytes(4));
        mkdir($this->root.DIRECTORY_SEPARATOR.'SmmBase', 0777, true);
        mkdir($this->root.DIRECTORY_SEPARATOR.'Data', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->savedEnv === false ? putenv('SMM_DATA_DIR') : putenv('SMM_DATA_DIR='.$this->savedEnv);
        $this->removeTree($this->root);
        parent::tearDown();
    }

    private function base(): string
    {
        return $this->root.DIRECTORY_SEPARATOR.'SmmBase';
    }

    private function data(): string
    {
        return realpath($this->root.DIRECTORY_SEPARATOR.'Data');
    }

    private function writeDataDirFile(string $content): void
    {
        file_put_contents($this->base().DIRECTORY_SEPARATOR.'.data-dir', $content);
    }

    public function test_reads_path_from_data_dir_file(): void
    {
        $this->writeDataDirFile($this->data());

        $this->assertSame($this->data(), DataDir::resolve($this->base()));
    }

    public function test_env_var_overrides_file(): void
    {
        $other = $this->root.DIRECTORY_SEPARATOR.'Other';
        mkdir($other);
        $this->writeDataDirFile($this->data());
        putenv('SMM_DATA_DIR='.$other);

        $this->assertSame(realpath($other), DataDir::resolve($this->base()));
    }

    public function test_missing_file_and_env_throws(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('.data-dir');

        DataDir::resolve($this->base());
    }

    public function test_relative_path_rejected(): void
    {
        $this->writeDataDirFile('..\\Data');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('full path');

        DataDir::resolve($this->base());
    }

    public function test_missing_folder_rejected(): void
    {
        $this->writeDataDirFile($this->root.DIRECTORY_SEPARATOR.'Nowhere');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not exist');

        DataDir::resolve($this->base());
    }

    public function test_path_inside_code_folder_rejected_case_insensitively(): void
    {
        $inside = $this->base().DIRECTORY_SEPARATOR.'data';
        mkdir($inside);
        $this->writeDataDirFile(strtolower($inside));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('inside the code folder');

        DataDir::resolve($this->base());
    }

    public function test_bom_and_newline_are_ignored(): void
    {
        $this->writeDataDirFile("\xEF\xBB\xBF".$this->data()."\r\n");

        $this->assertSame($this->data(), DataDir::resolve($this->base()));
    }

    private function removeTree(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (array_diff(scandir($dir), ['.', '..']) as $item) {
            $path = $dir.DIRECTORY_SEPARATOR.$item;
            is_dir($path) ? $this->removeTree($path) : unlink($path);
        }
        rmdir($dir);
    }
}
