<?php

namespace App\Domain\Publishing;

use App\Models\PostTarget;
use Filament\Notifications\Notification;

/** Tells the owner what happened to a post: an in-app notice (the bell), and a Windows pop-up where available. */
class Notifier
{
    public function published(PostTarget $target, string $text): void
    {
        $this->notify('published', $target, $text);
    }

    public function failed(PostTarget $target, string $text): void
    {
        $this->notify('failed', $target, $text);
    }

    public function late(PostTarget $target, string $text): void
    {
        $this->notify('late', $target, $text);
    }

    public function missed(PostTarget $target, string $text): void
    {
        $this->notify('missed', $target, $text);
    }

    public function notify(string $kind, PostTarget $target, string $text): void
    {
        $brand = $target->post->brand;
        $notification = Notification::make()->title(match ($kind) {
            'published' => 'Post published',
            'late' => 'Post is late',
            'missed' => 'Post missed',
            default => 'Post could not be sent',
        })->body($text);
        match ($kind) {
            'published' => $notification->success(),
            'late' => $notification->warning(),
            default => $notification->danger(),
        };
        $notification->sendToDatabase($brand->users);
        Popup::show((string) $notification->getTitle(), $text);
    }
}
