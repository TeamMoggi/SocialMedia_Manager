<?php

namespace Tests\Feature;

use App\Domain\Meta\MetaGraph;
use App\Domain\Meta\PublishBlocked;
use App\Domain\Publishing\FacebookSender;
use App\Enums\Network;
use App\Enums\PostState;
use App\Enums\PostType;
use App\Enums\TargetState;
use App\Models\Brand;
use App\Models\ConnectedAccount;
use App\Models\Post;
use App\Models\PostTarget;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FacebookSenderTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private Brand $brand;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->brand = Brand::where('slug', 'moggies-international')->firstOrFail();
        ConnectedAccount::create(['brand_id' => $this->brand->id, 'network' => Network::FacebookPage, 'external_id' => '1510742765451658', 'name' => 'Moggies International', 'access_token' => 'page-tok']);
        $this->dir = app('smm.data_dir').DIRECTORY_SEPARATOR.'media'.DIRECTORY_SEPARATOR.'test-fb';
        File::ensureDirectoryExists($this->dir);
        file_put_contents($this->dir.'/a.jpg', 'jpeg-a');
        file_put_contents($this->dir.'/b.jpg', 'jpeg-b');
        file_put_contents($this->dir.'/v.mp4', 'mp4-bytes');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function sender(string $mode = 'live'): FacebookSender
    {
        return new FacebookSender(new MetaGraph('secret', $mode));
    }

    /** @param list<string> $files */
    private function target(PostType $type, array $files = [], ?string $link = null): PostTarget
    {
        $post = Post::create(['brand_id' => $this->brand->id, 'caption' => 'Fresh raisins', 'state' => PostState::Approved, 'scheduled_at' => CarbonImmutable::parse('2026-12-04 06:00:00', 'UTC')]);
        foreach ($files as $i => $file) {
            $post->media()->create([
                'position' => $i + 1, 'path' => 'test-fb/'.$file, 'mime' => str_ends_with($file, '.mp4') ? 'video/mp4' : 'image/jpeg',
                'bytes' => 5,
            ]);
        }

        return PostTarget::create(['post_id' => $post->id, 'network' => Network::FacebookPage, 'type' => $type, 'link' => $link])
            ->load('post.media');
    }

    /** @return list<Request> */
    private function sent(string $method, string $pathEnd): array
    {
        return Http::recorded()
            ->map(fn (array $pair) => $pair[0])
            ->filter(fn (Request $r): bool => $r->method() === $method && str_contains(parse_url($r->url(), PHP_URL_PATH), $pathEnd))
            ->values()
            ->all();
    }

    private function body(Request $request): array
    {
        $data = $request->data();
        if ($request->isMultipart()) {
            $data = collect($data)->mapWithKeys(fn (array $part) => [$part['name'] => $part['contents']])->all();
        }

        return $data;
    }

    public function test_text_post_is_handed_over_with_schedule_time(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['id' => '1510742765451658_111'])]);
        $t = $this->target(PostType::FacebookText);

        $this->sender()->handOver($t, $t->post->scheduled_at);

        $feed = $this->sent('POST', '/1510742765451658/feed');
        $this->assertCount(1, $feed);
        $body = $this->body($feed[0]);
        $this->assertSame('Fresh raisins', $body['message']);
        $this->assertSame('false', $body['published']);
        $this->assertSame((string) CarbonImmutable::parse('2026-12-04 06:00:00', 'UTC')->timestamp, (string) $body['scheduled_publish_time']);
        $this->assertSame('SCHEDULED', $body['unpublished_content_type']);
        $this->assertSame(TargetState::HandedToFacebook, $t->fresh()->state);
        $this->assertSame('1510742765451658_111', $t->fresh()->external_id);
    }

    public function test_link_post_carries_the_link(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['id' => 'p_1'])]);
        $t = $this->target(PostType::FacebookLink, [], 'https://moggiesinternational.com/');

        $this->sender()->handOver($t, $t->post->scheduled_at);

        $this->assertSame('https://moggiesinternational.com/', $this->body($this->sent('POST', '/feed')[0])['link']);
    }

    public function test_photo_post_uploads_each_photo_unpublished_then_one_feed_call(): void
    {
        Http::fake(function (Request $request) {
            return str_contains($request->url(), '/photos')
                ? Http::response(['id' => 'photo-'.substr(md5($request->url().microtime()), 0, 4)])
                : Http::response(['id' => '1510742765451658_222']);
        });
        $t = $this->target(PostType::FacebookPhotos, ['a.jpg', 'b.jpg']);

        $this->sender()->handOver($t, $t->post->scheduled_at);

        $uploads = $this->sent('POST', '/photos');
        $this->assertCount(2, $uploads);
        foreach ($uploads as $upload) {
            $body = $this->body($upload);
            $this->assertSame('false', $body['published']);
            $this->assertSame('true', $body['temporary']);
        }
        $feed = $this->sent('POST', '/feed');
        $this->assertCount(1, $feed);
        $body = $this->body($feed[0]);
        $ids = $t->fresh()->work['fb_media'];
        $this->assertCount(2, $ids);
        $this->assertSame(['media_fbid' => $ids[0]], json_decode($body['attached_media[0]'], true));
        $this->assertSame(['media_fbid' => $ids[1]], json_decode($body['attached_media[1]'], true));
        $this->assertSame('SCHEDULED', $body['unpublished_content_type']);
        $this->assertSame(TargetState::HandedToFacebook, $t->fresh()->state);
    }

    public function test_video_is_uploaded_with_scheduled_content_type(): void
    {
        Http::fake(['graph-video.facebook.com/*' => Http::response(['id' => '999'])]);
        $t = $this->target(PostType::FacebookVideo, ['v.mp4']);

        $this->sender()->handOver($t, $t->post->scheduled_at);

        $upload = $this->sent('POST', '/1510742765451658/videos');
        $this->assertCount(1, $upload);
        $body = $this->body($upload[0]);
        $this->assertSame('Fresh raisins', $body['description']);
        $this->assertSame('false', $body['published']);
        $this->assertSame('SCHEDULED', $body['unpublished_content_type']);
        $this->assertSame('999', $t->fresh()->external_id);
        $this->assertSame(TargetState::HandedToFacebook, $t->fresh()->state);
    }

    public function test_publish_now_has_no_schedule_fields_and_saves_permalink(): void
    {
        Http::fake([
            'graph.facebook.com/*/feed' => Http::response(['id' => '1510742765451658_333']),
            'graph.facebook.com/*/1510742765451658_333*' => Http::response(['id' => '1510742765451658_333', 'permalink_url' => 'https://www.facebook.com/1510742765451658/posts/333']),
        ]);
        $t = $this->target(PostType::FacebookText);

        $this->sender()->publishNow($t);

        $body = $this->body($this->sent('POST', '/feed')[0]);
        $this->assertArrayNotHasKey('scheduled_publish_time', $body);
        $this->assertArrayNotHasKey('published', $body);
        $fresh = $t->fresh();
        $this->assertSame(TargetState::Published, $fresh->state);
        $this->assertSame('https://www.facebook.com/1510742765451658/posts/333', $fresh->permalink);
        $this->assertNotNull($fresh->published_at);
        $this->assertSame('1510742765451658_333', $fresh->external_id);
    }

    public function test_reschedule_updates_facebooks_scheduled_time(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['success' => true])]);
        $t = $this->target(PostType::FacebookText);
        $t->update(['state' => TargetState::HandedToFacebook, 'external_id' => 'p_9']);

        $this->sender()->reschedule($t, CarbonImmutable::parse('2026-12-05 08:00:00', 'UTC'));

        $update = $this->sent('POST', '/p_9');
        $this->assertCount(1, $update);
        $this->assertSame((string) CarbonImmutable::parse('2026-12-05 08:00:00', 'UTC')->timestamp, (string) $this->body($update[0])['scheduled_publish_time']);
    }

    public function test_withdraw_deletes_scheduled_post_and_photo_ids_and_resets_the_target(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['success' => true])]);
        $t = $this->target(PostType::FacebookPhotos, ['a.jpg']);
        $t->update(['state' => TargetState::HandedToFacebook, 'external_id' => 'p_9', 'work' => ['fb_media' => ['ph1']]]);

        $this->sender()->withdraw($t);

        $this->assertCount(1, $this->sent('DELETE', '/p_9'));
        $this->assertCount(1, $this->sent('DELETE', '/ph1'));
        $fresh = $t->fresh();
        $this->assertSame(TargetState::Waiting, $fresh->state);
        $this->assertNull($fresh->external_id);
        $this->assertEmpty($fresh->work['fb_media'] ?? []);
    }

    public function test_dry_run_sends_nothing_and_throws_publish_blocked(): void
    {
        Http::fake();
        $photo = $this->target(PostType::FacebookPhotos, ['a.jpg']);
        $text = $this->target(PostType::FacebookText);

        foreach ([$photo, $text] as $t) {
            try {
                $this->sender('dry-run')->handOver($t, $t->post->scheduled_at);
                $this->fail('PublishBlocked was expected');
            } catch (PublishBlocked) {
            }
        }
        Http::assertNothingSent();
    }

    public function test_status_reads_whether_a_handed_over_post_is_public_and_its_link(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['id' => 'p_9', 'is_published' => true, 'permalink_url' => 'https://www.facebook.com/p/9'])]);
        $t = $this->target(PostType::FacebookText);
        $t->update(['state' => TargetState::HandedToFacebook, 'external_id' => 'p_9']);

        $this->assertSame(['public' => true, 'permalink' => 'https://www.facebook.com/p/9'], $this->sender('dry-run')->status($t));
        $this->assertSame('permalink_url,is_published', Http::recorded()[0][0]->data()['fields'] ?? 'permalink_url,is_published');
    }
}
