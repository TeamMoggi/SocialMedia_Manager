<?php

namespace App\Filament\Resources\Posts\Pages;

use App\Domain\Posts\Approval;
use App\Domain\Posts\ApprovalRefused;
use App\Enums\PostState;
use App\Filament\Resources\Posts\PostResource;
use App\Filament\Resources\Posts\Schemas\PostForm;
use App\Models\Activity;
use App\Models\Post;
use Carbon\CarbonImmutable;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Str;

class EditPost extends EditRecord
{
    protected static string $resource = PostResource::class;

    /** The whole save (post, versions, photos and videos) is one database transaction: it succeeds or changes nothing. */
    protected ?bool $hasDatabaseTransactions = true;

    private array $targetData = [];

    /** A changed time, applied through Approval::reschedule (the same rules and log as the calendar). */
    private ?array $timeChange = null;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        return $data + PostForm::fillTargets($this->post());
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        [$data, $this->targetData] = PostForm::splitTargets($data);

        $this->timeChange = null;
        if (array_key_exists('scheduled_at', $data)) {
            $new = blank($data['scheduled_at']) ? null : CarbonImmutable::parse((string) $data['scheduled_at'], (string) config('app.timezone'))->utc();
            $old = $this->post()->scheduled_at;
            if (! ($new === null && $old === null) && ! ($new !== null && $old !== null && $new->equalTo($old))) {
                $this->timeChange = [$new];
            }
            unset($data['scheduled_at']);
        }

        return $data;
    }

    protected function afterSave(): void
    {
        $post = $this->post();
        PostForm::saveTargets($post, $this->targetData);
        if ($this->timeChange !== null) {
            [$new] = $this->timeChange;
            if ($new === null) {
                $post->update(['scheduled_at' => null]);
                Activity::record($post->brand, $post, 'time removed', [], $this->who());
                app(Approval::class)->withdraw($post->fresh(), $this->who(), 'the time was removed');
            } else {
                app(Approval::class)->reschedule($post->fresh(), $new, $this->who());
            }
        }
        if (app(Approval::class)->reopenIfChanged($post->fresh(), $this->who())) {
            Notification::make()->title('The post changed, so it needs your approval again')->warning()->send();
        }
        $this->getRecord()->refresh();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('submit')
                ->label('Send for approval')
                ->icon(Heroicon::OutlinedPaperAirplane)
                ->visible(fn (): bool => $this->post()->state === PostState::Draft)
                ->action(function (): void {
                    app(Approval::class)->submit($this->post(), $this->who());
                    $this->getRecord()->refresh();
                    Notification::make()->title('Sent for approval')->success()->send();
                }),
            Action::make('approve')
                ->label('Approve')
                ->icon(Heroicon::OutlinedCheckBadge)
                ->color('success')
                ->visible(fn (): bool => in_array($this->post()->state, [PostState::Draft, PostState::AwaitingApproval], true))
                ->disabled(fn (): bool => PostResource::hasBlocks($this->post()))
                ->tooltip(fn (): ?string => PostResource::hasBlocks($this->post()) ? 'Fix the red checks below first.' : null)
                ->modalHeading('Approve this post')
                ->modalDescription('Approves the post as last saved. Nothing is published until its time.')
                ->modalSubmitActionLabel('Approve')
                ->schema(fn (): array => [
                    CheckboxList::make('ticked')
                        ->label('Brand rules: tick each one you have checked')
                        ->options(fn (): array => array_combine($rules = $this->post()->brand->approvalChecklist(), $rules))
                        ->required()
                        ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                            $rules = $this->post()->brand->approvalChecklist();
                            if (count(array_intersect((array) $value, $rules)) !== count($rules)) {
                                $fail('Tick every brand rule before approving.');
                            }
                        }),
                ])
                ->action(function (array $data, Action $action): void {
                    try {
                        app(Approval::class)->approve($this->post(), (array) $data['ticked'], $this->who());
                    } catch (ApprovalRefused $e) {
                        Notification::make()->title($e->getMessage())->danger()->send();
                        $action->halt();
                    }
                    $this->getRecord()->refresh();
                    Notification::make()->title('Approved')->success()->send();
                }),
            Action::make('withdraw')
                ->label('Withdraw approval')
                ->icon(Heroicon::OutlinedArrowUturnLeft)
                ->color('warning')
                ->visible(fn (): bool => $this->post()->state === PostState::Approved)
                ->requiresConfirmation()
                ->action(function (): void {
                    app(Approval::class)->withdraw($this->post(), $this->who(), 'withdrawn by you');
                    $this->getRecord()->refresh();
                    Notification::make()->title('Approval withdrawn')->success()->send();
                }),
            DeleteAction::make()
                ->before(function (Post $record): void {
                    Activity::record($record->brand, null, 'post deleted', ['caption' => Str::limit((string) $record->caption, 80)], $this->who());
                }),
        ];
    }

    private function post(): Post
    {
        /** @var Post $post */
        $post = $this->getRecord();

        return $post;
    }

    private function who(): string
    {
        return auth()->user()?->name ?? 'owner';
    }
}
