<?php

namespace Tests\Support;

/**
 * Writes a minimal but well-formed MP4 (ISO base media) file for tests: one video track with the given
 * size, length, frame rate and codec, and the moov box before or after the media data.
 */
final class Mp4Fixture
{
    public static function make(string $path, int $width, int $height, float $seconds, int $fps = 30, string $codec = 'avc1', bool $moovFirst = true): void
    {
        $frames = max(1, (int) round($seconds * $fps));
        $timescale = $fps * 100;
        $mediaDuration = $frames * 100;
        $movieDuration = (int) round($seconds * 1000);

        $ftyp = self::box('ftyp', 'isom'.pack('N', 0x200).'isomiso2avc1mp41');
        $mdat = self::box('mdat', str_repeat("\0", $frames));

        $build = function (int $chunkOffset) use ($width, $height, $movieDuration, $timescale, $mediaDuration, $frames, $fps, $codec): string {
            $matrix = pack('N9', 0x00010000, 0, 0, 0, 0x00010000, 0, 0, 0, 0x40000000);
            $mvhd = self::full('mvhd', 0, 0, pack('NNNN', 0, 0, 1000, $movieDuration).pack('Nn', 0x00010000, 0x0100)
                .str_repeat("\0", 10).$matrix.str_repeat("\0", 24).pack('N', 2));
            $tkhd = self::full('tkhd', 0, 3, pack('NNNNN', 0, 0, 1, 0, $movieDuration).str_repeat("\0", 8)
                .pack('nnnn', 0, 0, 0, 0).$matrix.pack('NN', $width << 16, $height << 16));
            $mdhd = self::full('mdhd', 0, 0, pack('NNNN', 0, 0, $timescale, $mediaDuration).pack('nn', 0x55C4, 0));
            $hdlr = self::full('hdlr', 0, 0, pack('N', 0).'vide'.str_repeat("\0", 12)."VideoHandler\0");
            $vmhd = self::full('vmhd', 0, 1, str_repeat("\0", 8));
            $dinf = self::box('dinf', self::full('dref', 0, 0, pack('N', 1).self::full('url ', 0, 1, '')));
            $entry = self::box($codec, str_repeat("\0", 6).pack('n', 1).str_repeat("\0", 16).pack('nn', $width, $height)
                .pack('NN', 0x00480000, 0x00480000).pack('N', 0).pack('n', 1).str_repeat("\0", 32).pack('n', 0x0018).pack('n', 0xFFFF));
            $stsd = self::full('stsd', 0, 0, pack('N', 1).$entry);
            $stts = self::full('stts', 0, 0, pack('NNN', 1, $frames, intdiv($timescale, $fps)));
            $stsc = self::full('stsc', 0, 0, pack('NNNN', 1, 1, $frames, 1));
            $stsz = self::full('stsz', 0, 0, pack('NN', 1, $frames));
            $stco = self::full('stco', 0, 0, pack('NN', 1, $chunkOffset));
            $stbl = self::box('stbl', $stsd.$stts.$stsc.$stsz.$stco);
            $minf = self::box('minf', $vmhd.$dinf.$stbl);
            $mdia = self::box('mdia', $mdhd.$hdlr.$minf);
            $trak = self::box('trak', $tkhd.$mdia);

            return self::box('moov', $mvhd.$trak);
        };

        if ($moovFirst) {
            $moovLength = strlen($build(0));
            $moov = $build(strlen($ftyp) + $moovLength + 8);
            $bytes = $ftyp.$moov.$mdat;
        } else {
            $moov = $build(strlen($ftyp) + 8);
            $bytes = $ftyp.$mdat.$moov;
        }

        file_put_contents($path, $bytes);
    }

    private static function box(string $type, string $payload): string
    {
        return pack('N', 8 + strlen($payload)).$type.$payload;
    }

    private static function full(string $type, int $version, int $flags, string $payload): string
    {
        return self::box($type, chr($version).substr(pack('N', $flags), 1).$payload);
    }
}
