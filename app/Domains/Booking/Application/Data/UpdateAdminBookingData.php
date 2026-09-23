<?php

namespace App\Domains\Booking\Application\Data;

use App\Domains\Booking\Domain\Enums\WeekDays;
use App\Shared\Data\BaseData;
use Carbon\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Spatie\LaravelData\Support\Validation\ValidationContext;

/**
 * Modification d'une réservation EN ATTENTE — ex-Admin\BookingController::update().
 *
 * ⚠️ Ne porte AUCUN champ `status`, à la différence du formulaire Blade, qui validait
 * `status` dans le MÊME corps que le trajet et le prix. Un administrateur pouvait ainsi
 * confirmer ou annuler une course sans passer par `ChangeBookingStatus` — donc sans que
 * la matrice de transitions de `BookingLifecycle` s'applique, et sans notification. Le
 * seul chemin de changement de statut reste `POST /admin/bookings/{booking}/status`.
 *
 * ⚠️ Ne porte pas non plus `user_id` : le sélecteur du Blade n'offrait qu'une seule
 * option, déjà sélectionnée — un lien vers un compte client, jamais un choix réel. Le
 * reproduire aurait ajouté un champ qui ne fait rien.
 */
final class UpdateAdminBookingData extends BaseData
{
    public function __construct(
        public ?string $clientName,
        public string $phone,
        public string $fromLocation,
        public string $toLocation,
        public float $fromLat,
        public float $fromLng,
        public float $toLat,
        public float $toLng,
        public string $pickupDate,
        public string $pickupTime,
        public float $basePrice,
        public int $days = 1,
        public bool $roundTrip = false,
        public ?string $returnTime = null,
        public ?WeekDays $weekDays = null,
        public ?string $specialRequests = null,
    ) {}

    public static function rules(ValidationContext $context): array
    {
        return [
            'client_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:20'],
            'from_location' => ['required', 'string', 'max:255'],
            'to_location' => ['required', 'string', 'max:255'],
            'from_lat' => ['required', 'numeric', 'between:6,13'],
            'from_lng' => ['required', 'numeric', 'between:0,4'],
            'to_lat' => ['required', 'numeric', 'between:6,13'],
            'to_lng' => ['required', 'numeric', 'between:0,4'],
            'pickup_date' => ['required', 'date'],
            'pickup_time' => ['required', 'date_format:H:i'],
            'base_price' => ['required', 'numeric', 'min:0'],
            'days' => ['nullable', 'integer', 'min:1', 'max:366'],
            'round_trip' => ['nullable', 'boolean'],
            'return_time' => ['nullable', 'required_if:round_trip,true', 'date_format:H:i', 'after:pickup_time'],
            'week_days' => ['nullable', Rule::enum(WeekDays::class)],
            'special_requests' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return [
            'from_location.required' => 'Veuillez sélectionner une ville de départ.',
            'to_location.required' => 'Veuillez sélectionner une ville de destination.',
            'return_time.required_if' => 'L\'heure de retour est requise pour les trajets aller-retour.',
            'return_time.after' => 'L\'heure de retour doit être postérieure à l\'heure de prise en charge.',
            'base_price.required' => 'Le prix de base est requis.',
        ];
    }

    /** Même cohérence date / jours de circulation que la création. */
    public static function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $input = $validator->getData();

            if ((int) ($input['days'] ?? 1) > 1 && empty($input['week_days'])) {
                $validator->errors()->add(
                    'week_days',
                    'Les jours de circulation sont requis pour une réservation sur plusieurs jours.',
                );
            }

            if (
                (int) ($input['days'] ?? 1) > 1
                && ! empty($input['week_days'])
                && ! empty($input['pickup_date'])
            ) {
                $jours = WeekDays::tryFrom($input['week_days']);

                try {
                    $depart = Carbon::parse($input['pickup_date']);
                } catch (\Throwable) {
                    return;
                }

                if ($jours && ! in_array($depart->dayOfWeek, $jours->daysOfWeek(), true)) {
                    $validator->errors()->add(
                        'pickup_date',
                        'Le premier jour ne fait pas partie des jours de circulation choisis ('
                        .$jours->label().'). Choisissez une autre date de départ.',
                    );
                }
            }
        });
    }

    /**
     * Charge utile attendue par `BookingService::update()` — chemin COMPLET (sans
     * `_partial`), qui recalcule prix et distance. `status` n'y figure jamais : absent
     * du tableau, `$data['status'] ?? $booking->status` dans le service garde le statut
     * courant.
     */
    public function toServicePayload(): array
    {
        return [
            'client_name' => $this->clientName,
            'phone' => $this->phone,
            'from_location' => $this->fromLocation,
            'to_location' => $this->toLocation,
            'from_lat' => $this->fromLat,
            'from_lng' => $this->fromLng,
            'to_lat' => $this->toLat,
            'to_lng' => $this->toLng,
            'pickup_date' => $this->pickupDate,
            'pickup_time' => $this->pickupTime,
            'base_price' => $this->basePrice,
            'days' => $this->days,
            'round_trip' => $this->roundTrip,
            'return_time' => $this->returnTime,
            'week_days' => $this->weekDays?->value,
            'special_requests' => $this->specialRequests,
        ];
    }
}
