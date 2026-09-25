<?php

namespace App\Domains\Booking\Application\Data;

use App\Domains\Booking\Application\Actions\ChangeBookingStatus;
use App\Shared\Data\BaseData;
use App\Domains\Booking\Domain\Enums\BookingStatus;
use Spatie\TypeScriptTransformer\Attributes\TypeScriptType;

/**
 * Le corps de POST /admin/bookings/{booking}/status.
 *
 * ⚠️ `expired` n'est PAS proposé, comme dans le Blade : c'est un statut que seule la
 * commande `app:expire-bookings` pose, sur des courses dont l'heure de départ est passée.
 * L'offrir à la main laisserait fabriquer une expiration qui n'a pas eu lieu.
 */
final class ChangeBookingStatusData extends BaseData
{
    public function __construct(
        #[TypeScriptType(BookingStatus::class)]
        public string $status,
        public ?string $cancellationReason = null,
    ) {}

    /** @return array<string, mixed> */
    public static function rules(): array
    {
        return [
            'status' => ['required', 'string', 'in:'.implode(',', ChangeBookingStatus::ALLOWED)],
            // Facultatif, comme dans le Blade : une annulation sans motif reste possible.
            'cancellation_reason' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return ['status.in' => 'Le statut sélectionné est invalide.'];
    }
}
