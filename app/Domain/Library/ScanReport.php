<?php

namespace App\Domain\Library;

/** What one library scan found, in counts, plus the files it could not read and the folders it could not reach. */
final readonly class ScanReport
{
    /**
     * @param  list<string>  $unreadable  relative paths skipped this time (locked, still being written)
     * @param  list<string>  $unreachable  source folders that were not there
     */
    public function __construct(
        public int $added = 0,
        public int $changed = 0,
        public int $moved = 0,
        public int $missing = 0,
        public int $unchanged = 0,
        public array $unreadable = [],
        public array $unreachable = [],
        public bool $skippedBusy = false,
    ) {}

    public static function busy(): self
    {
        return new self(skippedBusy: true);
    }

    public function summary(): string
    {
        if ($this->skippedBusy) {
            return 'Library: a scan is already running; nothing to do.';
        }

        $text = "Library: {$this->added} new, {$this->changed} changed, {$this->moved} moved, {$this->missing} missing, {$this->unchanged} unchanged";
        if ($this->unreadable !== []) {
            $text .= '; could not read (tried again next time): '.implode(', ', $this->unreadable);
        }
        if ($this->unreachable !== []) {
            $text .= '; folder not found: '.implode(', ', $this->unreachable);
        }

        return $text.'.';
    }
}
