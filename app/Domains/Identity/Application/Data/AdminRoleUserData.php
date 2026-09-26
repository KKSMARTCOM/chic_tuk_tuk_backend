<?php

namespace App\Domains\Identity\Application\Data;

use App\Domains\Identity\Domain\Enums\Profil;
use App\Models\User;
use App\Shared\Data\BaseData;
use Spatie\TypeScriptTransformer\Attributes\TypeScriptType;

/** Un compte qui porte le rôle, sur sa fiche. */
final class AdminRoleUserData extends BaseData
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $email,
        #[TypeScriptType(Profil::class)]
        public string $profil,
    ) {}

    public static function fromModel(User $user): self
    {
        return new self($user->id, $user->name, $user->email, $user->profil);
    }
}
