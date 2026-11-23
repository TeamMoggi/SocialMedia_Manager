<?php

namespace Tests\Feature;

use App\Domain\Library\LibraryScanner;
use App\Domain\Posts\Approval;
use App\Enums\Network;
use App\Enums\PostState;
use App\Enums\PostType;
use App\Filament\Resources\Posts\Pages\CreatePost;
use App\Filament\Resources\Posts\Pages\EditPost;
use App\Filament\Resources\Posts\Pages\ListPosts;
use App\Filament\Resources\Posts\PostResource;
use App\Models\Brand;
use App\Models\ConnectedAccount;
use App\Models\MediaItem;
use App\Models\Post;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Tests\TestCase;

class ComposerTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private Brand $brand;

    private string $dir;

    private ConnectedAccount $facebook;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::where('email', User::LOCAL_OWNER_EMAIL)->firstOrFail());
        Filament::setCurrentPanel('app');
        $this->brand = Brand::where('slug', 'moggies-international')->firstOrFail();
        Filament::setTenant($this->brand);

        $this->dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'smm-composer-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->dir.'/Images');
        foreach (['one.jpg', 'two.jpg'] as $name) {
            imagejpeg(imagecreatetruecolor(1080, 1080), $this->dir.'/Images/'.$name, 85);
        }
        $this->brand->update(['library_sources' => [$this->dir]]);
        app(LibraryScanner::class)->scan($this->brand->fresh());
        $this->facebook = ConnectedAccount::create(['brand_id' => $this->brand->id, 'network' => Network::FacebookPage,
            'external_id' => '1510742765451658', 'name' => 'Moggies International', 'access_token' => 'page-token']);
        ConnectedAccount::create(['brand_id' => $this->brand->id, 'network' => Network::Instagram,
            'external_id' => '17841470732347612', 'name' => 'moggies_international', 'access_token' => 'page-token']);
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

    private function goodPost(string $caption = 'Sangli raisins, graded and packed.'): Post
    {
        $post = Post::create(['brand_id' => $this->brand->id, 'caption' => $caption, 'scheduled_at' => CarbonImmutable::now()->addDay()]);
        $post->targets()->create(['network' => Network::FacebookPage, 'type' => PostType::FacebookPhotos]);
        $post->targets()->create(['network' => Network::Instagram, 'type' => PostType::InstagramPhoto]);
        $post->media()->create(['media_item_id' => $this->item('one.jpg')->id, 'position' => 1]);

        return $post->fresh();
    }

    public function test_create_post_with_facebook_and_instagram_versions(): void
    {
        Livewire::test(CreatePost::class)
            ->fillForm([
                'caption' => 'Sangli raisins, graded and packed.',
                'scheduled_at' => '2026-12-04 12:30:00',
                'fb_on' => true, 'fb_type' => 'facebook_photos',
                'ig_on' => true, 'ig_type' => 'instagram_photo', 'ig_caption' => 'Raisins for Instagram.',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $post = Post::sole();
        $this->assertSame($this->brand->id, $post->brand_id);
        $this->assertSame('2026-12-04 07:00:00', $post->scheduled_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame(PostState::Draft, $post->state);
        $facebook = $post->target(Network::FacebookPage);
        $this->assertSame(PostType::FacebookPhotos, $facebook->type);
        $this->assertSame($this->facebook->id, $facebook->connected_account_id);
        $this->assertNull($facebook->caption);
        $this->assertSame('Raisins for Instagram.', $post->target(Network::Instagram)->caption);
    }

    public function test_media_repeater_copies_files_in_order(): void
    {
        Livewire::test(CreatePost::class)
            ->fillForm([
                'caption' => 'Two photos.',
                'ig_on' => true, 'ig_type' => 'instagram_carousel',
                'media' => [['media_item_id' => $this->item('one.jpg')->id], ['media_item_id' => $this->item('two.jpg')->id]],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $media = Post::sole()->media()->get();
        $this->assertSame([$this->item('one.jpg')->id, $this->item('two.jpg')->id], $media->pluck('media_item_id')->all());
        $this->assertSame([1, 2], $media->pluck('position')->all());
        $this->assertFileExists($media[1]->fullPath());
    }

    public function test_preview_shows_final_caption_ai_line_and_problems(): void
    {
        $this->item('one.jpg')->update(['ai_made' => true, 'shows_people' => true]);
        $post = $this->goodPost('Raisins '.implode(' ', array_map(fn ($i) => "#t{$i}", range(1, 31))));

        Livewire::test(EditPost::class, ['record' => $post->getRouteKey()])
            ->assertSee('Visuals made with AI')
            ->assertSee("Instagram's AI label will be added", escape: false)
            ->assertSee('The caption has 31 hashtags');
    }

    public function test_approve_action_disabled_while_blocking_problems(): void
    {
        $post = $this->goodPost();
        $post->media()->get()->each->delete();

        Livewire::test(EditPost::class, ['record' => $post->getRouteKey()])
            ->assertActionDisabled('approve');
    }

    public function test_approve_requires_every_checklist_item(): void
    {
        $post = $this->goodPost();
        $rules = $this->brand->approvalChecklist();

        Livewire::test(EditPost::class, ['record' => $post->getRouteKey()])
            ->callAction('approve', data: ['ticked' => array_slice($rules, 0, 4)])
            ->assertHasActionErrors(['ticked']);
        $this->assertSame(PostState::Draft, $post->fresh()->state);

        Livewire::test(EditPost::class, ['record' => $post->getRouteKey()])
            ->callAction('approve', data: ['ticked' => $rules])
            ->assertHasNoActionErrors();
        $this->assertSame(PostState::Approved, $post->fresh()->state);
    }

    public function test_editing_caption_of_approved_post_sends_it_back(): void
    {
        $post = $this->goodPost();
        app(Approval::class)->approve($post, $this->brand->approvalChecklist(), 'Owner');

        Livewire::test(EditPost::class, ['record' => $post->getRouteKey()])
            ->fillForm(['caption' => 'New words for the post.'])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified('The post changed, so it needs your approval again');

        $this->assertSame(PostState::AwaitingApproval, $post->fresh()->state);
    }

    public function test_saving_unchanged_approved_post_keeps_approval(): void
    {
        $post = $this->goodPost();
        app(Approval::class)->approve($post, $this->brand->approvalChecklist(), 'Owner');

        Livewire::test(EditPost::class, ['record' => $post->getRouteKey()])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(PostState::Approved, $post->fresh()->state);
    }

    public function test_other_brand_posts_are_not_listed_or_opened(): void
    {
        $mine = $this->goodPost();
        $other = Brand::create(['name' => 'Other business']);
        $theirs = Post::create(['brand_id' => $other->id, 'caption' => 'Not yours']);

        // Real requests, as in the browser: Filament adds its brand filter when the panel starts for a request.
        $this->get(PostResource::getUrl('index', ['tenant' => $this->brand]))
            ->assertOk()
            ->assertSee('Sangli raisins, graded and packed.')
            ->assertDontSee('Not yours');
        $this->get(PostResource::getUrl('edit', ['record' => $theirs, 'tenant' => $this->brand]))->assertNotFound();
        $this->get(PostResource::getUrl('edit', ['record' => $mine, 'tenant' => $this->brand]))->assertOk();
    }

    public function test_list_shows_brand_time_and_state(): void
    {
        $post = $this->goodPost();
        $post->update(['scheduled_at' => CarbonImmutable::parse('2026-12-04 06:00:00', 'UTC')]);

        Livewire::test(ListPosts::class)
            ->assertCanSeeTableRecords([$post])
            ->assertSee('Fri 4 Dec 2026, 11:30')
            ->assertSee('Draft');
    }
}
