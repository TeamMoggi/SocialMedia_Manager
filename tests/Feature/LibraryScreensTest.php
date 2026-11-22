<?php

namespace Tests\Feature;

use App\Domain\Library\LibraryScanner;
use App\Domain\Library\Thumbnails;
use App\Enums\MediaKind;
use App\Filament\Pages\TestSend;
use App\Filament\Resources\MediaGroups\Pages\ListMediaGroups;
use App\Filament\Resources\MediaItems\Pages\ListMediaItems;
use App\Models\Activity;
use App\Models\Brand;
use App\Models\MediaGroup;
use App\Models\MediaItem;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Tests\Support\Mp4Fixture;
use Tests\TestCase;

class LibraryScreensTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private Brand $brand;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::where('email', User::LOCAL_OWNER_EMAIL)->firstOrFail());
        Filament::setCurrentPanel('app');
        $this->brand = Brand::where('slug', 'moggies-international')->firstOrFail();
        Filament::setTenant($this->brand);

        $this->dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'smm-libui-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->dir.'/CD AI');
        File::ensureDirectoryExists($this->dir.'/5');
        $img = imagecreatetruecolor(1200, 900);
        imagejpeg($img, $this->dir.'/5/raisins close up.jpg', 80);
        imagepng($img, $this->dir.'/CD AI/ChatGPT Image.png');
        Mp4Fixture::make($this->dir.'/5/final.mp4', 1080, 1920, 10.0);
        $this->brand->update(['library_sources' => [$this->dir]]);
        app(LibraryScanner::class)->scan($this->brand->fresh());
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        File::deleteDirectory(Thumbnails::dir());
        parent::tearDown();
    }

    private function item(string $path): MediaItem
    {
        return MediaItem::where('relative_path', $path)->sole();
    }

    public function test_library_lists_items_and_filters_unanswered_people(): void
    {
        $photo = $this->item('5/raisins close up.jpg');
        $ai = $this->item('CD AI/ChatGPT Image.png');
        $photo->update(['shows_people' => false]);

        Livewire::test(ListMediaItems::class)
            ->assertCanSeeTableRecords([$photo, $ai, $this->item('5/final.mp4')])
            ->filterTable('people_unanswered', true)
            ->assertCanSeeTableRecords([$ai])
            ->assertCanNotSeeTableRecords([$photo]);
    }

    public function test_library_shows_which_networks_a_file_fits(): void
    {
        Livewire::test(ListMediaItems::class)
            ->assertSee('✓ Instagram photo')
            ->assertSee('✓ Reel');
    }

    public function test_edit_labels_saves_and_logs_activity(): void
    {
        $ai = $this->item('CD AI/ChatGPT Image.png');

        Livewire::test(ListMediaItems::class)
            ->callTableAction('labels', $ai, data: [
                'kind' => MediaKind::AiImage->value,
                'product_range' => 'raisins',
                'ai_made' => true,
                'shows_people' => 1,
                'description' => 'AI presenter holding a raisin box',
            ])
            ->assertHasNoTableActionErrors();

        $ai->refresh();
        $this->assertTrue($ai->shows_people);
        $this->assertSame('AI presenter holding a raisin box', $ai->description);
        $log = Activity::where('action', 'labels changed')->sole();
        $this->assertSame('owner', $log->by);
        $this->assertTrue($log->subject->is($ai));
    }

    public function test_look_for_new_files_reports_summary(): void
    {
        $img = imagecreatetruecolor(100, 100);
        imagejpeg($img, $this->dir.'/5/new one.jpg', 80);

        Livewire::test(ListMediaItems::class)
            ->callAction('scan')
            ->assertNotified('Library: 1 new, 0 changed, 0 moved, 0 missing, 3 unchanged.');
    }

    public function test_thumb_route_serves_own_brand_only(): void
    {
        $photo = $this->item('5/raisins close up.jpg');

        $this->get(route('filament.app.library.thumb', ['tenant' => $this->brand->slug, 'item' => $photo->id]))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg');

        $other = Brand::create(['name' => 'Other business']);
        $other->users()->attach(auth()->user());
        $this->get(route('filament.app.library.thumb', ['tenant' => $other->slug, 'item' => $photo->id]))
            ->assertNotFound();
    }

    public function test_file_route_streams_video_with_ranges(): void
    {
        $video = $this->item('5/final.mp4');

        $this->get(route('filament.app.library.file', ['tenant' => $this->brand->slug, 'item' => $video->id]), ['Range' => 'bytes=0-9'])
            ->assertStatus(206);
    }

    public function test_huge_image_gets_no_thumbnail(): void
    {
        $photo = $this->item('5/raisins close up.jpg');
        $photo->update(['width' => 8000, 'height' => 6000]);

        $this->assertNull(Thumbnails::for($photo->fresh()));
        $this->assertNotNull(Thumbnails::for($this->item('CD AI/ChatGPT Image.png')));
        Livewire::test(ListMediaItems::class)->assertSuccessful();
    }

    public function test_folder_rename_is_logged(): void
    {
        $group = MediaGroup::where('name', '5')->sole();

        Livewire::test(ListMediaGroups::class)
            ->call('updateTableColumnState', 'name', (string) $group->id, 'Customer video, June 2026');

        $this->assertSame('Customer video, June 2026', $group->fresh()->name);
        $this->assertSame(1, Activity::where('action', 'folder renamed')->count());
    }

    public function test_test_send_offers_library_items(): void
    {
        $photos = TestSend::libraryChoices($this->brand, 'image');
        $videos = TestSend::libraryChoices($this->brand, 'video');

        $this->assertArrayHasKey($this->item('5/raisins close up.jpg')->id, $photos);
        $this->assertArrayHasKey($this->item('5/final.mp4')->id, $videos);
        $this->assertArrayNotHasKey($this->item('5/final.mp4')->id, $photos);
        Livewire::test(TestSend::class)->assertSee('Test send');
    }
}
