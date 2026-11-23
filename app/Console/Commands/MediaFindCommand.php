<?php

namespace App\Console\Commands;

use App\Domain\Posts\MediaRules;
use App\Enums\MediaKind;
use App\Models\Brand;
use App\Models\MediaItem;
use Illuminate\Console\Command;

/**
 * Claude's way into the Library from a laptop session: finds files by words in their folder name, path, labels
 * and description, and prints each one's full path and facts so it can be picked for a task.
 */
class MediaFindCommand extends Command
{
    protected $signature = 'smm:media:find
        {words?* : Words to look for; every word must match}
        {--kind= : Only this kind (photo, ai_image, finished_video, clip, voice_over, music, script)}
        {--range= : Only this product range (raisins, frozen_vegetables, fruit_pulp, export_services, general)}
        {--attachable : Only photos and videos that can go in a post}
        {--include-missing : Also files that are no longer in their folder}
        {--limit=40 : At most this many files}
        {--brand=moggies-international : The brand (its web address name)}';

    protected $description = 'Library: find photos, videos, voice-overs, music and scripts, with full paths and facts';

    public function handle(): int
    {
        $brand = Brand::where('slug', $this->option('brand'))->first();
        if ($brand === null) {
            $this->error("No brand '{$this->option('brand')}'.");

            return self::FAILURE;
        }

        $query = MediaItem::query()->with('group')->withCount('postMedia')->where('brand_id', $brand->id);
        foreach ((array) $this->argument('words') as $word) {
            // "_" and "%" are left as they are: SQLite needs an ESCAPE clause to escape them, and as wildcards they
            // still match themselves ("Gemini_Generated" finds Gemini_Generated_Image_x1.png).
            $like = '%'.$word.'%';
            $query->where(fn ($q) => $q->where('relative_path', 'like', $like)
                ->orWhere('description', 'like', $like)
                ->orWhere('kind', 'like', $like)
                ->orWhere('product_range', 'like', $like)
                ->orWhereHas('group', fn ($g) => $g->where('name', 'like', $like)));
        }
        if ($kind = $this->option('kind')) {
            $query->where('kind', $kind);
        }
        if ($range = $this->option('range')) {
            $query->where('product_range', $range);
        }
        if ($this->option('attachable')) {
            $query->whereIn('kind', MediaKind::attachableValues());
        }
        if (! $this->option('include-missing')) {
            $query->whereNull('missing_since');
        }
        $limit = max(1, (int) $this->option('limit'));
        $items = $query->orderByDesc('mtime')->limit($limit)->get();

        if ($items->isEmpty()) {
            $this->line('No files match.');

            return self::SUCCESS;
        }

        foreach ($items as $item) {
            $this->printItem($item);
        }
        $count = $items->count();
        $this->line(($count === 1 ? '1 file found.' : "{$count} files found.").($count === $limit ? ' (Limit reached: use --limit for more.)' : ''));

        return self::SUCCESS;
    }

    private function printItem(MediaItem $item): void
    {
        $people = match ($item->shows_people) {
            null => 'not answered',
            true => 'yes',
            false => 'no',
        };
        $this->line($item->fullPath());
        $this->line('  Folder: '.($item->group?->name ?? '').' · Kind: '.$item->kind->label()
            .' · Range: '.($item->product_range?->label() ?? 'not set')
            .' · AI-made: '.($item->ai_made ? 'yes' : 'no').' · Shows people: '.$people
            .($item->isMissing() ? ' · MISSING since '.$item->missing_since->format('j M Y') : ''));

        $facts = [number_format($item->bytes / 1048576, 1).' MB'];
        if ($item->width && $item->height) {
            $facts[] = "{$item->width}×{$item->height}";
        }
        if ($item->duration !== null) {
            $facts[] = sprintf('%d:%02d', intdiv((int) round($item->duration), 60), (int) round($item->duration) % 60);
        }
        if ($item->codec) {
            $facts[] = $item->codec;
        }
        $facts[] = 'used in '.$item->post_media_count.($item->post_media_count === 1 ? ' post' : ' posts');
        $this->line('  '.implode(' · ', $facts));

        $badges = MediaRules::badges($item);
        if ($badges !== []) {
            $fits = array_keys(array_filter($badges));
            $not = array_keys(array_filter($badges, fn (bool $ok): bool => ! $ok));
            $this->line('  Fits: '.($fits === [] ? 'none' : implode(', ', $fits)).($not !== [] ? ' · Does not fit: '.implode(', ', $not) : ''));
        }
        if (filled($item->description)) {
            $this->line('  Description: '.$item->description);
        }
    }
}
