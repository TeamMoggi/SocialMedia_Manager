<?php

namespace Tests\Feature;

use App\Domain\Meta\MetaGraph;
use App\Domain\Meta\PublishBlocked;
use App\Domain\Publishing\InstagramSender;
use App\Domain\Relay\RelayFolder;
use App\Enums\Network;
use App\Enums\PostState;
use App\Enums\PostType;
use App\Enums\TargetState;
use App\Models\Brand;
use App\Models\ConnectedAccount;
use App\Enums\MediaKind;
use App\Models\MediaGroup;
use App\Models\MediaItem;
use App\Models\Post;
use App\Models\PostTarget;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class InstagramSenderTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const IG = '17841470732347612';

    private Brand $brand;

    private Filesystem $disk;

    private RelayFolder $relay;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->brand = Brand::where('slug', 'moggies-international')->firstOrFail();
        ConnectedAccount::create(['brand_id' => $this->brand->id, 'network' => Network::Instagram, 'external_id' => self::IG, 'name' => '@moggies_international', 'access_token' => 'tok', 'meta' => ['page_id' => '1']]);
        $this->disk = Storage::fake('relay');
        $this->relay = new RelayFolder($this->disk, 'https://moggiesinternational.com/mr-test/');
        $this->dir = app('smm.data_dir').DIRECTORY_SEPARATOR.'media'.DIRECTORY_SEPARATOR.'test-ig';
        File::ensureDirectoryExists($this->dir);
        foreach (['a.jpg', 'b.jpg', 'v.mp4'] as $name) {
            file_put_contents($this->dir.'/'.$name, 'bytes-'.$name);
        }
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function sender(string $mode = 'live'): InstagramSender
    {
        return new InstagramSender(new MetaGraph('secret', $mode), $this->relay, fn (int $s) => null);
    }

    /** @param list<string> $files */
    private function target(PostType $type, array $files, bool $ai = false, ?string $caption = 'Raisins from Sangli #raisins'): PostTarget
    {
        $post = Post::create(['brand_id' => $this->brand->id, 'caption' => $caption, 'state' => PostState::Approved, 'scheduled_at' => CarbonImmutable::parse('2026-12-04 06:00:00', 'UTC')]);
        foreach ($files as $i => $file) {
            $item = null;
            if ($ai) {
                $group = MediaGroup::firstOrCreate(
                    ['brand_id' => $this->brand->id, 'source_root' => 'D:/src', 'folder' => ''],
                    ['name' => 'Top folder'],
                );
                $item = MediaItem::create([
                    'brand_id' => $this->brand->id, 'media_group_id' => $group->id, 'source_root' => 'D:/src',
                    'relative_path' => 'x/'.$file.uniqid(), 'kind' => MediaKind::Photo, 'bytes' => 10, 'mtime' => 1,
                    'ai_made' => true, 'shows_people' => true,
                ]);
            }
            $media = $post->media()->create([
                'position' => $i + 1, 'path' => 'test-ig/'.$file, 'mime' => str_ends_with($file, '.mp4') ? 'video/mp4' : 'image/jpeg', 'bytes' => 5,
            ]);
            if ($item) {
                $media->updateQuietly(['media_item_id' => $item->id]);
            }
        }

        return PostTarget::create(['post_id' => $post->id, 'network' => Network::Instagram, 'type' => $type])->load('post.media');
    }

    /** A stand-in for Instagram. $published = media list returned by GET /{ig}/media. */
    private function fakeInstagram(array $published = [], bool $publishFails = false, string $status = 'FINISHED', bool $publishUnreachable = false): void
    {
        $counter = 0;
        Http::fake(function (Request $request) use (&$counter, $published, $publishFails, $status, $publishUnreachable) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            if ($request->method() === 'POST' && str_ends_with($path, '/'.self::IG.'/media')) {
                return Http::response(['id' => 'c'.++$counter]);
            }
            if ($request->method() === 'POST' && str_ends_with($path, '/media_publish')) {
                if ($publishUnreachable) {
                    throw new \Illuminate\Http\Client\ConnectionException('timeout');
                }

                return $publishFails
                    ? Http::response(['error' => ['code' => 9007, 'message' => 'Media ID is not available']], 400)
                    : Http::response(['id' => 'm1']);
            }
            if ($request->method() === 'GET' && str_ends_with($path, '/'.self::IG.'/media')) {
                return Http::response(['data' => $published]);
            }
            if ($request->method() === 'GET' && str_ends_with($path, '/m1')) {
                return Http::response(['id' => 'm1', 'permalink' => 'https://www.instagram.com/p/ABC/']);
            }
            if ($request->method() === 'GET') {
                return Http::response(['status_code' => $status, 'status' => 'Error: bad video']);
            }

            return Http::response([], 404);
        });
    }

    /** @return list<array<string, mixed>> */
    private function created(): array
    {
        return Http::recorded()
            ->map(fn (array $pair) => $pair[0])
            ->filter(fn (Request $r): bool => $r->method() === 'POST' && str_ends_with(parse_url($r->url(), PHP_URL_PATH), '/'.self::IG.'/media'))
            ->map(fn (Request $r): array => $r->data())
            ->values()
            ->all();
    }

    public function test_photo_prepare_uploads_creates_container_and_waits_for_finished(): void
    {
        $this->fakeInstagram();
        $t = $this->target(PostType::InstagramPhoto, ['a.jpg']);

        $this->sender()->prepare($t);

        $created = $this->created();
        $this->assertCount(1, $created);
        $this->assertStringStartsWith('https://moggiesinternational.com/mr-test/', $created[0]['image_url']);
        $this->assertSame('Raisins from Sangli #raisins', $created[0]['caption']);
        $fresh = $t->fresh();
        $this->assertSame(TargetState::Queued, $fresh->state);
        $this->assertSame('c1', $fresh->container_id);
        $this->assertCount(1, $fresh->work['relay']);
        $this->assertCount(1, $this->disk->files());
    }

    public function test_reel_uses_reels_media_type_and_video_url(): void
    {
        $this->fakeInstagram();
        $this->sender()->prepare($this->target(PostType::InstagramReel, ['v.mp4']));

        $reel = $this->created()[0];
        $this->assertSame('REELS', $reel['media_type']);
        $this->assertArrayHasKey('video_url', $reel);
    }

    public function test_story_uses_stories_media_type_and_has_no_caption(): void
    {
        $this->fakeInstagram();
        $this->sender()->prepare($this->target(PostType::InstagramStory, ['a.jpg']));

        $story = $this->created()[0];
        $this->assertSame('STORIES', $story['media_type']);
        $this->assertArrayHasKey('image_url', $story);
        $this->assertArrayNotHasKey('caption', $story);
    }

    public function test_carousel_makes_children_first_then_a_parent_listing_them(): void
    {
        $this->fakeInstagram();
        $carousel = $this->target(PostType::InstagramCarousel, ['a.jpg', 'v.mp4', 'b.jpg']);
        $this->sender()->prepare($carousel);
        $created = $this->created();
        $this->assertCount(4, $created);
        $this->assertSame('true', (string) $created[0]['is_carousel_item']);
        $this->assertSame('VIDEO', $created[1]['media_type']);
        $this->assertSame('CAROUSEL', $created[3]['media_type']);
        $this->assertSame('c1,c2,c3', $created[3]['children']);
        $fresh = $carousel->fresh();
        $this->assertSame('c4', $fresh->container_id);
        $this->assertSame(['c1', 'c2', 'c3'], $fresh->work['children']);
    }

    public function test_ai_label_is_on_the_parent_container_only(): void
    {
        $this->fakeInstagram();
        $this->sender()->prepare($this->target(PostType::InstagramCarousel, ['a.jpg', 'b.jpg'], ai: true));
        $created = $this->created();

        $this->assertArrayNotHasKey('is_ai_generated', $created[0]);
        $this->assertArrayNotHasKey('is_ai_generated', $created[1]);
        $this->assertSame('true', (string) $created[2]['is_ai_generated']);
    }

    public function test_ai_label_is_on_a_single_photo_container(): void
    {
        $this->fakeInstagram();
        $this->sender()->prepare($this->target(PostType::InstagramPhoto, ['a.jpg'], ai: true));

        $this->assertSame('true', (string) $this->created()[0]['is_ai_generated']);
    }

    public function test_publish_saves_started_time_before_the_publish_call_and_the_permalink_after(): void
    {
        $this->fakeInstagram();
        $t = $this->target(PostType::InstagramPhoto, ['a.jpg']);
        $this->sender()->prepare($t);

        $this->sender()->publish($t->fresh());

        $fresh = $t->fresh();
        $this->assertSame(TargetState::Published, $fresh->state);
        $this->assertSame('m1', $fresh->external_id);
        $this->assertSame('https://www.instagram.com/p/ABC/', $fresh->permalink);
        $this->assertNotNull($fresh->published_at);
        $this->assertNotEmpty($fresh->work['publish_started_at']);
        $this->assertSame([], $this->disk->files());
        $this->assertEmpty($fresh->work['relay'] ?? []);
    }

    public function test_publish_after_a_crash_adopts_the_existing_media_and_does_not_publish_again(): void
    {
        $t = $this->target(PostType::InstagramPhoto, ['a.jpg']);
        $started = CarbonImmutable::now()->subMinutes(2);
        $t->update(['state' => TargetState::Queued, 'container_id' => 'c1', 'work' => ['relay' => [], 'publish_started_at' => $started->toIso8601String()]]);
        $this->fakeInstagram([['id' => 'm77', 'timestamp' => $started->addSeconds(5)->format('Y-m-d\TH:i:sO'), 'permalink' => 'https://www.instagram.com/p/XYZ/', 'caption' => 'Raisins from Sangli #raisins']]);

        $this->sender()->publish($t->fresh());

        $fresh = $t->fresh();
        $this->assertSame('m77', $fresh->external_id);
        $this->assertSame('https://www.instagram.com/p/XYZ/', $fresh->permalink);
        $this->assertSame(TargetState::Published, $fresh->state);
        $publishCalls = Http::recorded()->filter(fn ($p) => str_ends_with(parse_url($p[0]->url(), PHP_URL_PATH), '/media_publish'));
        $this->assertCount(0, $publishCalls);
    }

    public function test_unmatched_started_publish_fails_with_check_instagram_message(): void
    {
        $t = $this->target(PostType::InstagramPhoto, ['a.jpg']);
        $t->update(['state' => TargetState::Queued, 'container_id' => 'c1', 'work' => ['publish_started_at' => CarbonImmutable::now()->subMinutes(2)->toIso8601String()]]);
        $this->fakeInstagram([]);

        try {
            $this->sender()->publish($t->fresh());
            $this->fail('A RuntimeException was expected');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('check Instagram before trying again', $e->getMessage());
        }
        $publishCalls = Http::recorded()->filter(fn ($p) => str_ends_with(parse_url($p[0]->url(), PHP_URL_PATH), '/media_publish'));
        $this->assertCount(0, $publishCalls);
    }

    public function test_a_clear_meta_refusal_leaves_no_started_time_so_a_retry_can_publish(): void
    {
        $this->fakeInstagram(publishFails: true);
        $t = $this->target(PostType::InstagramPhoto, ['a.jpg']);
        $this->sender()->prepare($t);

        try {
            $this->sender()->publish($t->fresh());
            $this->fail('A MetaError was expected');
        } catch (\App\Domain\Meta\MetaError) {
        }

        $this->assertArrayNotHasKey('publish_started_at', $t->fresh()->work);
    }

    public function test_a_lost_connection_during_publish_keeps_the_started_time(): void
    {
        $this->fakeInstagram(publishUnreachable: true);
        $t = $this->target(PostType::InstagramPhoto, ['a.jpg']);
        $this->sender()->prepare($t);

        try {
            $this->sender()->publish($t->fresh());
            $this->fail('A MetaError was expected');
        } catch (\App\Domain\Meta\MetaError) {
        }

        $this->assertNotEmpty($t->fresh()->work['publish_started_at']);
    }

    public function test_relay_files_are_deleted_after_a_failed_prepare(): void
    {
        $this->fakeInstagram(status: 'ERROR');
        $t = $this->target(PostType::InstagramPhoto, ['a.jpg']);
        try {
            $this->sender()->prepare($t);
            $this->fail('A RuntimeException was expected');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Instagram could not prepare it', $e->getMessage());
        }

        $this->assertSame([], $this->disk->files());
        $this->assertEmpty($t->fresh()->work['relay'] ?? []);
    }

    public function test_abandon_deletes_relay_files_and_resets_the_target(): void
    {
        $this->fakeInstagram();
        $t2 = $this->target(PostType::InstagramPhoto, ['a.jpg']);
        $this->sender()->prepare($t2);
        $this->assertCount(1, $this->disk->files());

        $this->sender()->abandon($t2->fresh());

        $this->assertSame([], $this->disk->files());
        $fresh = $t2->fresh();
        $this->assertSame(TargetState::Waiting, $fresh->state);
        $this->assertNull($fresh->container_id);
    }

    public function test_dry_run_sends_nothing(): void
    {
        Http::fake();
        $t = $this->target(PostType::InstagramPhoto, ['a.jpg']);

        $this->expectException(PublishBlocked::class);
        try {
            $this->sender('dry-run')->prepare($t);
        } finally {
            Http::assertNothingSent();
            $this->assertSame([], $this->disk->files());
        }
    }
}
