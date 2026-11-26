<?php

namespace Tests\Feature;

use App\Domain\Meta\MetaGraph;
use App\Domain\Posts\Approval;
use App\Domain\Publishing\Action;
use App\Domain\Publishing\FacebookSender;
use App\Domain\Publishing\InstagramSender;
use App\Domain\Publishing\Notifier;
use App\Domain\Publishing\Popup;
use App\Domain\Publishing\Publisher;
use App\Domain\Publishing\TargetCleanup;
use App\Domain\Publishing\TargetPlanner;
use App\Domain\Publishing\TemporaryFailure;
use App\Domain\Publishing\WakeTasks;
use App\Domain\Relay\RelayFolder;
use App\Enums\Network;
use App\Enums\PostState;
use App\Enums\PostType;
use App\Enums\TargetState;
use App\Filament\Pages\MissedPosts;
use App\Models\Brand;
use App\Models\ConnectedAccount;
use App\Models\Post;
use App\Models\PostTarget;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

/** Fixes from the final review of SM-010 (double posts, deleting public posts, stale approvals, time zones). */
class PublishingReviewFixesTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const PAGE = '15';

    private const IG = '17';

    private Brand $brand;

    private Filesystem $disk;

    private RelayFolder $relay;

    private string $dir;

    /** @var list<callable(Request, string): mixed> first handler that returns something wins */
    private array $handlers = [];

    /** @var list<string> */
    private array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->brand = Brand::where('slug', 'moggies-international')->firstOrFail();
        ConnectedAccount::create(['brand_id' => $this->brand->id, 'network' => Network::FacebookPage, 'external_id' => self::PAGE, 'name' => 'Page', 'access_token' => 'tok']);
        ConnectedAccount::create(['brand_id' => $this->brand->id, 'network' => Network::Instagram, 'external_id' => self::IG, 'name' => '@ig', 'access_token' => 'tok', 'meta' => ['page_id' => '1']]);
        $this->disk = Storage::fake('relay');
        $this->relay = new RelayFolder($this->disk, 'https://moggiesinternational.com/mr-test/');
        $this->dir = app('smm.data_dir').DIRECTORY_SEPARATOR.'media'.DIRECTORY_SEPARATOR.'test-fix';
        File::ensureDirectoryExists($this->dir);
        imagejpeg(imagecreatetruecolor(1080, 1080), $this->dir.'/a.jpg', 80);
        Http::fake(function (Request $request) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            $this->calls[] = $request->method().' '.$path;
            foreach ($this->handlers as $handler) {
                $response = $handler($request, $path);
                if ($response !== null) {
                    return $response;
                }
            }
            if ($request->method() === 'POST' && str_ends_with($path, '/'.self::IG.'/media')) {
                return Http::response(['id' => 'c1']);
            }
            if ($request->method() === 'POST' && str_ends_with($path, '/media_publish')) {
                return Http::response(['id' => 'm1']);
            }
            if ($request->method() === 'POST' && str_ends_with($path, '/'.self::PAGE.'/feed')) {
                return Http::response(['id' => '15_9']);
            }
            if ($request->method() === 'GET' && str_ends_with($path, '/m1')) {
                return Http::response(['permalink' => 'https://www.instagram.com/p/ABC/']);
            }
            if ($request->method() === 'GET' && str_ends_with($path, '/15_9')) {
                return Http::response(['is_published' => true, 'permalink_url' => 'https://www.facebook.com/15/posts/9']);
            }
            if ($request->method() === 'GET') {
                return Http::response(['status_code' => 'FINISHED']);
            }

            return Http::response(['success' => true]);
        });
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function made(string $needle): int
    {
        return count(array_filter($this->calls, fn (string $c): bool => str_contains($c, $needle)));
    }

    private function at(string $utc): CarbonImmutable
    {
        return CarbonImmutable::parse($utc, 'UTC');
    }

    private function graph(): MetaGraph
    {
        return new MetaGraph('secret', 'live');
    }

    private function publisher(?TargetPlanner $planner = null, ?RelayFolder $relay = null, ?\Closure $sleep = null): Publisher
    {
        $graph = $this->graph();
        $notifier = new class extends Notifier
        {
            public function notify(string $kind, PostTarget $target, string $text): void {}
        };

        return new Publisher($planner ?? new TargetPlanner, new FacebookSender($graph), new InstagramSender($graph, $relay ?? $this->relay, $sleep ?? fn (int $s) => null), app(Approval::class), $notifier, true);
    }

    private function approved(PostType $type, string $scheduledUtc, array $target = [], string $caption = 'Raisins from Sangli'): PostTarget
    {
        $post = Post::create(['brand_id' => $this->brand->id, 'caption' => $caption, 'state' => PostState::Draft, 'scheduled_at' => $this->at($scheduledUtc)]);
        if ($type === PostType::InstagramPhoto) {
            $post->media()->create(['position' => 1, 'path' => 'test-fix/a.jpg', 'mime' => 'image/jpeg', 'bytes' => 5000, 'width' => 1080, 'height' => 1080]);
        }
        $t = PostTarget::create(array_merge(['post_id' => $post->id, 'network' => $type->network(), 'type' => $type], $target));
        $post->update(['state' => PostState::Approved, 'approved_fingerprint' => app(Approval::class)->fingerprint($post->fresh())]);

        return $t->fresh();
    }

    private function cleanup(): void
    {
        $this->app->instance(TargetCleanup::class, new TargetCleanup(new FacebookSender($this->graph()), new InstagramSender($this->graph(), $this->relay), true));
    }

    // C1 -------------------------------------------------------------------------------------------------------

    public function test_instagram_guard_survives_late_catch_up_and_never_posts_twice(): void
    {
        $this->handlers[] = fn (Request $r, string $p) => str_ends_with($p, '/media_publish') ? throw new ConnectionException('timeout') : null;
        $this->handlers[] = fn (Request $r, string $p) => ($r->method() === 'GET' && str_ends_with($p, '/'.self::IG.'/media')) ? Http::response(['data' => []]) : null;
        $t = $this->approved(PostType::InstagramPhoto, '2026-12-04 06:00:00', ['state' => TargetState::Queued, 'container_id' => 'c1']);

        $this->publisher()->run($this->at('2026-12-04 06:00:10'));
        $this->assertNotEmpty($t->fresh()->work['publish_started_at']);

        $this->publisher()->run($this->at('2026-12-04 06:20:00'));
        $this->assertSame(TargetState::Late, $t->fresh()->state);
        $this->assertNotEmpty($t->fresh()->work['publish_started_at'], 'going late must not forget a possible post');

        $this->publisher()->run($this->at('2026-12-05 04:00:00'));

        $fresh = $t->fresh();
        $this->assertSame(1, $this->made('/media_publish'), 'published once only');
        $this->assertSame(0, count(array_keys($this->calls, 'POST /v27.0/'.self::IG.'/media')), 'no new container for a post that may already be out');
        $this->assertTrue($fresh->work['permanent']);
        $this->assertStringContainsString('check Instagram before trying again', $fresh->last_error);
    }

    // C2 -------------------------------------------------------------------------------------------------------

    public function test_facebook_lost_connection_on_the_final_call_does_not_post_twice(): void
    {
        $feedCalls = 0;
        $this->handlers[] = function (Request $r, string $p) use (&$feedCalls) {
            if ($r->method() === 'POST' && str_ends_with($p, '/'.self::PAGE.'/feed')) {
                $feedCalls++;
                throw new ConnectionException('timeout');
            }

            return null;
        };
        $this->handlers[] = fn (Request $r, string $p) => ($r->method() === 'GET' && str_ends_with($p, '/'.self::PAGE.'/published_posts'))
            ? Http::response(['data' => [['id' => '15_9', 'message' => 'Raisins from Sangli', 'created_time' => '2026-12-04T06:00:08+0000', 'permalink_url' => 'https://www.facebook.com/15/posts/9']]])
            : null;
        $t = $this->approved(PostType::FacebookText, '2026-12-04 06:00:00');

        $this->publisher()->run($this->at('2026-12-04 06:00:05'));
        $this->assertSame(TargetState::Failed, $t->fresh()->state);

        $this->publisher()->run($this->at('2026-12-04 06:01:10'));

        $fresh = $t->fresh();
        $this->assertSame(TargetState::Published, $fresh->state);
        $this->assertSame('15_9', $fresh->external_id);
        $this->assertSame(1, $feedCalls, 'the post was adopted, not made again');
    }

    public function test_facebook_unmatched_after_a_lost_connection_stops_with_check_the_page(): void
    {
        $this->handlers[] = fn (Request $r, string $p) => ($r->method() === 'POST' && str_ends_with($p, '/'.self::PAGE.'/feed')) ? throw new ConnectionException('timeout') : null;
        $this->handlers[] = fn (Request $r, string $p) => ($r->method() === 'GET' && str_ends_with($p, '/published_posts')) ? Http::response(['data' => []]) : null;
        $t = $this->approved(PostType::FacebookText, '2026-12-04 06:00:00');

        $this->publisher()->run($this->at('2026-12-04 06:00:05'));
        $this->publisher()->run($this->at('2026-12-04 06:01:10'));

        $fresh = $t->fresh();
        $this->assertSame(TargetState::Failed, $fresh->state);
        $this->assertTrue($fresh->work['permanent']);
        $this->assertStringContainsString('check the Page', $fresh->last_error);
    }

    // C3 -------------------------------------------------------------------------------------------------------

    public function test_taking_a_post_back_never_deletes_one_facebook_has_already_published(): void
    {
        $this->cleanup();
        $t = $this->approved(PostType::FacebookText, '2026-12-04 06:00:00', ['state' => TargetState::HandedToFacebook, 'external_id' => '15_9']);
        CarbonImmutable::setTestNow($this->at('2026-12-04 06:10:00'));

        app(Approval::class)->withdraw($t->post->fresh(), 'owner', 'edited');

        $this->assertSame(0, $this->made('DELETE'));
        $this->assertSame(TargetState::Published, $t->fresh()->state);
        $this->assertSame('https://www.facebook.com/15/posts/9', $t->fresh()->permalink);
    }

    public function test_when_facebook_cannot_say_whether_it_published_nothing_is_deleted(): void
    {
        $this->cleanup();
        $this->handlers[] = fn (Request $r, string $p) => ($r->method() === 'GET' && str_contains($p, '15_9')) ? Http::response(['error' => ['code' => 1, 'message' => 'down']], 500) : null;
        $t = $this->approved(PostType::FacebookText, '2026-12-04 06:00:00', ['state' => TargetState::HandedToFacebook, 'external_id' => '15_9']);
        CarbonImmutable::setTestNow($this->at('2026-12-04 06:10:00'));

        app(Approval::class)->withdraw($t->post->fresh(), 'owner', 'edited');

        $this->assertSame(0, $this->made('DELETE'));
        $this->assertSame(TargetState::HandedToFacebook, $t->fresh()->state);
    }

    // I1 -------------------------------------------------------------------------------------------------------

    public function test_a_failed_status_check_keeps_the_post_handed_over_and_a_later_one_confirms_it(): void
    {
        $down = true;
        $this->handlers[] = function (Request $r, string $p) use (&$down) {
            return ($down && $r->method() === 'GET' && str_contains($p, '15_9')) ? Http::response(['error' => ['code' => 1, 'message' => 'down']], 500) : null;
        };
        $t = $this->approved(PostType::FacebookText, '2026-12-04 06:00:00', ['state' => TargetState::HandedToFacebook, 'external_id' => '15_9']);

        $this->publisher()->run($this->at('2026-12-04 08:30:00'));
        $this->assertSame(TargetState::HandedToFacebook, $t->fresh()->state, 'an unreachable Facebook is not a failed post');

        $down = false;
        $this->publisher()->run($this->at('2026-12-04 08:31:00'));
        $this->assertSame(TargetState::Published, $t->fresh()->state);
    }

    public function test_post_now_on_a_facebook_post_that_is_already_public_does_not_post_it_again(): void
    {
        $this->cleanup();
        $t = $this->approved(PostType::FacebookText, '2026-12-04 06:00:00', ['state' => TargetState::Failed, 'external_id' => '15_9', 'work' => ['permanent' => true]]);
        CarbonImmutable::setTestNow($this->at('2026-12-04 09:00:00'));

        $this->publisher()->postNow($t, 'owner');
        $this->publisher()->run($this->at('2026-12-04 09:00:30'));

        $this->assertSame(TargetState::Published, $t->fresh()->state);
        $this->assertSame(0, $this->made('POST /v27.0/'.self::PAGE.'/feed'));
    }

    // I2 -------------------------------------------------------------------------------------------------------

    public function test_a_handed_over_copy_that_no_longer_matches_the_approved_post_is_replaced(): void
    {
        $t = $this->approved(PostType::FacebookText, '2026-12-14 06:00:00', ['state' => TargetState::HandedToFacebook, 'external_id' => '15_old', 'work' => ['fb_held' => ['fingerprint' => 'stale', 'at' => '2026-12-14T06:00:00+00:00']]]);

        $this->publisher()->run($this->at('2026-12-04 06:00:00'));

        $this->assertSame(1, $this->made('DELETE'));
        $this->assertSame(1, $this->made('POST /v27.0/'.self::PAGE.'/feed'));
        $fresh = $t->fresh();
        $this->assertSame('15_9', $fresh->external_id);
        $this->assertSame(app(Approval::class)->fingerprint($t->post->fresh()), $fresh->work['fb_held']['fingerprint']);
    }

    public function test_a_handed_over_copy_with_the_wrong_time_is_moved(): void
    {
        $post = $this->approved(PostType::FacebookText, '2026-12-14 06:00:00');
        $fingerprint = app(Approval::class)->fingerprint($post->post->fresh());
        $post->update(['state' => TargetState::HandedToFacebook, 'external_id' => '15_9', 'work' => ['fb_held' => ['fingerprint' => $fingerprint, 'at' => '2026-12-11T06:00:00+00:00']]]);

        $this->publisher()->run($this->at('2026-12-04 06:00:00'));

        $this->assertSame(0, $this->made('DELETE'));
        $this->assertSame(1, $this->made('POST /v27.0/15_9'));
        $this->assertSame('2026-12-14T06:00:00+00:00', $post->fresh()->work['fb_held']['at']);
    }

    public function test_a_post_is_not_deleted_while_facebook_still_holds_a_copy_that_cannot_be_taken_back(): void
    {
        $this->cleanup();
        $this->handlers[] = fn (Request $r, string $p) => $r->method() === 'DELETE' ? Http::response(['error' => ['code' => 1, 'message' => 'down']], 500) : null;
        $t = $this->approved(PostType::FacebookText, '2026-12-14 06:00:00', ['state' => TargetState::HandedToFacebook, 'external_id' => '15_9']);

        $deleted = $t->post->fresh()->delete();

        $this->assertFalse($deleted);
        $this->assertNotNull(Post::find($t->post_id));
    }

    // I3 / attempts ------------------------------------------------------------------------------------------

    public function test_running_out_of_retries_before_the_post_time_keeps_trying_every_30_minutes_instead_of_going_late(): void
    {
        $fail = true;
        $this->handlers[] = function (Request $r, string $p) use (&$fail) {
            return ($fail && $r->method() === 'POST' && str_ends_with($p, '/'.self::PAGE.'/feed'))
                ? Http::response(['error' => ['code' => 2, 'message' => 'down']], 500) : null;
        };
        $t = $this->approved(PostType::FacebookText, '2026-12-14 06:00:00');

        foreach (['2026-12-04 06:00:00', '2026-12-04 06:01:00', '2026-12-04 06:05:00', '2026-12-04 06:15:00'] as $when) {
            $this->publisher()->run($this->at($when));
        }
        $fresh = $t->fresh();
        $this->assertSame([TargetState::Failed, 4, '2026-12-04 06:45:00'], [$fresh->state, $fresh->attempts, $fresh->next_attempt_at->format('Y-m-d H:i:s')]);

        $this->publisher()->run($this->at('2026-12-04 06:20:00'));
        $this->assertSame(TargetState::Failed, $t->fresh()->state, 'not late: the post time is still days away');

        $fail = false;
        $this->publisher()->run($this->at('2026-12-04 06:45:00'));
        $fresh = $t->fresh();
        $this->assertSame([TargetState::HandedToFacebook, 0], [$fresh->state, $fresh->attempts], 'attempts start again after a success');
    }

    // I4 -------------------------------------------------------------------------------------------------------

    public function test_the_popup_script_keeps_running_after_the_command_that_started_it_returns(): void
    {
        $marker = sys_get_temp_dir().DIRECTORY_SEPARATOR.'smm-popup-'.bin2hex(random_bytes(4)).'.txt';
        $script = sys_get_temp_dir().DIRECTORY_SEPARATOR.'smm-popup-'.bin2hex(random_bytes(4)).'.ps1';
        file_put_contents($script, "param([string]\$Title,[string]\$Text)\nStart-Sleep -Seconds 2\nSet-Content -Path '{$marker}' -Value \$Text\n");

        Popup::show('Title', 'hello world', $script);

        for ($i = 0; $i < 40 && ! is_file($marker); $i++) {
            usleep(250_000);
        }
        $this->assertFileExists($marker, 'the pop-up script was killed when show() returned');
        $this->assertStringContainsString('hello world', (string) file_get_contents($marker));
        @unlink($marker);
        @unlink($script);
    }

    // I5c -------------------------------------------------------------------------------------------------------

    public function test_every_target_is_planned_with_the_real_time_not_the_time_the_pass_started(): void
    {
        $planner = new class extends TargetPlanner
        {
            public array $seen = [];

            public function plan(PostTarget $target, CarbonImmutable $now): Action
            {
                $this->seen[] = $now;

                return parent::plan($target, $now);
            }
        };
        CarbonImmutable::setTestNow($this->at('2026-12-04 06:00:00'));
        $polls = 0;
        $this->handlers[] = function (Request $r, string $p) use (&$polls) {
            return ($r->method() === 'GET' && str_ends_with($p, '/c1')) ? Http::response(['status_code' => ++$polls < 2 ? 'IN_PROGRESS' : 'FINISHED']) : null;
        };
        $this->approved(PostType::InstagramPhoto, '2026-12-04 06:10:00');
        $this->approved(PostType::FacebookText, '2026-12-04 06:20:00');
        $this->publisher($planner, null, function (int $seconds): void {
            CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(5));
        })->run();

        $seen = $planner->seen;
        $this->assertGreaterThanOrEqual(2, count($seen));
        $this->assertGreaterThan($seen[0]->timestamp, end($seen)->timestamp, 'a long prepare must not freeze the clock for the targets after it');
    }

    // I6 -------------------------------------------------------------------------------------------------------

    public function test_relay_files_without_a_container_count_as_not_prepared_and_old_ones_are_removed_first(): void
    {
        $t = $this->approved(PostType::InstagramPhoto, '2026-12-04 06:00:00', ['work' => ['relay' => ['old.jpg']]]);
        $this->disk->put('old.jpg', 'x');

        $this->assertSame(Action::PrepareInstagram, (new TargetPlanner)->plan($t->fresh(['post.brand', 'post.media']), $this->at('2026-12-04 06:00:05')));

        (new InstagramSender($this->graph(), $this->relay, fn (int $s) => null))->prepare($t->fresh(['post.media']));

        $this->assertFalse($this->disk->exists('old.jpg'));
        $this->assertCount(1, $this->disk->files());
        $this->assertSame('c1', $t->fresh()->container_id);
    }

    public function test_publishing_before_preparing_and_a_hostinger_blip_are_temporary_not_permanent(): void
    {
        $t = $this->approved(PostType::InstagramPhoto, '2026-12-04 06:00:00');
        $sender = new InstagramSender($this->graph(), $this->relay, fn (int $s) => null);
        try {
            $sender->publish($t->fresh(['post.media']));
            $this->fail('TemporaryFailure expected');
        } catch (TemporaryFailure) {
            $this->addToAssertionCount(1);
        }

        $broken = Mockery::mock(Filesystem::class);
        $broken->shouldReceive('writeStream')->andThrow(new \RuntimeException('FTP connection refused'));
        $broken->shouldReceive('delete');
        $flaky = new InstagramSender($this->graph(), new RelayFolder($broken, 'https://moggiesinternational.com/mr-test/'), fn (int $s) => null);
        try {
            $flaky->prepare($t->fresh(['post.media']));
            $this->fail('TemporaryFailure expected');
        } catch (TemporaryFailure $e) {
            $this->assertStringContainsString('Hostinger', $e->getMessage());
        }
    }

    // I7 -------------------------------------------------------------------------------------------------------

    public function test_the_catch_up_gate_is_saved_as_the_real_moment_of_the_next_india_midnight(): void
    {
        $this->handlers[] = fn (Request $r, string $p) => ($r->method() === 'POST' && str_ends_with($p, '/'.self::IG.'/media'))
            ? Http::response(['error' => ['code' => 2, 'message' => 'down']], 500) : null;
        $t = $this->approved(PostType::InstagramPhoto, '2026-12-04 06:00:00', ['state' => TargetState::Late]);

        $this->publisher()->run($this->at('2026-12-05 04:00:00'));

        // 5 Dec 04:00 UTC = 09:30 IST on 5 Dec; the next India midnight is 6 Dec 00:00 IST = 5 Dec 18:30 UTC.
        $this->assertSame('2026-12-05 18:30:00', $t->fresh()->next_attempt_at->utc()->format('Y-m-d H:i:s'));
    }

    // I8 -------------------------------------------------------------------------------------------------------

    public function test_post_now_gets_its_own_on_time_window_and_retries(): void
    {
        $t = $this->approved(PostType::InstagramPhoto, '2026-12-01 06:00:00', [
            'state' => TargetState::Failed, 'attempts' => 1, 'next_attempt_at' => $this->at('2026-12-05 08:01:00'),
            'work' => ['post_now' => true, 'post_now_at' => '2026-12-05T08:00:00+00:00'],
        ]);

        $this->assertSame(Action::RetryNow, (new TargetPlanner)->plan($t->fresh(['post.brand', 'post.media']), $this->at('2026-12-05 08:01:00')));
        $this->assertSame(Action::MarkLate, (new TargetPlanner)->plan($t->fresh(['post.brand', 'post.media']), $this->at('2026-12-05 08:20:00')));
    }

    // I9 -------------------------------------------------------------------------------------------------------

    public function test_a_post_withdrawn_while_instagram_prepares_it_is_not_published(): void
    {
        $t = $this->approved(PostType::InstagramPhoto, '2026-12-04 06:00:00');
        $polls = 0;
        $this->handlers[] = function (Request $r, string $p) use (&$polls) {
            if ($r->method() === 'GET' && str_ends_with($p, '/c1')) {
                return Http::response(['status_code' => ++$polls < 2 ? 'IN_PROGRESS' : 'FINISHED']);
            }

            return null;
        };
        $post = $t->post;

        $this->publisher(null, null, fn (int $s) => $post->update(['caption' => 'Changed while preparing']))->run($this->at('2026-12-04 06:00:05'));

        $this->assertSame(0, $this->made('/media_publish'));
        $this->assertNotSame(TargetState::Published, $t->fresh()->state);
        $this->assertSame([], $this->disk->files());
    }

    // Minors ----------------------------------------------------------------------------------------------------

    public function test_a_retried_hand_over_keeps_the_new_photo_ids_not_the_old_ones(): void
    {
        $t = $this->approved(PostType::FacebookPhotos, '2026-12-14 06:00:00', ['work' => ['fb_media' => ['old1']]]);
        $t->post->media()->create(['position' => 1, 'path' => 'test-fix/a.jpg', 'mime' => 'image/jpeg', 'bytes' => 5000]);
        $this->handlers[] = fn (Request $r, string $p) => ($r->method() === 'POST' && str_ends_with($p, '/photos')) ? Http::response(['id' => 'new1']) : null;

        (new FacebookSender($this->graph()))->handOver($t->fresh(['post.media']), $this->at('2026-12-14 06:00:00'));

        $this->assertSame(['new1'], $t->fresh()->work['fb_media']);
    }

    public function test_taking_back_a_post_the_owner_already_deleted_on_facebook_counts_as_done(): void
    {
        $this->handlers[] = fn (Request $r, string $p) => $r->method() === 'DELETE' ? Http::response(['error' => ['code' => 100, 'message' => 'Object does not exist']], 400) : null;
        $t = $this->approved(PostType::FacebookText, '2026-12-14 06:00:00', ['state' => TargetState::HandedToFacebook, 'external_id' => '15_9']);

        (new FacebookSender($this->graph()))->withdraw($t);

        $this->assertSame([TargetState::Waiting, null], [$t->fresh()->state, $t->fresh()->external_id]);
    }

    public function test_instagram_adoption_ignores_line_ending_differences(): void
    {
        $t = $this->approved(PostType::InstagramPhoto, '2026-12-04 06:00:00', ['state' => TargetState::Queued, 'container_id' => 'c1', 'work' => ['publish_started_at' => '2026-12-04T05:59:00+00:00']], "Line one\r\nLine two");
        $this->handlers[] = fn (Request $r, string $p) => ($r->method() === 'GET' && str_ends_with($p, '/'.self::IG.'/media'))
            ? Http::response(['data' => [['id' => 'm77', 'timestamp' => '2026-12-04T06:00:03+0000', 'permalink' => 'https://www.instagram.com/p/XYZ/', 'caption' => "Line one\nLine two"]]]) : null;

        (new InstagramSender($this->graph(), $this->relay, fn (int $s) => null))->publish($t->fresh(['post.media']));

        $this->assertSame('m77', $t->fresh()->external_id);
    }

    public function test_the_missed_page_badge_counts_failed_posts_too(): void
    {
        $this->actingAs(\App\Models\User::where('email', \App\Models\User::LOCAL_OWNER_EMAIL)->firstOrFail());
        Filament::setCurrentPanel('app');
        Filament::setTenant($this->brand);
        $this->approved(PostType::InstagramPhoto, '2026-12-04 06:00:00', ['state' => TargetState::Failed]);

        $this->assertSame('1', MissedPosts::getNavigationBadge());
    }

    public function test_wake_tasks_run_the_script_only_when_the_times_changed(): void
    {
        $this->approved(PostType::FacebookText, '2026-12-14 06:00:00');
        $runs = 0;
        $file = sys_get_temp_dir().DIRECTORY_SEPARATOR.'smm-wake-'.bin2hex(random_bytes(4)).'.hash';
        $wake = new WakeTasks(run: function (array $times) use (&$runs): void {
            $runs++;
        }, enabled: true, stateFile: $file);
        $now = $this->at('2026-12-04 06:00:00');

        $wake->sync($now);
        $wake->sync($now);
        $this->assertSame(1, $runs);

        $this->approved(PostType::FacebookText, '2026-12-15 06:00:00');
        $wake->sync($now);
        $this->assertSame(2, $runs);
        @unlink($file);
    }
}
