<?php

namespace App\Filament\Pages;

use App\Domain\Posts\Approval;
use App\Domain\Posts\ApprovalRefused;
use App\Enums\Network;
use App\Enums\PostState;
use App\Enums\TargetState;
use App\Filament\Resources\Posts\PostResource;
use App\Models\Brand;
use App\Models\Post;
use App\Models\PostTarget;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Str;
use Throwable;

/**
 * Week and month calendar of the posts (FullCalendar 6.1.15, vendored in public/vendor). Dragging a post
 * changes only its time, so an approved post stays approved.
 *
 * Times: the calendar runs in "UTC" and is given the brand's wall-clock times written as UTC strings
 * ("2026-12-04T00:30:00Z" means 00:30 in Kolkata), so it shows brand time in any browser and hands the
 * same form back when a post is dropped.
 */
class Calendar extends Page
{
    protected string $view = 'filament.pages.calendar';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static ?string $navigationLabel = 'Calendar';

    protected static ?string $title = 'Calendar';

    protected static ?int $navigationSort = 11;

    /** @return list<array{id: int, title: string, start: string, color: string, url: string, editable: bool}> */
    public function calendarEvents(string $from, string $to): array
    {
        $zone = $this->zone();
        $start = $this->fromWallClock($from);
        $end = $this->fromWallClock($to);
        if ($start === null || $end === null) {
            return [];
        }
        $now = CarbonImmutable::now();

        return Post::query()
            ->with('targets')
            ->where('brand_id', $this->brand()->id)
            ->whereNotNull('scheduled_at')
            ->whereBetween('scheduled_at', [$start, $end])
            ->orderBy('scheduled_at')
            ->get()
            ->map(fn (Post $post): array => [
                'id' => $post->id,
                'title' => $this->title($post),
                'start' => $post->scheduled_at->setTimezone($zone)->format('Y-m-d\TH:i:s').'Z',
                'color' => $this->colour($post),
                'url' => PostResource::getUrl('edit', ['record' => $post, 'tenant' => $this->brand()]),
                'editable' => $post->scheduled_at->greaterThan($now),
            ])
            ->values()
            ->all();
    }

    /** @return array{ok: bool, message: string} */
    public function movePost(int $postId, string $wallClock): array
    {
        $post = Post::query()->where('brand_id', $this->brand()->id)->find($postId);
        if ($post === null) {
            return ['ok' => false, 'message' => 'This post is not in this brand.'];
        }
        $utc = $this->fromWallClock($wallClock);
        if ($utc === null) {
            return ['ok' => false, 'message' => 'That time could not be read; try again.'];
        }

        try {
            app(Approval::class)->reschedule($post, $utc, auth()->user()?->name ?? 'owner');
        } catch (ApprovalRefused $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }

        $note = $post->state === PostState::Approved ? ' It stays approved.' : '';

        return ['ok' => true, 'message' => 'Moved to '.$utc->setTimezone($this->zone())->format('D j M, H:i').'.'.$note];
    }

    /** The brand's wall clock written as UTC (or with no zone) -> the real moment in UTC. */
    private function fromWallClock(string $value): ?CarbonImmutable
    {
        $clean = preg_replace('/(Z|[+-]\d{2}:?\d{2})$/', '', trim($value));
        try {
            return CarbonImmutable::parse((string) $clean, $this->zone())->utc();
        } catch (Throwable) {
            return null;
        }
    }

    /** Sending shows in the colour: red (failed or missed), amber (late), green (published), blue (handed over or queued), else the post's state. */
    private function colour(Post $post): string
    {
        $states = $post->targets->map(fn (PostTarget $t): TargetState => $t->state);
        return match (true) {
            $states->contains(fn (TargetState $s): bool => in_array($s, [TargetState::Failed, TargetState::Missed], true)) => '#dc2626',
            $states->contains(TargetState::Late) => '#d97706',
            $states->contains(TargetState::Published) => '#16a34a',
            $states->contains(fn (TargetState $s): bool => in_array($s, [TargetState::HandedToFacebook, TargetState::Queued], true)) => '#2563eb',
            default => $post->state->color(),
        };
    }

    private function title(Post $post): string
    {
        $first = trim(strtok((string) $post->caption, "\n") ?: '') ?: 'Untitled post';
        $networks = $post->targets
            ->sortBy(fn (PostTarget $t): string => $t->network->value)
            ->map(fn (PostTarget $t): string => $t->network === Network::FacebookPage ? 'FB' : 'IG')
            ->implode(' · ');

        return Str::limit($first, 40).($networks !== '' ? ' · '.$networks : '');
    }

    private function brand(): Brand
    {
        /** @var Brand $brand */
        $brand = Filament::getTenant();

        return $brand;
    }

    private function zone(): string
    {
        return $this->brand()->timezone ?: (string) config('smm.display_timezone');
    }

    protected function getViewData(): array
    {
        return [
            'now' => CarbonImmutable::now($this->zone())->format('Y-m-d\TH:i:s').'Z',
            'zone' => $this->zone(),
            'states' => PostState::cases(),
        ];
    }
}
