<?php

namespace Tests\Unit;

use App\Domain\Library\NameLabels;
use App\Enums\MediaKind;
use App\Enums\ProductRange;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class NameLabelsTest extends TestCase
{
    public static function names(): array
    {
        // Real folder and file names from Markting Social Media.
        return [
            'chatgpt image' => ['CD AI/ChatGPT Image Jun 5, 2026.png', 1, MediaKind::AiImage, null, true],
            'gemini image' => ['Images/Gemini_Generated_Image_x1.png', 1, MediaKind::AiImage, null, true],
            'finished raisin video' => ['5/Sangli raisins final.mp4', 2, MediaKind::FinishedVideo, ProductRange::Raisins, false],
            'only video in folder' => ['10/Custromer out side.mp4', 1, MediaKind::FinishedVideo, null, false],
            'one of several clips' => ['GM/clip2.mp4', 3, MediaKind::Clip, null, false],
            'hindi voice-over mp3' => ['NRI target/voice hindi.mp3', 1, MediaKind::VoiceOver, ProductRange::ExportServices, false],
            'music mp3' => ['Music/track.mp3', 1, MediaKind::Music, null, false],
            'wav voice-over' => ['Second/Moggies International your trusted Partener !.wav', 1, MediaKind::VoiceOver, null, false],
            'wav music' => ['Music/bgm loop.wav', 1, MediaKind::Music, null, false],
            'pulp photo' => ['10/frozen mango pulp.jpg', 1, MediaKind::Photo, ProductRange::FruitPulp, false],
            'frozen veg photo' => ['Products/frozen peas pack.webp', 1, MediaKind::Photo, ProductRange::FrozenVegetables, false],
            'script' => ['Via Sohar/script.docx', 1, MediaKind::Script, null, false],
            'raisins is not ai' => ['Raisins/raisins close up.jpg', 1, MediaKind::Photo, ProductRange::Raisins, false],
        ];
    }

    #[DataProvider('names')]
    public function test_guess(string $path, int $videos, MediaKind $kind, ?ProductRange $range, bool $ai): void
    {
        $guess = NameLabels::guess($path, $videos);

        $this->assertSame($kind, $guess['kind']);
        $this->assertSame($range, $guess['product_range']);
        $this->assertSame($ai, $guess['ai_made']);
    }
}
