<?php

namespace App\Domain\Library;

use App\Enums\MediaKind;
use App\Enums\ProductRange;

/** First labels for a newly found file, guessed from its folder and file name. The owner or Claude corrects them later. */
final class NameLabels
{
    private const AI = '/chatgpt|gemini|dall|midjourney|firefly|imagen|generated|\bai\b/i';

    /** In order: the first match wins ("frozen mango pulp" is pulp, not vegetables). */
    private const RANGES = [
        ['/raisin|kishmish|kismis|sangli|malayar|manik/i', ProductRange::Raisins],
        ['/pulp|mango|guava|papaya|pineapple|puree/i', ProductRange::FruitPulp],
        ['/frozen|peas|sweet ?corn|okra|cauliflower|\bveg/i', ProductRange::FrozenVegetables],
        ['/export|service|iec|apeda|rcmc|\bnri\b|document|customs|exim|training/i', ProductRange::ExportServices],
    ];

    /** @return array{kind: MediaKind, product_range: ?ProductRange, ai_made: bool} */
    public static function guess(string $relativePath, int $videosInFolder = 1): array
    {
        $path = str_replace('\\', '/', $relativePath);
        $name = basename($path);
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $ai = (bool) preg_match(self::AI, $path);

        $range = null;
        foreach (self::RANGES as [$pattern, $candidate]) {
            if (preg_match($pattern, $path)) {
                $range = $candidate;
                break;
            }
        }

        $kind = match (true) {
            in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true) => $ai ? MediaKind::AiImage : MediaKind::Photo,
            in_array($extension, ['mp4', 'mov', 'webm'], true) => preg_match('/final|export|output|ready/i', $name) || $videosInFolder === 1
                ? MediaKind::FinishedVideo : MediaKind::Clip,
            $extension === 'wav' => preg_match('/music|bgm|song/i', $path) ? MediaKind::Music : MediaKind::VoiceOver,
            $extension === 'mp3' => preg_match('/voice|\bvo\b|narration|hindi/i', $path) ? MediaKind::VoiceOver : MediaKind::Music,
            default => MediaKind::Script,
        };

        return ['kind' => $kind, 'product_range' => $range, 'ai_made' => $ai];
    }
}
