<?php

namespace App\Domain\Posts;

use App\Enums\AccountStatus;
use App\Enums\Network;
use App\Enums\PostState;
use App\Models\Activity;
use App\Models\ConnectedAccount;
use App\Models\MediaItem;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\PostTarget;
use Carbon\CarbonImmutable;

/**
 * Every change of a post's approval: checking it, sending it for approval, approving it against the brand
 * rules, withdrawing it, and changing its time. Nothing is sent without approval (spec §4). Changing the
 * content of an approved post sends it back for approval; changing only its time does not.
 */
final class Approval
{
    /** @return list<Problem> */
    public function problems(Post $post, bool $checkTime = true): array
    {
        $post->load(['targets', 'media.mediaItem']);
        $problems = [];

        if ($post->targets->isEmpty()) {
            $problems[] = Problem::block('Choose Facebook, Instagram or both.');
        }

        if ($post->scheduled_at === null) {
            $problems[] = Problem::block('Pick a time for the post.');
        } elseif ($checkTime && $post->scheduled_at->lessThanOrEqualTo(CarbonImmutable::now())) {
            $problems[] = Problem::block('The time has passed; pick a time in the future.');
        }

        foreach (AiLabel::unanswered($post) as $item) {
            /** @var MediaItem $item */
            $problems[] = Problem::block("Answer 'shows people' for '{$item->fileName()}' in the Library (it is AI-made).");
        }

        $files = $post->media->map(fn (PostMedia $media): array => $media->toRuleFile())->all();
        foreach ($post->targets as $target) {
            /** @var PostTarget $target */
            $prefix = self::prefix($target->network);
            if (! $this->connected($post, $target->network)) {
                $what = $target->network === Network::FacebookPage ? 'the Facebook Page' : 'Instagram';
                $problems[] = Problem::block("{$prefix}connect {$what} on the Meta page first.");
            }
            $target->setRelation('post', $post);
            foreach (MediaRules::check($target->type, $files, CaptionBuilder::for($target), $target->link) as $problem) {
                $problems[] = $problem->withPrefix($prefix);
            }
        }

        return $problems;
    }

    public function submit(Post $post, string $by): void
    {
        if ($post->state !== PostState::Draft) {
            return;
        }
        $post->update(['state' => PostState::AwaitingApproval]);
        Activity::record($post->brand, $post, 'sent for approval', [], $by);
    }

    /** @param list<string> $ticked the checklist items the owner ticked */
    public function approve(Post $post, array $ticked, string $by): void
    {
        if (! in_array($post->state, [PostState::Draft, PostState::AwaitingApproval], true)) {
            throw new ApprovalRefused('Only a draft or a post waiting for approval can be approved.');
        }
        $required = $post->brand->approvalChecklist();
        $given = array_values(array_unique(array_map('strval', $ticked)));
        sort($given);
        $wanted = $required;
        sort($wanted);
        if ($given !== $wanted) {
            throw new ApprovalRefused('Tick every brand rule before approving.');
        }
        foreach ($this->problems($post) as $problem) {
            if ($problem->blocks()) {
                throw new ApprovalRefused("This post can't be approved yet: {$problem->message}");
            }
        }

        $post->update([
            'state' => PostState::Approved,
            'approved_at' => CarbonImmutable::now(),
            'approved_by' => $by,
            'checklist' => array_map(fn (string $item): array => ['item' => $item, 'ticked' => true], $required),
            'approved_fingerprint' => $this->fingerprint($post),
        ]);
        Activity::record($post->brand, $post, 'approved', ['checklist' => $required], $by);
    }

    public function withdraw(Post $post, string $by, string $reason): void
    {
        if ($post->state !== PostState::Approved) {
            return;
        }
        // Part 3 (SM-010) also takes back a post already handed to Facebook here.
        $post->update([
            'state' => PostState::AwaitingApproval,
            'approved_at' => null,
            'approved_by' => null,
            'checklist' => null,
            'approved_fingerprint' => null,
        ]);
        Activity::record($post->brand, $post, 'approval withdrawn', ['reason' => $reason], $by);
    }

    /** Sends an approved post back for approval if its content changed since it was approved. */
    public function reopenIfChanged(Post $post, string $by): bool
    {
        if ($post->state !== PostState::Approved || $this->fingerprint($post) === $post->approved_fingerprint) {
            return false;
        }
        $this->withdraw($post, $by, 'the post changed');

        return true;
    }

    /** Changes only the time; an approved post stays approved. */
    public function reschedule(Post $post, CarbonImmutable $utc, string $by): void
    {
        if ($utc->lessThanOrEqualTo(CarbonImmutable::now())) {
            throw new ApprovalRefused('Pick a time in the future.');
        }
        $zone = $post->brand->timezone ?: (string) config('smm.display_timezone');
        $from = $post->scheduled_at?->setTimezone($zone)->format('j M Y, H:i');
        $post->update(['scheduled_at' => $utc]);
        Activity::record($post->brand, $post, 'time changed', ['from' => $from, 'to' => $utc->setTimezone($zone)->format('j M Y, H:i')], $by);
    }

    /**
     * What approval covers: the caption, each network's version as it will be sent (its final caption, with the AI
     * line) and the media in order, plus whether the AI label applies. Not the time.
     */
    public function fingerprint(Post $post): string
    {
        $post->load(['targets', 'media']);
        $targets = $post->targets
            ->sortBy(fn (PostTarget $t): string => $t->network->value)
            ->map(function (PostTarget $t) use ($post): array {
                $t->setRelation('post', $post);

                return [$t->network->value, $t->type->value, (string) $t->caption, (string) $t->link, CaptionBuilder::for($t)];
            })
            ->values()
            ->all();
        $media = $post->media
            ->sortBy('position')
            ->map(fn (PostMedia $m): array => [$m->media_item_id, $m->hash])
            ->values()
            ->all();

        return hash('sha256', json_encode([(string) $post->caption, $targets, $media, AiLabel::applies($post)]));
    }

    /**
     * The gate the publisher (part 3) checks right before handing a post over or publishing it: approved, nothing
     * changed since, and nothing that blocks it now (its time passing is not a reason).
     */
    public function stillApproved(Post $post): bool
    {
        return $post->state === PostState::Approved
            && $post->approved_fingerprint !== null
            && hash_equals($post->approved_fingerprint, $this->fingerprint($post))
            && ! MediaRules::hasBlock($this->problems($post, checkTime: false));
    }

    /**
     * After a Library file's labels change: every approved post using it whose content changed (for example the AI
     * line) or that is now blocked (for example "shows people" not answered) goes back for approval.
     *
     * @return int how many posts went back
     */
    public function recheckFor(MediaItem $item, string $by): int
    {
        $count = 0;
        $posts = Post::query()
            ->where('state', PostState::Approved)
            ->whereHas('media', fn ($q) => $q->where('media_item_id', $item->id))
            ->get();
        foreach ($posts as $post) {
            if (! $this->stillApproved($post)) {
                $this->withdraw($post, $by, "a Library file it uses changed: '{$item->fileName()}'");
                $count++;
            }
        }

        return $count;
    }

    private function connected(Post $post, Network $network): bool
    {
        return ConnectedAccount::query()
            ->where('brand_id', $post->brand_id)
            ->where('network', $network)
            ->whereIn('status', [AccountStatus::Connected, AccountStatus::Expiring])
            ->exists();
    }

    public static function prefix(Network $network): string
    {
        return $network === Network::FacebookPage ? 'Facebook: ' : 'Instagram: ';
    }
}
