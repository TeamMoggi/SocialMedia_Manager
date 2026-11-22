<?php

namespace Tests\Feature;

use App\Domain\Library\LibraryScanner;
use App\Domain\Posts\Approval;
use App\Domain\Posts\ApprovalRefused;
use App\Domain\Posts\Problem;
use App\Enums\Network;
use App\Enums\PostState;
use App\Enums\PostType;
use App\Models\Activity;
use App\Models\Brand;
use App\Models\ConnectedAccount;
use App\Models\MediaItem;
use App\Models\Post;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class ApprovalTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private Brand $brand;

    private string $dir;

    private Approval $approval;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'smm-approve-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->dir.'/Images');
        foreach (['one.jpg', 'two.jpg'] as $name) {
            imagejpeg(imagecreatetruecolor(1080, 1080), $this->dir.'/Images/'.$name, 85);
        }
        $this->brand = Brand::where('slug', 'moggies-international')->firstOrFail();
        $this->brand->update(['library_sources' => [$this->dir]]);
        app(LibraryScanner::class)->scan($this->brand->fresh());
        foreach ([[Network::FacebookPage, '1510742765451658'], [Network::Instagram, '17841470732347612']] as [$network, $id]) {
            ConnectedAccount::create(['brand_id' => $this->brand->id, 'network' => $network, 'external_id' => $id,
                'name' => 'Moggies International', 'access_token' => 'page-token']);
        }
        $this->approval = app(Approval::class);
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

    /** A post that passes every check: Facebook photo + Instagram photo, one photo, tomorrow. */
    private function goodPost(array $photos = ['one.jpg']): Post
    {
        $post = Post::create(['brand_id' => $this->brand->id, 'caption' => 'Sangli raisins, graded and packed. #raisins',
            'scheduled_at' => CarbonImmutable::now()->addDay()]);
        $post->targets()->create(['network' => Network::FacebookPage, 'type' => PostType::FacebookPhotos]);
        $post->targets()->create(['network' => Network::Instagram, 'type' => $photos === ['one.jpg'] ? PostType::InstagramPhoto : PostType::InstagramCarousel]);
        foreach ($photos as $i => $name) {
            $post->media()->create(['media_item_id' => $this->item($name)->id, 'position' => $i + 1]);
        }

        return $post->fresh();
    }

    private function messages(Post $post): array
    {
        return array_map(fn (Problem $p) => $p->level.': '.$p->message, $this->approval->problems($post));
    }

    private function approve(Post $post): void
    {
        $this->approval->approve($post, $this->brand->approvalChecklist(), 'Owner');
    }

    public function test_good_post_has_no_problems(): void
    {
        $this->assertSame([], $this->messages($this->goodPost()));
    }

    public function test_cannot_approve_with_blocking_problems(): void
    {
        $post = $this->goodPost();
        $post->media()->get()->each->delete();

        $this->expectException(ApprovalRefused::class);
        $this->approve($post->fresh());
    }

    public function test_cannot_approve_without_every_checklist_item(): void
    {
        $post = $this->goodPost();

        try {
            $this->approval->approve($post, array_slice($this->brand->approvalChecklist(), 0, 4), 'Owner');
            $this->fail('Approval should be refused');
        } catch (ApprovalRefused $e) {
            $this->assertStringContainsString('Tick every brand rule', $e->getMessage());
        }
        $this->assertSame(PostState::Draft, $post->fresh()->state);
    }

    public function test_approve_records_who_when_and_checklist(): void
    {
        $post = $this->goodPost();

        $this->approve($post);

        $post->refresh();
        $this->assertSame(PostState::Approved, $post->state);
        $this->assertSame('Owner', $post->approved_by);
        $this->assertNotNull($post->approved_at);
        $this->assertCount(5, $post->checklist);
        $this->assertTrue($post->checklist[0]['ticked']);
        $this->assertSame($this->approval->fingerprint($post), $post->approved_fingerprint);
    }

    public function test_unanswered_ai_people_blocks(): void
    {
        $this->item('one.jpg')->update(['ai_made' => true, 'shows_people' => null]);

        $messages = $this->messages($this->goodPost());

        $this->assertContains("block: Answer 'shows people' for 'one.jpg' in the Library (it is AI-made).", $messages);
    }

    public function test_unconnected_network_blocks(): void
    {
        ConnectedAccount::where('network', Network::Instagram)->delete();

        $this->assertContains('block: Instagram: connect Instagram on the Meta page first.', $this->messages($this->goodPost()));
    }

    public function test_missing_or_past_time_blocks(): void
    {
        $post = $this->goodPost();
        $post->update(['scheduled_at' => CarbonImmutable::now()->subHour()]);
        $this->assertContains('block: The time has passed; pick a time in the future.', $this->messages($post->fresh()));

        $post->update(['scheduled_at' => null]);
        $this->assertContains('block: Pick a time for the post.', $this->messages($post->fresh()));
    }

    public function test_media_problems_name_their_network(): void
    {
        $post = $this->goodPost();
        $post->update(['caption' => implode(' ', array_map(fn ($i) => "#t{$i}", range(1, 31)))]);

        $this->assertContains('block: Instagram: The caption has 31 hashtags; Instagram takes up to 30.', $this->messages($post->fresh()));
    }

    public function test_caption_change_reopens_approval(): void
    {
        $post = $this->goodPost();
        $this->approve($post);

        $post->update(['caption' => 'A different caption.']);
        $reopened = $this->approval->reopenIfChanged($post->fresh(), 'Owner');

        $this->assertTrue($reopened);
        $post->refresh();
        $this->assertSame(PostState::AwaitingApproval, $post->state);
        $this->assertNull($post->approved_at);
        $this->assertNull($post->approved_fingerprint);
    }

    public function test_media_reorder_reopens_approval(): void
    {
        $post = $this->goodPost(['one.jpg', 'two.jpg']);
        $this->approve($post);

        [$first, $second] = $post->media()->get()->all();
        $first->update(['position' => 2]);
        $second->update(['position' => 1]);

        $this->assertTrue($this->approval->reopenIfChanged($post->fresh(), 'Owner'));
    }

    public function test_time_change_keeps_approval(): void
    {
        $post = $this->goodPost();
        $this->approve($post);

        $this->approval->reschedule($post->fresh(), CarbonImmutable::now()->addDays(2), 'Owner');

        $this->assertFalse($this->approval->reopenIfChanged($post->fresh(), 'Owner'));
        $this->assertSame(PostState::Approved, $post->fresh()->state);
    }

    public function test_reschedule_to_past_is_refused(): void
    {
        $post = $this->goodPost();

        $this->expectException(ApprovalRefused::class);
        $this->expectExceptionMessage('Pick a time in the future');
        $this->approval->reschedule($post, CarbonImmutable::now()->subMinute(), 'Owner');
    }

    public function test_every_change_is_in_the_activity_log(): void
    {
        $post = $this->goodPost();

        $this->approval->submit($post, 'Owner');
        $this->approve($post->fresh());
        $this->approval->reschedule($post->fresh(), CarbonImmutable::now()->addDays(3), 'Owner');
        $this->approval->withdraw($post->fresh(), 'Owner', 'changed my mind');

        $this->assertSame(
            ['sent for approval', 'approved', 'time changed', 'approval withdrawn'],
            Activity::where('subject_type', $post->getMorphClass())->where('subject_id', $post->id)->orderBy('id')->pluck('action')->all(),
        );
        $this->assertSame(PostState::AwaitingApproval, $post->fresh()->state);
    }
}
