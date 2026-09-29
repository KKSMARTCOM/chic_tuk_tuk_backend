<?php

namespace App\Domains\Identity\Application\Actions;

use App\Domains\Identity\Application\Data\DeviceSessionData;
use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Les sessions encore valides du compte, la plus récemment active d'abord.
 *
 * Les jetons expirés ou inactifs depuis plus de la fenêtre glissante sont écartés :
 * `token.fresh` les refuserait à leur prochaine requête, les montrer ferait croire à
 * des appareils encore connectés.
 */
final class ListDeviceSessions
{
    /**
     * @return list<DeviceSessionData>
     */
    public function __invoke(User $user): array
    {
        $current = $user->currentAccessToken();
        $currentId = $current instanceof PersonalAccessToken ? (int) $current->getKey() : null;
        $limit = now()->subDays((int) config('identity.token.inactivity_days'));

        return $user->tokens()
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->where(fn ($query) => $query
                ->where('last_used_at', '>=', $limit)
                ->orWhere(fn ($query) => $query->whereNull('last_used_at')->where('created_at', '>=', $limit)))
            ->orderByRaw('coalesce(last_used_at, created_at) desc')
            ->get()
            ->map(fn (PersonalAccessToken $token) => DeviceSessionData::fromModel($token, $currentId))
            ->values()
            ->all();
    }
}
