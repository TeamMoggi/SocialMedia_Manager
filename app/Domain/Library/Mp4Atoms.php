<?php

namespace App\Domain\Library;

/**
 * Instagram needs the "moov" box (the video's index) at the front of an MP4 or MOV file. This walks the
 * file's top-level boxes only (no decoding) to see whether moov comes before the media data (mdat).
 */
final class Mp4Atoms
{
    /** True if moov comes before mdat, false if after, null if neither is found or the file can't be read. */
    public static function moovFirst(string $path): ?bool
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return null;
        }

        try {
            $size = filesize($path) ?: 0;
            $offset = 0;
            while ($offset + 8 <= $size) {
                if (fseek($handle, $offset) !== 0) {
                    return null;
                }
                $header = fread($handle, 8);
                if ($header === false || strlen($header) < 8) {
                    return null;
                }
                $boxSize = unpack('N', substr($header, 0, 4))[1];
                $type = substr($header, 4, 4);
                $headerLength = 8;
                if ($boxSize === 1) {
                    $large = fread($handle, 8);
                    if ($large === false || strlen($large) < 8) {
                        return null;
                    }
                    $boxSize = unpack('J', $large)[1];
                    $headerLength = 16;
                } elseif ($boxSize === 0) {
                    $boxSize = $size - $offset;
                }

                if ($type === 'moov') {
                    return true;
                }
                if ($type === 'mdat') {
                    return false;
                }
                if ($boxSize < $headerLength) {
                    return null;
                }
                $offset += $boxSize;
            }

            return null;
        } finally {
            fclose($handle);
        }
    }
}
