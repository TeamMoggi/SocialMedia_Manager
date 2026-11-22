<?php

namespace App\Domain\Library;

use App\Domain\Posts\Approval;
use App\Enums\MediaKind;
use App\Enums\ProductRange;
use App\Models\Activity;
use App\Models\Brand;
use App\Models\MediaGroup;
use App\Models\MediaItem;
use BackedEnum;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Claude's labelling pass (owner's OK, 18 Nov 2026): applies a labels file to the Library. It changes only
 * folder names and file labels (kind, range, AI-made, shows people, description), never posts, settings or
 * files, and logs every change as "by Claude". All or nothing: one invalid value and nothing is changed.
 *
 * {"source": "<optional source folder>", "groups": [{"folder": "5", "name": "..."}],
 *  "items": [{"path": "5/final.mp4", "kind": "...", "product_range": "...", "ai_made": false, "shows_people": false, "description": "..."}]}
 */
final class LabelImport
{
    /** @return array{groups: int, items: int, unknown: list<string>} */
    public static function apply(Brand $brand, array $data, string $by = 'Claude'): array
    {
        $source = rtrim(trim((string) ($data['source'] ?? $brand->librarySources()[0])), '\\/');
        $errors = [];
        $unknown = [];
        $groupChanges = [];
        $itemChanges = [];

        foreach (array_values((array) ($data['groups'] ?? [])) as $i => $entry) {
            $name = is_array($entry) && is_string($entry['name'] ?? null) ? trim($entry['name']) : '';
            if (! is_array($entry) || ! isset($entry['folder']) || $name === '' || mb_strlen($name) > 150) {
                $errors[] = 'Folder entry '.($i + 1)." needs a 'folder' and a 'name' of up to 150 characters.";

                continue;
            }
            $folder = trim(str_replace('\\', '/', (string) $entry['folder']), '/');
            $group = MediaGroup::query()->where('brand_id', $brand->id)->where('source_root', $source)->where('folder', $folder)->first();
            if ($group === null) {
                $unknown[] = "folder {$folder}";

                continue;
            }
            $groupChanges[] = [$group, $name];
        }

        foreach (array_values((array) ($data['items'] ?? [])) as $i => $entry) {
            if (! is_array($entry) || ! is_string($entry['path'] ?? null) || trim($entry['path']) === '') {
                $errors[] = 'File entry '.($i + 1)." needs a 'path'.";

                continue;
            }
            $path = trim(str_replace('\\', '/', $entry['path']), '/');
            $changes = self::changes($entry, $path, $errors);
            $item = MediaItem::query()->where('brand_id', $brand->id)->where('source_root', $source)->where('relative_path', $path)->first();
            if ($item === null) {
                $unknown[] = $path;

                continue;
            }
            $itemChanges[] = [$item, $changes];
        }

        if ($errors !== []) {
            throw new InvalidArgumentException(implode("\n", $errors));
        }

        return DB::transaction(function () use ($brand, $by, $groupChanges, $itemChanges, $unknown): array {
            $groups = 0;
            foreach ($groupChanges as [$group, $name]) {
                if ($group->name !== $name) {
                    $group->update(['name' => $name]);
                    Activity::record($brand, $group, 'folder renamed', ['name' => $name], $by);
                    $groups++;
                }
            }
            $items = 0;
            foreach ($itemChanges as [$item, $changes]) {
                $item->fill($changes);
                $dirty = $item->getDirty();
                if ($dirty === []) {
                    continue;
                }
                $item->save();
                Activity::record($brand, $item, 'labels changed', array_map(fn ($v) => $v instanceof BackedEnum ? $v->value : $v, $dirty), $by);
                // An approved post using this file goes back for approval if what it will send changed.
                app(Approval::class)->recheckFor($item, $by);
                $items++;
            }

            return ['groups' => $groups, 'items' => $items, 'unknown' => $unknown];
        });
    }

    private static function changes(array $entry, string $path, array &$errors): array
    {
        $changes = [];
        if (array_key_exists('kind', $entry)) {
            $kind = is_string($entry['kind']) ? MediaKind::tryFrom($entry['kind']) : null;
            $kind === null
                ? $errors[] = "'".self::show($entry['kind'])."' is not a kind ({$path}). Kinds: ".implode(', ', array_map(fn ($c) => $c->value, MediaKind::cases())).'.'
                : $changes['kind'] = $kind;
        }
        if (array_key_exists('product_range', $entry)) {
            if ($entry['product_range'] === null) {
                $changes['product_range'] = null;
            } else {
                $range = is_string($entry['product_range']) ? ProductRange::tryFrom($entry['product_range']) : null;
                $range === null
                    ? $errors[] = "'".self::show($entry['product_range'])."' is not a product range ({$path}). Ranges: ".implode(', ', array_map(fn ($c) => $c->value, ProductRange::cases())).'.'
                    : $changes['product_range'] = $range;
            }
        }
        if (array_key_exists('ai_made', $entry)) {
            is_bool($entry['ai_made']) ? $changes['ai_made'] = $entry['ai_made'] : $errors[] = "ai_made must be true or false ({$path}).";
        }
        if (array_key_exists('shows_people', $entry)) {
            is_bool($entry['shows_people']) || $entry['shows_people'] === null
                ? $changes['shows_people'] = $entry['shows_people']
                : $errors[] = "shows_people must be true, false or null ({$path}).";
        }
        if (array_key_exists('description', $entry)) {
            $text = $entry['description'];
            if ($text !== null && ! is_string($text)) {
                $errors[] = "description must be text ({$path}).";
            } elseif (is_string($text) && mb_strlen($text) > 1000) {
                $errors[] = "description is longer than 1,000 characters ({$path}).";
            } else {
                $changes['description'] = blank($text) ? null : trim($text);
            }
        }

        return $changes;
    }

    private static function show(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : json_encode($value);
    }
}
