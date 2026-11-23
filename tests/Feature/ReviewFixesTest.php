<?php

namespace Tests\Feature;

use App\Domain\Library\LabelImport;
use App\Domain\Library\LibraryScanner;
use App\Domain\Posts\Approval;
use App\Enums\Network;
use App\Enums\PostState;
use App\Enums\PostType;
use App\Filament\Pages\Tenancy\EditBrand;
use App\Filament\Resources\MediaItems\MediaItemResource;
use App\Filament\Resources\Posts\Pages\CreatePost;
use App\Filament\Resources\Posts\Pages\EditPost;
use App\Models\Activity;
use App\Models\Brand;
use App\Models\ConnectedAccount;
use App\Models\MediaItem;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/** The fixes from the final whole-branch review of SM-009 (22 Nov 2026), each reproduced first. */
class ReviewFixesTest extends TestCase
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
        $this->dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'smm-fixes-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->dir.'/Images');
        imagejpeg(imagecreatetruecolor(1080, 1080), $this->dir.'/Images/one.jpg', 85);
        imagejpeg(imagecreatetruecolor(1080, 1080), $this->dir.'/Images/two.jpg', 85);
        imagepng(imagecreatetruecolor(400, 400), $this->dir.'/Images/ChatGPT person.png');
        imagepng(imagecreatetruecolor(300, 300), $this->dir.'/Images/Gemini_Generated_Image_x1.png');
        $this->brand->update(['library_sources' => [$this->dir]]);
        app(LibraryScanner::class)->scan($this->brand->fresh());
        foreach ([[Network::FacebookPage, '1510742765451658'], [Network::Instagram, '17841470732347612']] as [$network, $id]) {
            ConnectedAccount::create(['brand_id' => $this->brand->id, 'network' => $network, 'external_id' => $id,
                'name' => 'Moggies International', 'access_token' => 'page-token']);
        }
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        File::deleteDirectory(app('smm.data_dir').DIRECTORY_SEPARATOR.'media'.DIRECTORY_SEPARATOR.'posts');
        parent::tearDown();
    }

    private function item(string $name): MediaItem
    {
        return MediaItem::where('relative_path', 'Images/'.$name)->sole();
    }

    /** An approved post: Facebook photo(s) + Instagram carousel or photo, tomorrow. */
    private function approvedPost(array $names = ['one.jpg']): Post
    {
        $post = Post::create(['brand_id' => $this->brand->id, 'caption' => 'Raisins.', 'scheduled_at' => CarbonImmutable::now()->addDay()]);
        $post->targets()->create(['network' => Network::FacebookPage, 'type' => PostType::FacebookPhotos]);
        $post->targets()->create(['network' => Network::Instagram, 'type' => count($names) > 1 ? PostType::InstagramCarousel : PostType::InstagramPhoto]);
        foreach ($names as $i => $name) {
            $post->media()->create(['media_item_id' => $this->item($name)->id, 'position' => $i + 1]);
        }
        app(Approval::class)->approve($post->fresh(), $this->brand->approvalChecklist(), 'Owner');

        return $post->fresh();
    }

    private function labels(MediaItem $item, ?int $people): array
    {
        return ['kind' => $item->kind->value, 'product_range' => null, 'ai_made' => $item->ai_made, 'shows_people' => $people, 'description' => null];
    }

    // C1 (a): a Library label change must not leave an approved post whose final content changed.

    public function test_label_change_after_approval_reopens_the_post(): void
    {
        $ai = $this->item('ChatGPT person.png');
        $ai->update(['ai_made' => true, 'shows_people' => true]);
        $post = $this->approvedPost(['ChatGPT person.png']);

        MediaItemResource::saveLabels($ai->fresh(), $this->labels($ai->fresh(), 0));

        $this->assertSame(PostState::AwaitingApproval, $post->fresh()->state);
        $this->assertStringContainsString('ChatGPT person.png', Activity::where('action', 'approval withdrawn')->sole()->details['reason']);
    }

    public function test_unanswered_people_after_approval_reopens_the_post(): void
    {
        $ai = $this->item('ChatGPT person.png');
        $ai->update(['ai_made' => true, 'shows_people' => false]);
        $post = $this->approvedPost(['ChatGPT person.png']);

        MediaItemResource::saveLabels($ai->fresh(), $this->labels($ai->fresh(), null));

        $this->assertSame(PostState::AwaitingApproval, $post->fresh()->state);
    }

    public function test_label_import_reopens_posts_using_the_file(): void
    {
        $ai = $this->item('ChatGPT person.png');
        $ai->update(['ai_made' => true, 'shows_people' => true]);
        $post = $this->approvedPost(['ChatGPT person.png']);

        LabelImport::apply($this->brand, ['items' => [['path' => 'Images/ChatGPT person.png', 'shows_people' => false]]]);

        $this->assertSame(PostState::AwaitingApproval, $post->fresh()->state);
    }

    public function test_description_change_keeps_approval(): void
    {
        $post = $this->approvedPost();
        $item = $this->item('one.jpg');

        MediaItemResource::saveLabels($item, ['description' => 'Golden raisins'] + $this->labels($item, null));

        $this->assertSame(PostState::Approved, $post->fresh()->state);
    }

    public function test_still_approved_gate_for_publishing(): void
    {
        $approval = app(Approval::class);
        $post = $this->approvedPost();
        $this->assertTrue($approval->stillApproved($post));

        // Its time passing is no reason to stop it (part 3 publishes at the time).
        $post->update(['scheduled_at' => CarbonImmutable::now()->subMinute()]);
        $this->assertTrue($approval->stillApproved($post->fresh()));

        // A change made behind the approval's back stops it.
        $post->update(['caption' => 'Changed without approval']);
        $this->assertFalse($approval->stillApproved($post->fresh()));
    }

    // C1 (b): a save that fails half-way changes nothing.

    public function test_failed_copy_during_save_changes_nothing(): void
    {
        $post = $this->approvedPost(['one.jpg', 'two.jpg']);
        $copies = $post->media()->get()->map(fn (PostMedia $m) => $m->fullPath())->all();
        // A PNG the Library read as fine, but whose file is now broken.
        file_put_contents($this->dir.'/Images/ChatGPT person.png', 'broken');
        $broken = $this->item('ChatGPT person.png');
        $keys = array_keys(Livewire::test(EditPost::class, ['record' => $post->getRouteKey()])->get('data.media'));

        Livewire::test(EditPost::class, ['record' => $post->getRouteKey()])
            ->set('data.media', [$keys[1] => ['media_item_id' => $this->item('two.jpg')->id], 'new' => ['media_item_id' => $broken->id]])
            ->call('save');

        $post->refresh();
        $this->assertSame(PostState::Approved, $post->state);
        $this->assertSame([$this->item('one.jpg')->id, $this->item('two.jpg')->id], $post->media()->pluck('media_item_id')->all());
        foreach ($copies as $copy) {
            $this->assertFileExists($copy);
        }
        $this->assertTrue(app(Approval::class)->stillApproved($post));
    }

    public function test_copy_refuses_a_picture_too_large_to_convert(): void
    {
        config(['smm.limits.max_convert_pixels' => 100]);
        $post = Post::create(['brand_id' => $this->brand->id, 'caption' => 'x']);

        try {
            $post->media()->create(['media_item_id' => $this->item('ChatGPT person.png')->id, 'position' => 1]);
            $this->fail('The copy should be refused');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('too large', collect($e->errors())->flatten()->first());
        }
        $this->assertSame(0, PostMedia::count());
    }

    // I1: a post whose original was deleted can still be saved.

    public function test_post_with_deleted_original_can_still_be_saved(): void
    {
        $post = $this->approvedPost();
        $copy = $post->media()->first()->fullPath();
        unlink($this->dir.'/Images/one.jpg');
        app(LibraryScanner::class)->scan($this->brand->fresh());

        Livewire::test(EditPost::class, ['record' => $post->getRouteKey()])
            ->fillForm(['caption' => 'Raisins, new words.'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Raisins, new words.', $post->fresh()->caption);
        $this->assertFileExists($copy);
    }

    // I2: a huge PNG is refused with a plain message, before any memory is used.

    public function test_huge_png_is_refused_in_the_form(): void
    {
        $this->item('ChatGPT person.png')->update(['width' => 8000, 'height' => 6000]);

        Livewire::test(CreatePost::class)
            ->fillForm(['caption' => 'x', 'media' => [['media_item_id' => $this->item('ChatGPT person.png')->id]]])
            ->call('create')
            ->assertHasFormErrors();

        $this->assertSame(0, Post::count());
    }

    // I3: the preview shows the post's own copy.

    public function test_preview_shows_the_post_copy(): void
    {
        $post = $this->approvedPost();
        $media = $post->media()->first();
        $url = route('filament.app.library.post-media', ['tenant' => $this->brand->slug, 'media' => $media->id]);

        Livewire::test(EditPost::class, ['record' => $post->getRouteKey()])->assertSee($url, escape: false);
        $this->get($url)->assertOk();

        $other = Brand::create(['name' => 'Other business']);
        $other->users()->attach(auth()->user());
        $this->get(route('filament.app.library.post-media', ['tenant' => $other->slug, 'media' => $media->id]))->assertNotFound();
    }

    // I4: a time change in the form goes through the same rules as the calendar.

    public function test_form_time_change_refuses_the_past_and_is_logged(): void
    {
        $post = $this->approvedPost();
        $zone = $this->brand->timezone;

        Livewire::test(EditPost::class, ['record' => $post->getRouteKey()])
            ->fillForm(['scheduled_at' => CarbonImmutable::now($zone)->subDays(2)->format('Y-m-d H:i:s')])
            ->call('save')
            ->assertHasFormErrors(['scheduled_at']);

        Livewire::test(EditPost::class, ['record' => $post->getRouteKey()])
            ->fillForm(['scheduled_at' => CarbonImmutable::now($zone)->addDays(3)->setTime(12, 30)->format('Y-m-d H:i:s')])
            ->call('save')
            ->assertHasNoFormErrors();

        $post->refresh();
        $this->assertSame(PostState::Approved, $post->state);
        $this->assertSame('12:30', $post->scheduled_at->setTimezone($zone)->format('H:i'));
        $this->assertSame(1, Activity::where('action', 'time changed')->count());
    }

    // I5: the week view shows the whole day.

    public function test_calendar_shows_the_whole_day(): void
    {
        $view = file_get_contents(resource_path('views/filament/pages/calendar.blade.php'));

        $this->assertStringContainsString("slotMinTime: '00:00:00'", $view);
        $this->assertStringContainsString("scrollTime: '07:00:00'", $view);
    }

    // M1 (re-graded): words with "_" are found.

    public function test_find_words_with_underscores(): void
    {
        $this->artisan('smm:media:find', ['words' => ['Gemini_Generated']])
            ->expectsOutputToContain('Gemini_Generated_Image_x1.png')
            ->assertSuccessful();
    }

    // M5 (re-graded): a parent of the app's data folder is not a Library folder.

    public function test_brand_settings_refuse_a_parent_of_the_data_folder(): void
    {
        $page = Livewire::test(EditBrand::class)
            ->fillForm(['library_sources' => [['path' => dirname(app('smm.data_dir'))]]])
            ->call('save')
            ->assertHasFormErrors(['library_sources.0.path']);

        $this->assertStringContainsString("app's own", $page->errors()->first('data.library_sources.0.path'));
    }
}
