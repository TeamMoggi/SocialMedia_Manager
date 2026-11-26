<?php

namespace App\Domain\LinkedIn;

use RuntimeException;

/**
 * A refusal or failure from LinkedIn, with a plain message for the owner. Never carries the request
 * address, the key, the sign-in code or the Client Secret: only LinkedIn's own status and message.
 */
class LinkedInError extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly int $httpStatus = 0,
        private readonly bool $isTransient = false,
        private readonly bool $signIn = false,
    ) {
        parent::__construct($message);
    }

    /** @param array<string, mixed> $json LinkedIn's error body (REST: status/message; sign-in: error/error_description). */
    public static function fromResponse(int $status, array $json, bool $signIn = false): self
    {
        $text = trim((string) ($json['error_description'] ?? $json['message'] ?? $json['error'] ?? "LinkedIn answered with HTTP {$status}."));

        return new self("LinkedIn error {$status}: {$text}", $status, $status === 429 || $status >= 500, $signIn);
    }

    public static function offline(): self
    {
        return new self('Could not reach LinkedIn (no connection).', 0, true);
    }

    public function status(): int
    {
        return $this->httpStatus;
    }

    public function transient(): bool
    {
        return $this->isTransient;
    }

    /** LinkedIn no longer accepts the key (expired, removed, or the permissions changed). */
    public function keyRefused(): bool
    {
        return $this->httpStatus === 401 && ! $this->signIn;
    }

    public function plain(): string
    {
        $text = (string) preg_replace('/^LinkedIn error \d+: /', '', $this->getMessage());

        return match (true) {
            $this->httpStatus === 0 && $this->isTransient => 'Could not reach LinkedIn (no connection).',
            $this->signIn => "LinkedIn refused the sign-in: {$text}",
            $this->httpStatus === 401 => 'LinkedIn no longer accepts the key: press Reconnect LinkedIn on the LinkedIn page.',
            $this->httpStatus === 403 => "LinkedIn refused: the key may lack a permission, or the account is not an Administrator of the page ({$text}).",
            $this->httpStatus === 426 => 'LinkedIn no longer supports the API version this app uses: update the app.',
            $this->httpStatus === 429 => "LinkedIn's daily limit was reached: try again tomorrow.",
            $this->httpStatus >= 500 => 'LinkedIn had a temporary problem: try again in a few minutes.',
            default => $text,
        };
    }
}
