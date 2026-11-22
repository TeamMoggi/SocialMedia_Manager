<?php

namespace Tests\Unit;

use App\Domain\Posts\CaptionStats;
use Tests\TestCase;

class CaptionStatsTest extends TestCase
{
    public function test_counts_characters_hashtags_and_mentions(): void
    {
        $caption = 'Raisins #Sangli #export @moggies_international 🍇';

        $stats = CaptionStats::of($caption);

        $this->assertSame(mb_strlen($caption), $stats['chars']);
        $this->assertSame(2, $stats['hashtags']);
        $this->assertSame(1, $stats['mentions']);
    }

    public function test_an_email_is_not_a_mention(): void
    {
        $this->assertSame(0, CaptionStats::of('Write to info@moggiesinternational.com')['mentions']);
    }

    public function test_hint_in_plain_words(): void
    {
        $this->assertSame('12 / 2,200 characters · 1 hashtag · 0 mentions', CaptionStats::hint('Hello #India'));
    }
}
