<?php

namespace App\Domains\Identity\Application\Data;

use App\Shared\Data\BaseData;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Une session ouverte sur un appareil, telle que la liste des appareils connectés
 * l'affiche.
 *
 * Le user agent part BRUT : c'est au front d'en tirer un libellé lisible
 * (« Chrome sur Android »). Il est nul pour les jetons émis avant le 2026-09-29.
 */
final class DeviceSessionData extends BaseData
{
    public function __construct(
        public int $id,
        public ?string $userAgent,
        public ?string $ipAddress,
        public string $createdAt,
        public ?string $lastUsedAt,
        public bool $isCurrent,
    ) {}

    public static function fromModel(PersonalAccessToken $token, ?int $currentId): self
    {
        return new self(
            id: (int) $token->id,
            userAgent: $token->user_agent,
            ipAddress: $token->ip_address,
            createdAt: $token->created_at->toIso8601String(),
            lastUsedAt: $token->last_used_at?->toIso8601String(),
            isCurrent: $currentId !== null && (int) $token->id === $currentId,
        );
    }
}
