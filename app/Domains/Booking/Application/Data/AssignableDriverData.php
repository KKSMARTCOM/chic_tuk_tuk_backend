<?php

namespace App\Domains\Booking\Application\Data;

use App\Models\Driver;
use App\Shared\Data\BaseData;

/**
 * Un agent proposé dans la fenêtre d'affectation.
 *
 * ⚠️ `id` est l'identifiant de l'AGENT (`drivers.id`), celui que prend la route
 * d'affectation. La fenêtre Blade, elle, reçoit des COMPTES et redescend vers
 * `user.driver.id` en JavaScript, avec un repli sur `user.id` quand la relation manque —
 * un repli qui envoie alors un identifiant de compte à une route qui attend un agent,
 * et produit « Le Agent sélectionné n'existe pas ». L'API ne renvoie que des agents.
 */
final class AssignableDriverData extends BaseData
{
    public function __construct(
        public string $id,
        public string $userId,
        public ?string $name,
        public ?string $phone,
        /**
         * Courses déjà confirmées ou en cours pour cet agent.
         *
         * Ce n'est PAS un motif d'exclusion — un administrateur affecte souvent à
         * l'avance — mais c'est ce qui permet de ne pas tout donner au même.
         */
        public int $activeBookingsCount,
    ) {}

    public static function fromModel(Driver $driver): self
    {
        return new self(
            id: $driver->id,
            userId: $driver->user_id,
            name: $driver->user?->name,
            phone: $driver->user?->phone,
            activeBookingsCount: (int) ($driver->active_bookings_count ?? 0),
        );
    }
}
