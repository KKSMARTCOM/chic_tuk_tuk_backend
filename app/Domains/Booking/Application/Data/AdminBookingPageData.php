<?php

namespace App\Domains\Booking\Application\Data;

use App\Models\Booking;
use App\Shared\Data\BaseData;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Une page de la liste des réservations.
 *
 * Même enveloppe que `BookingHistoryPageData`, et pour la même raison : le format de
 * pagination par défaut de Laravel embarque une quinzaine de champs dont des URL absolues
 * vers le domaine de l'API, inutiles à un front qui construit ses propres liens.
 */
final class AdminBookingPageData extends BaseData
{
    public function __construct(
        /** @var array<int, AdminBookingListItemData> */
        public array $data,
        public int $currentPage,
        public int $lastPage,
        public int $perPage,
        public int $total,
    ) {}

    public static function fromPaginator(LengthAwarePaginator $page): self
    {
        return new self(
            data: collect($page->items())
                ->map(fn (Booking $booking) => AdminBookingListItemData::fromModel($booking))
                ->all(),
            currentPage: $page->currentPage(),
            lastPage: $page->lastPage(),
            perPage: $page->perPage(),
            total: $page->total(),
        );
    }
}
