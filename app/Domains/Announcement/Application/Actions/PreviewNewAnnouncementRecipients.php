<?php

namespace App\Domains\Announcement\Application\Actions;

use App\Models\Announcement;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * « Les nouveaux du ciblage » (spec 2026-11-04-communiques-relance-ajout) : ce que le ciblage
 * d'origine donnerait aujourd'hui, moins les destinataires existants. L'expéditeur d'origine en
 * est retiré comme à l'envoi, même quand un autre admin fait l'ajout.
 */
final class PreviewNewAnnouncementRecipients
{
    public function __construct(private readonly ResolveAnnouncementRecipients $resolve) {}

    /** @return Collection<int, User> */
    public function __invoke(Announcement $announcement): Collection
    {
        $existing = $announcement->recipients()->pluck('user_id')->all();

        return ($this->resolve)($announcement->target_profiles ?? [], $announcement->target_user_ids ?? [], null)
            ->reject(fn (User $user) => $user->id === $announcement->sent_by || in_array($user->id, $existing, true))
            ->values();
    }
}
