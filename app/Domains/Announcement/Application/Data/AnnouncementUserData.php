<?php

namespace App\Domains\Announcement\Application\Data;

use App\Models\User;
use App\Shared\Data\BaseData;

/** Une personne choisie à la main, ou trouvée par la recherche. */
final class AnnouncementUserData extends BaseData
{
    public function __construct(
        public string $id,
        public string $name,
        public string $profil,
        public bool $hasEmail,
    ) {}

    public static function fromUser(User $user): self
    {
        return new self($user->id, (string) $user->name, (string) $user->profil, (bool) $user->email);
    }
}
