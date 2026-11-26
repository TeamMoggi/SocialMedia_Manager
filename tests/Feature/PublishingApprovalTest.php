<?php

namespace Tests\Feature;

use App\Domain\Meta\MetaGraph;
use App\Domain\Posts\Approval;
use App\Domain\Publishing\FacebookSender;
use App\Domain\Publishing\InstagramSender;
use App\Domain\Publishing\TargetCleanup;
use App\Domain\Relay\RelayFolder;
use App\Enums\Network;
use App\Enums\PostState;
use App\Enums\PostType;
use App\Enums\TargetState;
use App\Models\Brand;
use App\Models\ConnectedAccount;
use App\Models\Post;
use App\Models\PostTarget;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PublishingApprovalTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private Brand $brand;

    private Filesystem $disk;

    protected function setUp(): void
    {
        parent::setUp();
        $this->brand = Brand::where('slug', 'moggies-international')->firstOrFail();
        ConnectedAccount::create(['brand_id' => $this->brand->id, 'network' => Network::FacebookPage, 'external_id' => '1510742765451658', 'name' => 'Page', 'access_token' => 'tok']);
        ConnectedAccount::create(['brand_id' => $this->brand->id, 'network' => Network::Instagram, 'external_id' => '1784', 'name' => '@ig', 'access_token' => 'tok', 'meta' => ['page_id' => '1']]);
        $this->disk = Storage::fake('relay');
        $graph = new MetaGraph('secret', 'live');
        $relay = new RelayFolder($this->disk, 'https://moggiesinternational.com/mr-test/');
        $this->app->instance(TargetCleanup::class, new TargetCleanup(new FacebookSender($graph), new InstagramSender($graph, $relay), true));
    }

    private function handedOver(?CarbonImmutable $at = null): PostTarget
    {
        $post = Post::create(['brand_id' => $this->brand->id, 'caption' => 'Hi', 'state' => PostState::Draft, 'scheduled_at' => $at ?? CarbonImmutable::now()->addDays(3)]);
        $t = PostTarget::create(['post_id' => $post->id, 'network' => Network::FacebookPage, 'type' => PostType::FacebookText, 'state' => TargetState::HandedToFacebook, 'external_id' => 'p_9']);
        $post->update(['state' => PostState::Approved, 'approved_fingerprint' => app(Approval::class)->fingerprint($post->fresh())]);

        return $t->fresh();
    }

    private function sent(string $method, string $pathEnd): int
    {
        return Http::recorded()->filter(fn ($p) => $p[0]->method() === $method && str_ends_with(parse_url($p[0]->url(), PHP_URL_PATH), $pathEnd))->count();
    }

    public function test_withdrawing_an_approved_post_deletes_the_facebook_scheduled_copy(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['success' => true])]);
        $t = $this->handedOver();

        app(Approval::class)->withdraw($t->post->fresh(), 'owner', 'edited');

        $this->assertSame(1, $this->sent('DELETE', '/p_9'));
        $fresh = $t->fresh();
        $this->assertSame([TargetState::Waiting, null], [$fresh->state, $fresh->external_id]);
        $this->assertSame(PostState::AwaitingApproval, $t->post->fresh()->state);
    }

    public function test_a_facebook_failure_while_withdrawing_does_not_block_the_withdrawal(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['code' => 1, 'message' => 'down']], 500)]);
        $t = $this->handedOver();

        app(Approval::class)->withdraw($t->post->fresh(), 'owner', 'edited');

        $this->assertSame(PostState::AwaitingApproval, $t->post->fresh()->state);
        $fresh = $t->fresh();
        $this->assertSame(TargetState::HandedToFacebook, $fresh->state, 'kept so the publisher retries taking it back');
        $this->assertStringContainsString('Could not take the post back from Facebook', $fresh->last_error);
    }

    public function test_changing_the_time_of_a_handed_over_post_updates_facebook(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['success' => true])]);
        $t = $this->handedOver();
        $new = CarbonImmutable::now()->addDays(5);

        app(Approval::class)->reschedule($t->post->fresh(), $new, 'owner');

        $this->assertSame(1, $this->sent('POST', '/p_9'));
        $this->assertSame(TargetState::HandedToFacebook, $t->fresh()->state);
        $this->assertSame(PostState::Approved, $t->post->fresh()->state);
    }

    public function test_moving_a_handed_over_post_closer_than_10_minutes_takes_it_back_from_facebook(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['success' => true])]);
        $t = $this->handedOver();

        app(Approval::class)->reschedule($t->post->fresh(), CarbonImmutable::now()->addMinutes(5), 'owner');

        $this->assertSame(1, $this->sent('DELETE', '/p_9'));
        $this->assertSame(TargetState::Waiting, $t->fresh()->state);
    }

    public function test_changing_the_time_of_an_instagram_queued_post_prepares_it_again_later(): void
    {
        Http::fake();
        $post = Post::create(['brand_id' => $this->brand->id, 'caption' => 'Hi', 'state' => PostState::Draft, 'scheduled_at' => CarbonImmutable::now()->addHour()]);
        $this->disk->put('abc.jpg', 'x');
        $t = PostTarget::create(['post_id' => $post->id, 'network' => Network::Instagram, 'type' => PostType::InstagramPhoto, 'state' => TargetState::Queued, 'container_id' => 'c1', 'work' => ['relay' => ['abc.jpg']]]);
        $post->update(['state' => PostState::Approved, 'approved_fingerprint' => app(Approval::class)->fingerprint($post->fresh())]);

        app(Approval::class)->reschedule($post->fresh(), CarbonImmutable::now()->addHours(5), 'owner');

        $fresh = $t->fresh();
        $this->assertSame([TargetState::Waiting, null], [$fresh->state, $fresh->container_id]);
        $this->assertSame([], $this->disk->files());
    }

    public function test_deleting_a_post_removes_the_facebook_copy_and_relay_files(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['success' => true])]);
        $t = $this->handedOver();
        $this->disk->put('left.jpg', 'x');
        $ig = PostTarget::create(['post_id' => $t->post_id, 'network' => Network::Instagram, 'type' => PostType::InstagramPhoto, 'state' => TargetState::Queued, 'container_id' => 'c1', 'work' => ['relay' => ['left.jpg']]]);

        $t->post->fresh()->delete();

        $this->assertSame(1, $this->sent('DELETE', '/p_9'));
        $this->assertSame([], $this->disk->files());
        $this->assertNull(PostTarget::find($ig->id));
    }

    public function test_a_published_target_is_never_touched_by_withdraw_or_delete(): void
    {
        Http::fake();
        $t = $this->handedOver();
        $t->update(['state' => TargetState::Published, 'permalink' => 'https://www.facebook.com/p/9']);

        app(Approval::class)->withdraw($t->post->fresh(), 'owner', 'edited');
        $t->post->fresh()->delete();

        Http::assertNothingSent();
    }

    public function test_withdrawing_gives_a_failed_or_missed_target_a_fresh_start(): void
    {
        Http::fake();
        $t = $this->handedOver();
        $t->update(['state' => TargetState::Missed, 'external_id' => null, 'attempts' => 4, 'late_days' => 3, 'last_error' => 'x', 'work' => ['permanent' => true]]);

        app(Approval::class)->withdraw($t->post->fresh(), 'owner', 'edited');

        $fresh = $t->fresh();
        $this->assertSame([TargetState::Waiting, 0, 0, null], [$fresh->state, $fresh->attempts, $fresh->late_days, $fresh->last_error]);
        $this->assertArrayNotHasKey('permanent', $fresh->work ?? []);
    }
}
