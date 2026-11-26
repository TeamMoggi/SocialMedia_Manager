<?php

namespace Tests\Feature;

use App\Domain\Meta\MetaGraph;
use App\Domain\Posts\Approval;
use App\Domain\Publishing\FacebookSender;
use App\Domain\Publishing\InstagramSender;
use App\Domain\Publishing\Notifier;
use App\Domain\Publishing\Publisher;
use App\Domain\Publishing\TargetPlanner;
use App\Domain\Relay\RelayFolder;
use App\Enums\Network;
use App\Enums\PostState;
use App\Enums\PostType;
use App\Enums\TargetState;
use App\Models\Activity;
use App\Models\Brand;
use App\Models\ConnectedAccount;
use App\Models\Post;
use App\Models\PostTarget;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** One approved post for both networks, due now: a single pass publishes both and a second pass does nothing. */
class PublishEndToEndTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_one_pass_publishes_facebook_and_instagram_and_a_second_pass_does_nothing(): void
    {
        $brand = Brand::where('slug', 'moggies-international')->firstOrFail();
        ConnectedAccount::create(['brand_id' => $brand->id, 'network' => Network::FacebookPage, 'external_id' => '15', 'name' => 'Page', 'access_token' => 'tok']);
        ConnectedAccount::create(['brand_id' => $brand->id, 'network' => Network::Instagram, 'external_id' => '17', 'name' => '@ig', 'access_token' => 'tok', 'meta' => ['page_id' => '1']]);
        $disk = Storage::fake('relay');
        $relay = new RelayFolder($disk, 'https://moggiesinternational.com/mr-test/');
        $dir = app('smm.data_dir').DIRECTORY_SEPARATOR.'media'.DIRECTORY_SEPARATOR.'test-e2e';
        File::ensureDirectoryExists($dir);
        imagejpeg(imagecreatetruecolor(1080, 1080), $dir.'/a.jpg', 80);

        $at = CarbonImmutable::parse('2026-12-04 06:00:00', 'UTC');
        $post = Post::create(['brand_id' => $brand->id, 'caption' => 'Sangli raisins, graded by hand', 'state' => PostState::Draft, 'scheduled_at' => $at]);
        $post->media()->create(['position' => 1, 'path' => 'test-e2e/a.jpg', 'mime' => 'image/jpeg', 'bytes' => 5000, 'width' => 1080, 'height' => 1080]);
        $fb = PostTarget::create(['post_id' => $post->id, 'network' => Network::FacebookPage, 'type' => PostType::FacebookPhotos]);
        $ig = PostTarget::create(['post_id' => $post->id, 'network' => Network::Instagram, 'type' => PostType::InstagramPhoto]);
        $post->update(['state' => PostState::Approved, 'approved_fingerprint' => app(Approval::class)->fingerprint($post->fresh())]);

        Http::fake(function (Request $r) {
            $path = parse_url($r->url(), PHP_URL_PATH);
            return match (true) {
                $r->method() === 'POST' && str_ends_with($path, '/15/photos') => Http::response(['id' => 'ph1']),
                $r->method() === 'POST' && str_ends_with($path, '/15/feed') => Http::response(['id' => '15_9']),
                $r->method() === 'GET' && str_ends_with($path, '/15_9') => Http::response(['permalink_url' => 'https://www.facebook.com/15/posts/9']),
                $r->method() === 'POST' && str_ends_with($path, '/17/media') => Http::response(['id' => 'c1']),
                $r->method() === 'POST' && str_ends_with($path, '/media_publish') => Http::response(['id' => 'm1']),
                $r->method() === 'GET' && str_ends_with($path, '/m1') => Http::response(['permalink' => 'https://www.instagram.com/p/ABC/']),
                $r->method() === 'GET' => Http::response(['status_code' => 'FINISHED']),
                default => Http::response([], 404),
            };
        });
        $graph = new MetaGraph('secret', 'live');
        $publisher = new Publisher(new TargetPlanner, new FacebookSender($graph), new InstagramSender($graph, $relay, fn (int $s) => null), app(Approval::class), new Notifier, true);

        $first = $publisher->run($at->addSeconds(10));
        $requestsAfterFirst = Http::recorded()->count();
        $second = $publisher->run($at->addMinutes(2));

        $this->assertSame(2, $first['done']);
        $this->assertSame([TargetState::Published, TargetState::Published], [$fb->fresh()->state, $ig->fresh()->state]);
        $this->assertSame('https://www.facebook.com/15/posts/9', $fb->fresh()->permalink);
        $this->assertSame('https://www.instagram.com/p/ABC/', $ig->fresh()->permalink);
        $this->assertSame([], $disk->files());
        $this->assertSame(2, Activity::where('action', 'published')->count());
        $this->assertSame(0, $second['done']);
        $this->assertSame($requestsAfterFirst, Http::recorded()->count(), 'the second pass sends nothing');

        File::deleteDirectory($dir);
    }
}
