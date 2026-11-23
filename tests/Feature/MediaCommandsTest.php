<?php

namespace Tests\Feature;

use App\Domain\Library\LibraryScanner;
use App\Models\Activity;
use App\Models\Brand;
use App\Models\MediaGroup;
use App\Models\MediaItem;
use App\Models\Post;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\Support\Mp4Fixture;
use Tests\TestCase;

class MediaCommandsTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private Brand $brand;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'smm-cmd-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->dir.'/Images');
        File::ensureDirectoryExists($this->dir.'/5');
        imagejpeg(imagecreatetruecolor(1080, 1350), $this->dir.'/Images/raisins close up.jpg', 85);
        Mp4Fixture::make($this->dir.'/5/final.mp4', 1080, 1920, 21.0);
        file_put_contents($this->dir.'/5/voice hindi.wav', 'RIFF-wav');
        $this->brand = Brand::where('slug', 'moggies-international')->firstOrFail();
        $this->brand->update(['library_sources' => [$this->dir]]);
        app(LibraryScanner::class)->scan($this->brand->fresh());
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        File::deleteDirectory(app('smm.data_dir').DIRECTORY_SEPARATOR.'media'.DIRECTORY_SEPARATOR.'posts');
        parent::tearDown();
    }

    private function item(string $path): MediaItem
    {
        return MediaItem::where('relative_path', $path)->sole();
    }

    private function labelsFile(array $data): string
    {
        // Inside the test folder (deleted afterwards); .json files are not indexed by the Library.
        $path = $this->dir.DIRECTORY_SEPARATOR.'labels.json';
        file_put_contents($path, json_encode($data));

        return $path;
    }

    public function test_find_matches_all_words_and_prints_full_paths(): void
    {
        $this->item('Images/raisins close up.jpg')->update(['description' => 'Golden raisins on the packing table']);

        $this->artisan('smm:media:find', ['words' => ['golden', 'packing']])
            ->expectsOutputToContain($this->item('Images/raisins close up.jpg')->fullPath())
            ->expectsOutputToContain('1080×1350')
            ->expectsOutputToContain('1 file found.')
            ->doesntExpectOutputToContain('final.mp4')
            ->assertSuccessful();
    }

    public function test_find_filters_by_kind_and_shows_fit(): void
    {
        $this->artisan('smm:media:find', ['words' => [], '--kind' => 'finished_video'])
            ->expectsOutputToContain($this->item('5/final.mp4')->fullPath())
            ->expectsOutputToContain('Fits: Reel, Story, Facebook video')
            ->doesntExpectOutputToContain('raisins close up.jpg')
            ->assertSuccessful();
    }

    public function test_find_skips_missing_unless_asked(): void
    {
        unlink($this->dir.'/Images/raisins close up.jpg');
        app(LibraryScanner::class)->scan($this->brand->fresh());

        $this->artisan('smm:media:find', ['words' => ['raisins']])
            ->expectsOutputToContain('No files match.')
            ->assertSuccessful();
        $this->artisan('smm:media:find', ['words' => ['raisins'], '--include-missing' => true])
            ->expectsOutputToContain('MISSING')
            ->assertSuccessful();
    }

    public function test_label_import_changes_only_labels_and_logs_by_claude(): void
    {
        $photo = $this->item('Images/raisins close up.jpg');
        $post = Post::create(['brand_id' => $this->brand->id, 'caption' => 'Raisins']);
        $media = $post->media()->create(['media_item_id' => $photo->id, 'position' => 1]);
        $source = $photo->fullPath();
        touch($source, time() - 3600);
        clearstatcache();
        $mtime = filemtime($source);
        $file = $this->labelsFile([
            'groups' => [['folder' => '5', 'name' => 'Customer video, June 2026']],
            'items' => [['path' => 'Images/raisins close up.jpg', 'product_range' => 'raisins', 'shows_people' => false,
                'description' => 'Golden raisins, close up']],
        ]);

        $this->artisan('smm:media:label', ['file' => $file])
            ->expectsOutputToContain('1 file and 1 folder changed')
            ->assertSuccessful();

        $photo->refresh();
        $this->assertSame('Golden raisins, close up', $photo->description);
        $this->assertFalse($photo->shows_people);
        $this->assertSame('Customer video, June 2026', MediaGroup::where('folder', '5')->sole()->name);
        $this->assertSame(['Claude', 'Claude'], Activity::orderBy('id')->pluck('by')->all());
        $this->assertSame($media->path, $media->fresh()->path);
        $this->assertSame('Raisins', $post->fresh()->caption);
        clearstatcache();
        $this->assertSame($mtime, filemtime($source));
    }

    public function test_label_import_reports_unknown_paths(): void
    {
        $file = $this->labelsFile(['items' => [
            ['path' => 'Images/not here.jpg', 'description' => 'x'],
            ['path' => 'Images/raisins close up.jpg', 'description' => 'Known file'],
        ]]);

        $this->artisan('smm:media:label', ['file' => $file])
            ->expectsOutputToContain('Not found: Images/not here.jpg')
            ->assertSuccessful();

        $this->assertSame('Known file', $this->item('Images/raisins close up.jpg')->description);
        $this->assertSame(0, MediaItem::where('relative_path', 'Images/not here.jpg')->count());
    }

    public function test_invalid_label_file_changes_nothing(): void
    {
        $file = $this->labelsFile(['items' => [
            ['path' => 'Images/raisins close up.jpg', 'description' => 'Should not be saved'],
            ['path' => '5/final.mp4', 'kind' => 'movie'],
        ]]);

        $this->artisan('smm:media:label', ['file' => $file])
            ->expectsOutputToContain("'movie' is not a kind")
            ->assertFailed();

        $this->assertNull($this->item('Images/raisins close up.jpg')->description);
        $this->assertSame(0, Activity::count());
    }
}
