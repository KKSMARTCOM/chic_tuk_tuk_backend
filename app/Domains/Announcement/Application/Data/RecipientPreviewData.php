<?php

namespace App\Domains\Announcement\Application\Data;

use App\Domains\Notification\Application\Data\NotificationPreferencesData;
use App\Models\User;
use App\Shared\Data\BaseData;
use Illuminate\Support\Collection;

/** Le décompte en direct de l'éditeur : « 43 destinataires, dont 38 avec e-mail ». */
final class RecipientPreviewData extends BaseData
{
    public function __construct(
        public int $total,
        public int $withEmail,
    ) {}

    /** @param  Collection<int, User>  $users */
    public static function fromUsers(Collection $users): self
    {
        return new self(
            total: $users->count(),
            withEmail: $users->filter(fn (User $u) => $u->email && NotificationPreferencesData::fromUser($u)->emailNotifications)->count(),
        );
    }
}
