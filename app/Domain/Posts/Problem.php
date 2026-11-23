<?php

namespace App\Domain\Posts;

/** One finding about a post: "block" stops approval, "warn" is shown but allowed. */
final readonly class Problem
{
    public function __construct(public string $level, public string $message) {}

    public static function block(string $message): self
    {
        return new self('block', $message);
    }

    public static function warn(string $message): self
    {
        return new self('warn', $message);
    }

    public function blocks(): bool
    {
        return $this->level === 'block';
    }

    public function withPrefix(string $prefix): self
    {
        return new self($this->level, $prefix.$this->message);
    }
}
