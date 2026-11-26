<?php

namespace Tests\Feature;

use App\Domain\Meta\MetaGraph;
use App\Domain\Posts\Approval;
use App\Domain\Posts\ApprovalRefused;
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
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PublisherTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const PAGE = '1510742765451658';

    private const IG = '17841470732347612';

    private Brand $brand;

    private Filesystem $disk;

    private RelayFolder $relay;

    private string $dir;

    /** What the stand-in for Meta does; a test changes these between passes. */
    private array $meta = ['container' => 'ok', 'publish' => 'ok', 'fb_published' => true];

    /** @var list<array{string, string}> kind and text of every notice */
    private array $notices = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->brand = Brand::where('slug', 'moggies-international')->firstOrFail();
        ConnectedAccount::create(['brand_id' => $this->brand->id, 'network' => Network::FacebookPage, 'external_id' => self::PAGE, 'name' => 'Page', 'access_token' => 'tok']);
        ConnectedAccount::create(['brand_id' => $this->brand->id, 'network' => Network::Instagram, 'external_id' => self::IG, 'name' => '@ig', 'access_token' => 'tok', 'meta' => ['page_id' => '1']]);
        $this->disk = Storage::fake('relay');
        $this->relay = new RelayFolder($this->disk, 'https://moggiesinternational.com/mr-test/');
        $this->dir = app('smm.data_dir').DIRECTORY_SEPARATOR.'media'.DIRECTORY_SEPARATOR.'test-pub';
        File::ensureDirectoryExists($this->dir);
        $img = imagecreatetruecolor(1080, 1080);
        imagejpeg($img, $this->dir.'/a.jpg', 80);
        $this->fakeMeta();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function fakeMeta(): void
    {
        $counter = 0;
        Http::fake(function (Request $request) use (&$counter) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            $get = $request->method() === 'GET';
            if ($request->method() === 'POST' && str_ends_with($path, '/'.self::IG.'/media')) {
                return match ($this->meta['container']) {
                    'transient' => Http::response(['error' => ['code' => 2, 'message' => 'Service temporarily unavailable']], 500),
                    'permanent' => Http::response(['error' => ['code' => 100, 'message' => 'Invalid parameter']], 400),
                    default => Http::response(['id' => 'c'.++$counter]),
                };
            }
            if ($request->method() === 'POST' && str_ends_with($path, '/media_publish')) {
                return $this->meta['publish'] === 'ok'
                    ? Http::response(['id' => 'm1'])
                    : Http::response(['error' => ['code' => 9007, 'message' => 'Media ID is not available']], 400);
            }
            if ($request->method() === 'POST' && str_ends_with($path, '/'.self::PAGE.'/feed')) {
                return Http::response(['id' => self::PAGE.'_77']);
            }
            if ($get && str_ends_with($path, '/m1')) {
                return Http::response(['id' => 'm1', 'permalink' => 'https://www.instagram.com/p/ABC/']);
            }
            if ($get && str_contains($path, self::PAGE.'_77')) {
                return Http::response(['id' => self::PAGE.'_77', 'is_published' => $this->meta['fb_published'], 'permalink_url' => 'https://www.facebook.com/p/77']);
            }
            if ($get) {
                return Http::response(['status_code' => 'FINISHED']);
            }
            if ($request->method() === 'DELETE') {
                return Http::response(['success' => true]);
            }

            return Http::response([], 404);
        });
    }

    private function publisher(bool $live = true): Publisher
    {
        $graph = new MetaGraph('secret', $live ? 'live' : 'dry-run');
        $notifier = new class($this) extends Notifier
        {
            public function __construct(private readonly PublisherTest $test) {}

            public function notify(string $kind, PostTarget $target, string $text): void
            {
                $this->test->noticeSeen($kind, $text);
            }
        };

        return new Publisher(new TargetPlanner, new FacebookSender($graph), new InstagramSender($graph, $this->relay, fn (int $s) => null), app(Approval::class), $notifier, $live);
    }

    public function noticeSeen(string $kind, string $text): void
    {
        $this->notices[] = [$kind, $text];
    }

    private function at(string $utc): CarbonImmutable
    {
        return CarbonImmutable::parse($utc, 'UTC');
    }

    /** An approved post with one target (an Instagram photo, a Facebook text post or a Facebook text with a state). */
    private function approved(PostType $type, string $scheduledUtc, array $target = []): PostTarget
    {
        $post = Post::create(['brand_id' => $this->brand->id, 'caption' => 'Raisins from Sangli', 'state' => PostState::Draft, 'scheduled_at' => $this->at($scheduledUtc)]);
        if ($type === PostType::InstagramPhoto) {
            $post->media()->create(['position' => 1, 'path' => 'test-pub/a.jpg', 'mime' => 'image/jpeg', 'bytes' => 5000, 'width' => 1080, 'height' => 1080]);
        }
        $t = PostTarget::create(array_merge(['post_id' => $post->id, 'network' => $type->network(), 'type' => $type], $target));
        $post->update(['state' => PostState::Approved, 'approved_fingerprint' => app(Approval::class)->fingerprint($post->fresh())]);

        return $t->fresh();
    }

    private function sentTo(string $method, string $pathEnd): int
    {
        return Http::recorded()->filter(fn ($p) => $p[0]->method() === $method && str_ends_with(parse_url($p[0]->url(), PHP_URL_PATH), $pathEnd))->count();
    }

    public function test_due_instagram_photo_is_prepared_then_published_in_one_pass(): void
    {
        $t = $this->approved(PostType::InstagramPhoto, '2026-12-04 06:00:00');

        $result = $this->publisher()->run($this->at('2026-12-04 06:00:05'));

        $fresh = $t->fresh();
        $this->assertSame(TargetState::Published, $fresh->state);
        $this->assertSame('https://www.instagram.com/p/ABC/', $fresh->permalink);
        $this->assertSame(1, $result['done']);
        $this->assertSame([], $this->disk->files());
        $this->assertSame([['published', 'Instagram: published']], array_map(fn ($n) => [$n[0], substr($n[1], 0, 20)], $this->notices));
        $this->assertTrue(Activity::where('action', 'published')->exists());
    }

    public function test_instagram_is_prepared_ahead_and_published_at_the_time(): void
    {
        $t = $this->approved(PostType::InstagramPhoto, '2026-12-04 06:00:00');

        $this->publisher()->run($this->at('2026-12-04 05:46:00'));
        $this->assertSame(TargetState::Queued, $t->fresh()->state);
        $this->assertSame(0, $this->sentTo('POST', '/media_publish'));

        $this->publisher()->run($this->at('2026-12-04 05:58:00'));
        $this->assertSame(TargetState::Queued, $t->fresh()->state);

        $this->publisher()->run($this->at('2026-12-04 06:00:30'));
        $this->assertSame(TargetState::Published, $t->fresh()->state);
        $this->assertSame(1, $this->sentTo('POST', '/media_publish'));
    }

    public function test_facebook_post_is_handed_over_then_confirmed_after_its_time(): void
    {
        $t = $this->approved(PostType::FacebookText, '2026-12-04 06:00:00');

        $this->publisher()->run($this->at('2026-12-03 06:00:00'));
        $this->assertSame(TargetState::HandedToFacebook, $t->fresh()->state);

        $this->publisher()->run($this->at('2026-12-04 06:10:00'));

        $fresh = $t->fresh();
        $this->assertSame(TargetState::Published, $fresh->state);
        $this->assertSame('https://www.facebook.com/p/77', $fresh->permalink);
    }

    public function test_a_facebook_post_that_is_still_not_public_an_hour_late_is_reported(): void
    {
        $this->meta['fb_published'] = false;
        $t = $this->approved(PostType::FacebookText, '2026-12-04 06:00:00');
        $this->publisher()->run($this->at('2026-12-03 06:00:00'));

        $this->publisher()->run($this->at('2026-12-04 06:10:00'));
        $this->assertSame(TargetState::HandedToFacebook, $t->fresh()->state);

        $this->publisher()->run($this->at('2026-12-04 07:05:00'));
        $this->assertSame(TargetState::Failed, $t->fresh()->state);
        $this->assertSame('failed', $this->notices[0][0]);
    }

    public function test_unapproved_or_changed_post_is_not_sent_and_facebook_copy_is_withdrawn(): void
    {
        $t = $this->approved(PostType::FacebookText, '2026-12-04 06:00:00');
        $this->publisher()->run($this->at('2026-12-03 06:00:00'));
        $this->assertSame(TargetState::HandedToFacebook, $t->fresh()->state);

        $t->post->update(['caption' => 'Changed after approval']);
        $this->publisher()->run($this->at('2026-12-03 07:00:00'));

        $this->assertSame(1, $this->sentTo('DELETE', '/'.self::PAGE.'_77'));
        $this->assertSame(TargetState::Waiting, $t->fresh()->state);
        $this->assertSame(PostState::AwaitingApproval, $t->post->fresh()->state);
        $this->assertTrue(Activity::where('action', 'like', 'approval changed%')->exists());
    }

    public function test_transient_error_schedules_retries_at_1_4_10_minutes_then_goes_late(): void
    {
        $this->meta['container'] = 'transient';
        $t = $this->approved(PostType::InstagramPhoto, '2026-12-04 06:00:00');

        $this->publisher()->run($this->at('2026-12-04 06:00:00'));
        $this->assertSame([1, '2026-12-04 06:01:00'], [$t->fresh()->attempts, $t->fresh()->next_attempt_at->format('Y-m-d H:i:s')]);

        $this->publisher()->run($this->at('2026-12-04 06:00:30'));
        $this->assertSame(1, $t->fresh()->attempts);

        $this->publisher()->run($this->at('2026-12-04 06:01:00'));
        $this->assertSame([2, '2026-12-04 06:05:00'], [$t->fresh()->attempts, $t->fresh()->next_attempt_at->format('Y-m-d H:i:s')]);

        $this->publisher()->run($this->at('2026-12-04 06:05:00'));
        $this->assertSame([3, '2026-12-04 06:15:00'], [$t->fresh()->attempts, $t->fresh()->next_attempt_at->format('Y-m-d H:i:s')]);

        $this->publisher()->run($this->at('2026-12-04 06:15:00'));
        $this->assertSame(4, $t->fresh()->attempts);
        $this->assertNull($t->fresh()->next_attempt_at);

        $this->publisher()->run($this->at('2026-12-04 06:16:00'));
        $this->assertSame(TargetState::Late, $t->fresh()->state);
        $this->assertContains('late', array_column($this->notices, 0));
    }

    public function test_permanent_error_fails_at_once_with_plain_message(): void
    {
        $this->meta['container'] = 'permanent';
        $t = $this->approved(PostType::InstagramPhoto, '2026-12-04 06:00:00');

        $this->publisher()->run($this->at('2026-12-04 06:00:00'));

        $fresh = $t->fresh();
        $this->assertSame(TargetState::Failed, $fresh->state);
        $this->assertTrue($fresh->work['permanent']);
        $this->assertSame('Invalid parameter', $fresh->last_error);
        $this->assertSame('failed', $this->notices[0][0]);
        $this->assertSame([], $this->disk->files());

        $this->publisher()->run($this->at('2026-12-04 06:05:00'));
        $this->assertSame(1, $this->sentTo('POST', '/'.self::IG.'/media'));
    }

    public function test_late_post_is_retried_on_each_of_three_next_days_then_missed(): void
    {
        // Laptop off at 06:00 UTC (11:30 India) on 4 Dec; on again at 06:40.
        $t = $this->approved(PostType::InstagramPhoto, '2026-12-04 06:00:00');
        $this->meta['container'] = 'transient';

        $this->publisher()->run($this->at('2026-12-04 06:40:00'));
        $this->assertSame(TargetState::Late, $t->fresh()->state);
        $this->assertSame(0, $this->sentTo('POST', '/'.self::IG.'/media'));

        $this->publisher()->run($this->at('2026-12-04 15:00:00'));
        $this->assertSame(0, $this->sentTo('POST', '/'.self::IG.'/media'), 'not the same day');

        $this->publisher()->run($this->at('2026-12-05 04:00:00'));
        $this->assertSame(1, $this->sentTo('POST', '/'.self::IG.'/media'));
        $this->assertSame([TargetState::Late, 1], [$t->fresh()->state, $t->fresh()->late_days]);

        $this->publisher()->run($this->at('2026-12-05 09:00:00'));
        $this->assertSame(1, $this->sentTo('POST', '/'.self::IG.'/media'), 'once a day');

        $this->publisher()->run($this->at('2026-12-06 04:00:00'));
        $this->assertSame([TargetState::Late, 2], [$t->fresh()->state, $t->fresh()->late_days]);

        $this->publisher()->run($this->at('2026-12-07 04:00:00'));
        $this->assertSame(TargetState::Missed, $t->fresh()->state);
        $this->assertSame(3, $t->fresh()->late_days);
        $this->assertSame('missed', $this->notices[array_key_last($this->notices)][0]);
    }

    public function test_late_post_is_posted_on_the_next_day_when_instagram_works_again(): void
    {
        $t = $this->approved(PostType::InstagramPhoto, '2026-12-04 06:00:00');
        $this->publisher()->run($this->at('2026-12-04 06:40:00'));

        $this->publisher()->run($this->at('2026-12-05 04:00:00'));

        $fresh = $t->fresh();
        $this->assertSame(TargetState::Published, $fresh->state);
        $this->assertStringContainsString('late', end($this->notices)[1]);
    }

    public function test_late_post_is_never_sent_after_the_third_day(): void
    {
        $t = $this->approved(PostType::InstagramPhoto, '2026-12-04 06:00:00');

        $this->publisher()->run($this->at('2026-12-08 04:00:00'));

        $this->assertSame(TargetState::Missed, $t->fresh()->state);
        $this->assertSame(0, $this->sentTo('POST', '/'.self::IG.'/media'));
    }

    public function test_second_run_while_locked_does_nothing(): void
    {
        $t = $this->approved(PostType::InstagramPhoto, '2026-12-04 06:00:00');
        File::ensureDirectoryExists(app('smm.data_dir').DIRECTORY_SEPARATOR.'run');
        $lock = fopen(app('smm.data_dir').DIRECTORY_SEPARATOR.'run'.DIRECTORY_SEPARATOR.'publish.lock', 'c');
        flock($lock, LOCK_EX);

        $result = $this->publisher()->run($this->at('2026-12-04 06:00:05'));

        flock($lock, LOCK_UN);
        fclose($lock);
        $this->assertTrue($result['locked']);
        $this->assertSame(TargetState::Waiting, $t->fresh()->state);
        Http::assertNothingSent();
    }

    public function test_dry_run_changes_nothing_and_logs_would(): void
    {
        $t = $this->approved(PostType::InstagramPhoto, '2026-12-04 06:00:00');

        $this->publisher(live: false)->run($this->at('2026-12-04 06:00:05'));
        $this->publisher(live: false)->run($this->at('2026-12-04 06:00:35'));

        $this->assertSame(TargetState::Waiting, $t->fresh()->state);
        Http::assertNothingSent();
        $this->assertSame(1, Activity::where('action', 'dry run: would prepare Instagram')->count(), 'logged once, not every pass');
    }

    public function test_connection_lost_during_publish_is_retried_without_a_second_post(): void
    {
        $t = $this->approved(PostType::InstagramPhoto, '2026-12-04 06:00:00', ['state' => TargetState::Queued, 'container_id' => 'c1']);
        $publishCalls = 0;
        Http::fake(function (Request $request) use (&$publishCalls) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            if (str_ends_with($path, '/media_publish')) {
                $publishCalls++;

                throw new ConnectionException('timeout');
            }
            if ($request->method() === 'GET' && str_ends_with($path, '/'.self::IG.'/media')) {
                return Http::response(['data' => []]);
            }

            return Http::response([]);
        });

        $this->publisher()->run($this->at('2026-12-04 06:00:10'));
        $this->assertSame(TargetState::Failed, $t->fresh()->state);

        $this->publisher()->run($this->at('2026-12-04 06:01:10'));

        $fresh = $t->fresh();
        $this->assertSame(TargetState::Failed, $fresh->state);
        $this->assertTrue($fresh->work['permanent']);
        $this->assertStringContainsString('check Instagram before trying again', $fresh->last_error);
        $this->assertSame(1, $publishCalls);
    }

    public function test_post_now_and_new_time_reset_a_missed_target(): void
    {
        $missed = $this->approved(PostType::InstagramPhoto, '2026-12-01 06:00:00', ['state' => TargetState::Missed, 'late_days' => 3, 'attempts' => 4, 'last_error' => 'x']);

        $this->publisher()->postNow($missed, 'owner');
        $fresh = $missed->fresh();
        $this->assertSame([TargetState::Waiting, 0, 0, null], [$fresh->state, $fresh->late_days, $fresh->attempts, $fresh->last_error]);
        $this->assertTrue($fresh->work['post_now']);

        $this->publisher()->run($this->at('2026-12-05 08:00:00'));
        $this->assertSame(TargetState::Published, $missed->fresh()->state);

        $other = $this->approved(PostType::InstagramPhoto, '2026-12-01 06:00:00', ['state' => TargetState::Missed, 'late_days' => 3]);
        $this->publisher()->newTime($other, CarbonImmutable::now()->addDays(2), 'owner');
        $this->assertSame(TargetState::Waiting, $other->fresh()->state);
        $this->assertTrue($other->post->fresh()->scheduled_at->isFuture());
        $this->assertSame(PostState::Approved, $other->post->fresh()->state);

        $this->expectException(ApprovalRefused::class);
        $this->publisher()->newTime($other->fresh(), CarbonImmutable::now()->subDay(), 'owner');
    }
}
