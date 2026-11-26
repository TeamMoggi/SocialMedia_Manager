<?php

namespace App\Domain\Publishing;

use App\Enums\Network;
use App\Enums\PostState;
use App\Enums\TargetState;
use App\Models\PostTarget;
use App\Support\PublishGuard;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * One-time Windows tasks that wake the laptop and run smm:publish-due at the moments something has to happen: for
 * Instagram a lead time before the post and at the post time, for Facebook at the post time. The 21 Nov 2026 trial
 * showed an Interactive, wake-to-run task in \SocialMediaManager\ needs no administrator prompt. The script
 * (scripts\windows\wake-at.ps1) makes the missing tasks and removes the ones no longer needed. Only on Windows,
 * in the App copy, and never in tests; a problem here never stops the caller.
 */
final class WakeTasks
{
    private readonly ?Closure $run;

    private readonly bool $enabled;

    public function __construct(?Closure $run = null, ?bool $enabled = null)
    {
        $this->run = $run;
        $this->enabled = $enabled ?? (PHP_OS_FAMILY === 'Windows' && PublishGuard::allowsLive(base_path()) && ! app()->runningUnitTests());
    }

    /** @return list<CarbonImmutable> distinct moments after $now, oldest first */
    public function times(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $planner = new TargetPlanner;
        $times = [];
        $targets = PostTarget::query()
            ->whereIn('state', [TargetState::Waiting, TargetState::Queued, TargetState::Failed])
            ->whereHas('post', fn ($q) => $q->where('state', PostState::Approved)->whereNotNull('scheduled_at'))
            ->with(['post.media'])
            ->get();
        foreach ($targets as $target) {
            /** @var PostTarget $target */
            $at = $target->post->scheduled_at;
            if ($target->state === TargetState::Failed) {
                if ($target->next_attempt_at !== null && ($target->work['permanent'] ?? false) !== true) {
                    $times[] = $target->next_attempt_at;
                }

                continue;
            }
            if ($target->network === Network::Instagram && $target->state === TargetState::Waiting) {
                $times[] = $at->subMinutes($planner->leadMinutes($target));
            }
            $times[] = $at;
        }

        $unique = [];
        foreach ($times as $time) {
            if ($time->greaterThan($now)) {
                $unique[$time->utc()->format('YmdHi')] = $time->utc()->startOfMinute();
            }
        }
        ksort($unique);

        return array_values($unique);
    }

    public function sync(?CarbonImmutable $now = null): void
    {
        if (! $this->enabled) {
            return;
        }
        try {
            $times = array_map(fn (CarbonImmutable $t): string => $t->toIso8601String(), $this->times($now));
            $this->run !== null ? ($this->run)($times) : $this->runScript($times);
        } catch (Throwable $e) {
            Log::warning('Wake-up tasks could not be updated: '.$e->getMessage());
        }
    }

    /** @param list<string> $times */
    private function runScript(array $times): void
    {
        $script = base_path('scripts'.DIRECTORY_SEPARATOR.'windows'.DIRECTORY_SEPARATOR.'wake-at.ps1');
        $process = new Process([
            'powershell.exe', '-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', $script, '-Times', implode(',', $times),
            '-Php', PHP_BINARY,
        ], base_path(), null, null, 60);
        $process->run();
        if (! $process->isSuccessful()) {
            throw new \RuntimeException(trim($process->getErrorOutput().$process->getOutput()) ?: 'wake-at.ps1 failed');
        }
    }
}
