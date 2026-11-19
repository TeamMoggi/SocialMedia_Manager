<?php

namespace App\Domain\Meta;

use RuntimeException;

/**
 * A refusal or failure from Meta's Graph API, with a plain message for the owner.
 * Never carries the request address or the access token: only Meta's own code and message.
 */
class MetaError extends RuntimeException
{
    private const TRANSIENT_CODES = [1, 2, 4, 17, 32, 613];

    public function __construct(
        string $message,
        private readonly int $metaCode = 0,
        private readonly ?int $metaSubcode = null,
        private readonly bool $isTransient = false,
        private readonly ?string $traceId = null,
    ) {
        parent::__construct($message);
    }

    /** @param array<string, mixed> $error The "error" object of a Graph API response. */
    public static function fromResponse(array $error, int $httpStatus): self
    {
        $code = (int) ($error['code'] ?? 0);
        $message = trim((string) ($error['error_user_msg'] ?? $error['message'] ?? "Meta answered with HTTP {$httpStatus}."));

        return new self(
            "Meta error {$code}: {$message}",
            $code,
            isset($error['error_subcode']) ? (int) $error['error_subcode'] : null,
            ($error['is_transient'] ?? false) === true || in_array($code, self::TRANSIENT_CODES, true) || $httpStatus >= 500,
            isset($error['fbtrace_id']) ? (string) $error['fbtrace_id'] : null,
        );
    }

    public static function offline(): self
    {
        return new self('Could not reach Meta (no connection).', 0, null, true);
    }

    public function code(): int
    {
        return $this->metaCode;
    }

    public function subcode(): ?int
    {
        return $this->metaSubcode;
    }

    public function transient(): bool
    {
        return $this->isTransient;
    }

    public function traceId(): ?string
    {
        return $this->traceId;
    }

    public function plain(): string
    {
        $code = $this->metaCode;

        return match (true) {
            $code === 0 && $this->isTransient => 'Could not reach Meta: no internet connection. Try again when the laptop is online.',
            $code === 190 => 'The Meta key no longer works: make a new key (How to use → Meta key) and save it on the Meta page.',
            $code === 10 || ($code >= 200 && $code <= 299) => 'Meta refused: the key is missing a permission. Check the Meta page.',
            in_array($code, [4, 17, 32, 613], true) => "Meta's hourly limit was reached: try again in an hour.",
            in_array($code, [1, 2], true) => 'Meta had a temporary problem: try again in a few minutes.',
            default => preg_replace('/^Meta error \d+: /', '', $this->getMessage()),
        };
    }
}
