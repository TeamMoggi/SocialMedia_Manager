<?php

namespace App\Domain\Meta;

use Carbon\CarbonImmutable;

/** The result of asking Meta about a brand's permanent key (debug_token). */
final readonly class KeyCheck
{
    /**
     * @param  list<string>  $scopes  permissions the key has
     * @param  list<string>  $missing  required permissions it lacks (connecting is refused)
     * @param  list<string>  $missingWanted  useful permissions it lacks (a warning)
     * @param  list<string>  $extra  permissions beyond what the app needs (a warning)
     */
    public function __construct(
        public bool $valid,
        public ?CarbonImmutable $expiresAt,
        public array $scopes,
        public array $missing,
        public array $missingWanted,
        public array $extra,
        public ?string $problem,
        public bool $unreachable,
        public CarbonImmutable $checkedAt,
    ) {}

    public static function failed(string $problem, bool $unreachable = false): self
    {
        return new self(false, null, [], [], [], [], $problem, $unreachable, CarbonImmutable::now('UTC'));
    }

    public function toArray(): array
    {
        return [
            'valid' => $this->valid,
            'expires_at' => $this->expiresAt?->toIso8601String(),
            'scopes' => $this->scopes,
            'missing' => $this->missing,
            'missing_wanted' => $this->missingWanted,
            'extra' => $this->extra,
            'problem' => $this->problem,
            'unreachable' => $this->unreachable,
            'checked_at' => $this->checkedAt->toIso8601String(),
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            (bool) ($data['valid'] ?? false),
            isset($data['expires_at']) ? CarbonImmutable::parse($data['expires_at']) : null,
            $data['scopes'] ?? [],
            $data['missing'] ?? [],
            $data['missing_wanted'] ?? [],
            $data['extra'] ?? [],
            $data['problem'] ?? null,
            (bool) ($data['unreachable'] ?? false),
            CarbonImmutable::parse($data['checked_at'] ?? 'now'),
        );
    }
}
