<?php

namespace Tests\Feature;

use App\Domain\Library\LibraryScanner;
use App\Enums\MediaKind;
use App\Models\Brand;
use App\Models\MediaGroup;
use App\Models\MediaItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\Support\Mp4Fixture;
use Tests\TestCase;

class LibraryScanTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private Brand $brand;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'smm-scan-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->dir.'/Images');
        File::ensureDirectoryExists($this->dir.'/5');
        $this->jpeg($this->dir.'/Images/Fous on product !.jpg', 1080, 1080);
        Mp4Fixture::make($this->dir.'/5/final.mp4', 1080, 1920, 10.0);
        file_put_contents($this->dir.'/5/voice hindi.wav', 'RIFF-wav-bytes');
        file_put_contents($this->dir.'/5/project.wfp', 'filmora');
        $this->brand = Brand::where('slug', 'moggies-international')->firstOrFail();
        $this->brand->update(['library_sources' => [$this->dir]]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        File::delete(app('smm.data_dir').'/run/media-scan.lock');
        parent::tearDown();
    }

    private function jpeg(string $path, int $w, int $h): void
    {
        $img = imagecreatetruecolor($w, $h);
        imagejpeg($img, $path, 80);
    }

    private function scan()
    {
        return app(LibraryScanner::class)->scan($this->brand->fresh());
    }

    private function snapshot(): array
    {
        clearstatcache();
        $out = [];
        foreach (File::allFiles($this->dir) as $file) {
            $out[$file->getRelativePathname()] = [$file->getSize(), $file->getMTime()];
        }
        ksort($out);

        return $out;
    }

    public function test_first_scan_adds_files_with_labels_and_facts(): void
    {
        $report = $this->scan();

        $this->assertSame(3, $report->added);
        $this->assertSame(3, MediaItem::count());
        $photo = MediaItem::where('relative_path', 'Images/Fous on product !.jpg')->sole();
        $this->assertSame(MediaKind::Photo, $photo->kind);
        $this->assertSame(1080, $photo->width);
        $this->assertNotNull($photo->hash);
        $video = MediaItem::where('relative_path', '5/final.mp4')->sole();
        $this->assertSame(MediaKind::FinishedVideo, $video->kind);
        $this->assertEqualsWithDelta(10.0, $video->duration, 0.1);
        $voice = MediaItem::where('relative_path', '5/voice hindi.wav')->sole();
        $this->assertSame(MediaKind::VoiceOver, $voice->kind);
        $this->assertNull($voice->width);
        $this->assertEqualsCanonicalizing(['Images', '5'], MediaGroup::pluck('name')->all());
        $this->assertStringContainsString('3 new', $report->summary());
    }

    public function test_scan_never_changes_the_source_folder(): void
    {
        $old = time() - 3600;
        foreach (File::allFiles($this->dir) as $file) {
            touch($file->getPathname(), $old);
        }
        $before = $this->snapshot();

        $this->scan();
        $this->scan();

        $this->assertSame($before, $this->snapshot());
    }

    public function test_second_scan_counts_unchanged(): void
    {
        $this->scan();

        $report = $this->scan();

        $this->assertSame(0, $report->added);
        $this->assertSame(3, $report->unchanged);
    }

    public function test_moved_file_keeps_labels(): void
    {
        $this->scan();
        $photo = MediaItem::where('relative_path', 'Images/Fous on product !.jpg')->sole();
        $photo->update(['description' => 'Raisins on the packing table']);

        rename($this->dir.'/Images/Fous on product !.jpg', $this->dir.'/5/renamed.jpg');
        $report = $this->scan();

        $this->assertSame(1, $report->moved);
        $this->assertSame(0, $report->missing);
        $this->assertSame(0, $report->added);
        $moved = $photo->fresh();
        $this->assertSame('5/renamed.jpg', $moved->relative_path);
        $this->assertSame('Raisins on the packing table', $moved->description);
        $this->assertFalse($moved->isMissing());
        $this->assertSame('5', $moved->group->name);
    }

    public function test_deleted_file_is_marked_missing_not_deleted(): void
    {
        $this->scan();
        unlink($this->dir.'/5/voice hindi.wav');

        $report = $this->scan();

        $this->assertSame(1, $report->missing);
        $this->assertTrue(MediaItem::where('relative_path', '5/voice hindi.wav')->sole()->isMissing());
    }

    public function test_unreachable_source_marks_nothing_missing(): void
    {
        $this->scan();
        rename($this->dir, $this->dir.'-away');

        try {
            $report = $this->scan();
        } finally {
            rename($this->dir.'-away', $this->dir);
        }

        $this->assertSame(0, $report->missing);
        $this->assertSame([$this->dir], $report->unreachable);
        $this->assertSame(0, MediaItem::whereNotNull('missing_since')->count());
        $this->assertStringContainsString('folder not found', $report->summary());
    }

    public function test_unreadable_file_is_skipped_and_retried(): void
    {
        $this->scan();
        $locked = $this->dir.'/Images/locked.jpg';
        $this->jpeg($locked, 400, 400);
        $handle = fopen($locked, 'r+');
        flock($handle, LOCK_EX);

        try {
            $report = $this->scan();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }

        $this->assertSame(['Images/locked.jpg'], $report->unreadable);
        $this->assertSame(0, MediaItem::where('relative_path', 'Images/locked.jpg')->count());

        $again = $this->scan();

        $this->assertSame(1, $again->added);
        $this->assertSame(400, MediaItem::where('relative_path', 'Images/locked.jpg')->sole()->width);
    }

    public function test_second_scan_while_one_runs_is_skipped(): void
    {
        File::ensureDirectoryExists(app('smm.data_dir').'/run');
        $handle = fopen(app('smm.data_dir').'/run/media-scan.lock', 'c');
        flock($handle, LOCK_EX);

        try {
            $report = $this->scan();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }

        $this->assertTrue($report->skippedBusy);
        $this->assertSame(0, MediaItem::count());
    }

    public function test_scan_command_and_daily_run_the_library_scan(): void
    {
        $this->artisan('smm:media:scan')->expectsOutputToContain('3 new')->assertSuccessful();

        $this->artisan('smm:daily')->expectsOutputToContain('Library:')->assertSuccessful();
    }
}
