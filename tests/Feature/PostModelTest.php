<?php

namespace Tests\Feature;

use App\Domain\Library\LibraryScanner;
use App\Domain\Posts\AiLabel;
use App\Domain\Posts\CaptionBuilder;
use App\Domain\Posts\MediaRules;
use App\Enums\Network;
use App\Enums\PostState;
use App\Enums\PostType;
use App\Enums\TargetState;
use App\Filament\Resources\MediaItems\Pages\ListMediaItems;
use App\Models\Brand;
use App\Models\MediaItem;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

class PostModelTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private Brand $brand;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'smm-posts-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->dir.'/Images');
        $img = imagecreatetruecolor(1080, 1080);
        imagejpeg($img, $this->dir.'/Images/raisins.jpg', 85);
        $png = imagecreatetruecolor(400, 400);
        imagesavealpha($png, true);
        imagefill($png, 0, 0, imagecolorallocatealpha($png, 0, 0, 0, 127));
        imagepng($png, $this->dir.'/Images/ChatGPT logo.png');
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

    private function makePost(string $caption = 'Sangli raisins, graded and packed.'): Post
    {
        return Post::create(['brand_id' => $this->brand->id, 'caption' => $caption]);
    }

    private function attach(Post $post, MediaItem $item, int $position = 1): PostMedia
    {
        return $post->media()->create(['media_item_id' => $item->id, 'position' => $position]);
    }

    public function test_new_post_is_a_draft_with_waiting_targets(): void
    {
        $post = $this->makePost();
        $target = $post->targets()->create(['network' => Network::Instagram, 'type' => PostType::InstagramPhoto]);

        $this->assertSame(PostState::Draft, $post->fresh()->state);
        $this->assertSame(TargetState::Waiting, $target->fresh()->state);
        $this->assertTrue($post->target(Network::Instagram)->is($target));
        $this->assertNull($post->target(Network::FacebookPage));
    }

    public function test_attaching_copies_into_the_post_folder_and_leaves_source_untouched(): void
    {
        $source = $this->dir.'/Images/raisins.jpg';
        touch($source, time() - 3600);
        clearstatcache();
        $before = [filemtime($source), filesize($source)];
        $post = $this->makePost();

        $media = $this->attach($post, $this->item('Images/raisins.jpg'));

        $this->assertFileExists($media->fullPath());
        $this->assertStringStartsWith($post->mediaDir(), $media->fullPath());
        $this->assertSame(1080, $media->width);
        $this->assertSame('image/jpeg', $media->mime);
        clearstatcache();
        $this->assertSame($before, [filemtime($source), filesize($source)]);
    }

    public function test_png_is_saved_as_jpeg_with_white_background(): void
    {
        $media = $this->attach($this->makePost(), $this->item('Images/ChatGPT logo.png'));

        $this->assertSame('image/jpeg', $media->mime);
        $this->assertStringEndsWith('.jpg', $media->path);
        $copy = imagecreatefromjpeg($media->fullPath());
        $rgb = imagecolorat($copy, 0, 0);
        $this->assertGreaterThan(245, ($rgb >> 16) & 0xFF);
        $this->assertGreaterThan(245, $rgb & 0xFF);
    }

    public function test_post_copy_survives_source_rename(): void
    {
        $media = $this->attach($this->makePost(), $this->item('Images/raisins.jpg'));

        rename($this->dir.'/Images/raisins.jpg', $this->dir.'/Images/raisins renamed.jpg');

        $this->assertFileExists($media->fullPath());
        $this->assertTrue($media->toRuleFile()['exists']);
        $this->assertSame([], MediaRules::check(PostType::InstagramPhoto, [$media->toRuleFile()], 'Hello'));
    }

    public function test_removing_media_deletes_only_the_copy(): void
    {
        $media = $this->attach($this->makePost(), $this->item('Images/raisins.jpg'));
        $copy = $media->fullPath();

        $media->delete();

        $this->assertFileDoesNotExist($copy);
        $this->assertFileExists($this->dir.'/Images/raisins.jpg');
    }

    public function test_deleting_post_removes_its_folder(): void
    {
        $post = $this->makePost();
        $this->attach($post, $this->item('Images/raisins.jpg'));
        $folder = $post->mediaDir();

        $post->delete();

        $this->assertDirectoryDoesNotExist($folder);
        $this->assertSame(0, PostMedia::count());
    }

    public function test_facebook_caption_gets_ai_line_only_for_ai_people(): void
    {
        $post = $this->makePost('Our raisins.');
        $facebook = $post->targets()->create(['network' => Network::FacebookPage, 'type' => PostType::FacebookPhotos]);
        $instagram = $post->targets()->create(['network' => Network::Instagram, 'type' => PostType::InstagramPhoto, 'caption' => 'Our raisins on Instagram.']);
        $ai = $this->item('Images/ChatGPT logo.png');
        $this->attach($post, $ai);

        $ai->update(['ai_made' => true, 'shows_people' => false]);
        $this->assertFalse(AiLabel::applies($post->fresh()));
        $this->assertSame('Our raisins.', CaptionBuilder::for($facebook->fresh()));

        $ai->update(['shows_people' => true]);
        $this->assertTrue(AiLabel::applies($post->fresh()));
        $this->assertSame("Our raisins.\n\nVisuals made with AI", CaptionBuilder::for($facebook->fresh()));
        $this->assertSame('Our raisins on Instagram.', CaptionBuilder::for($instagram->fresh()));

        $ai->update(['ai_made' => false]);
        $this->assertFalse(AiLabel::applies($post->fresh()));
    }

    public function test_unanswered_ai_files_are_listed(): void
    {
        $post = $this->makePost();
        $ai = $this->item('Images/ChatGPT logo.png');
        $ai->update(['ai_made' => true, 'shows_people' => null]);
        $this->attach($post, $ai);

        $this->assertSame([$ai->id], array_map(fn (MediaItem $i) => $i->id, AiLabel::unanswered($post->fresh())));
    }

    public function test_story_final_caption_is_empty(): void
    {
        $post = $this->makePost('Packing day.');
        $story = $post->targets()->create(['network' => Network::Instagram, 'type' => PostType::InstagramStory]);

        $this->assertSame('', CaptionBuilder::for($story));
    }

    public function test_missing_source_cannot_be_attached(): void
    {
        $item = $this->item('Images/raisins.jpg');
        unlink($this->dir.'/Images/raisins.jpg');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("'raisins.jpg' is no longer in its folder");

        $this->attach($this->makePost(), $item);
    }

    public function test_library_counts_posts_using_a_file(): void
    {
        $this->actingAs(User::where('email', User::LOCAL_OWNER_EMAIL)->firstOrFail());
        Filament::setCurrentPanel('app');
        Filament::setTenant($this->brand);
        $item = $this->item('Images/raisins.jpg');
        $this->attach($this->makePost(), $item);

        Livewire::test(ListMediaItems::class)->assertTableColumnStateSet('post_media_count', 1, $item);
    }
}
