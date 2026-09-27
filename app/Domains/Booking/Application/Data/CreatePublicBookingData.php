<?php

namespace App\Domains\Booking\Application\Data;

use App\Domains\Booking\Domain\Enums\WeekDays;
use App\Shared\Data\BaseData;
use Carbon\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Spatie\LaravelData\Support\Validation\ValidationContext;

/**
 * Demande de réservation déposée depuis le tunnel public (landing).
 *
 * Reprend à l'identique les règles de Web\BookingController::store(), y compris la
 * contrainte des 24 heures d'anticipation. Aucun champ de prix n'est accepté :
 * le tarif est recalculé côté serveur à la création, un client ne peut donc pas
 * imposer le sien — ce que permettait la variante admin de cette route.
 */
class CreatePublicBookingData extends BaseData
{
    public function __construct(
        public string $fromLocation,
        public string $toLocation,
        public float $fromLat,
        public float $fromLng,
        public float $toLat,
        public float $toLng,
        public string $pickupDate,
        public string $pickupTime,
        public string $phone,
        public int $days = 1,
        public bool $roundTrip = false,
        public ?string $returnTime = null,
        public ?WeekDays $weekDays = null,
        public ?string $specialRequests = null,
        public ?string $promoCode = null,
    ) {}

    public static function rules(ValidationContext $context): array
    {
        return [
            'from_location' => ['required', 'string', 'max:255'],
            'to_location' => ['required', 'string', 'max:255'],
            'from_lat' => ['required', 'numeric', 'between:6,13'],
            'from_lng' => ['required', 'numeric', 'between:0,4'],
            'to_lat' => ['required', 'numeric', 'between:6,13'],
            'to_lng' => ['required', 'numeric', 'between:0,4'],
            'pickup_date' => ['required', 'date'],
            'pickup_time' => ['required', 'date_format:H:i'],
            'phone' => ['required', 'string', 'regex:/^[0-9+\-\s()]+$/', 'min:10', 'max:20'],
            'days' => ['nullable', 'integer', 'min:1', 'max:366'],
            'round_trip' => ['nullable', 'boolean'],
            'return_time' => ['nullable', 'required_if:round_trip,true', 'date_format:H:i', 'after:pickup_time'],
            // L'obligation pour un abonnement est traitée dans withValidator().
            'week_days' => ['nullable', Rule::enum(WeekDays::class)],
            'special_requests' => ['nullable', 'string', 'max:500'],
            'promo_code' => ['nullable', 'string', 'max:50'],
        ];
    }

    public static function messages(): array
    {
        return [
            'from_location.required' => 'Veuillez sélectionner une ville de départ.',
            'to_location.required' => 'Veuillez sélectionner une ville de destination.',
            'from_lat.required' => 'Veuillez choisir une ville de départ dans la liste de suggestions.',
            'from_lng.required' => 'Veuillez choisir une ville de départ dans la liste de suggestions.',
            'to_lat.required' => 'Veuillez choisir une destination dans la liste de suggestions.',
            'to_lng.required' => 'Veuillez choisir une destination dans la liste de suggestions.',
            'from_lat.between' => 'Le point de départ est en dehors de la zone desservie.',
            'to_lat.between' => 'La destination est en dehors de la zone desservie.',
            'pickup_date.required' => 'La date de prise en charge est obligatoire.',
            'pickup_time.required' => 'L\'heure de prise en charge est obligatoire.',
            'phone.regex' => 'Le numéro de téléphone contient des caractères invalides.',
            'phone.min' => 'Le numéro de téléphone est trop court.',
            'return_time.required_if' => 'L\'heure de retour est requise pour les trajets aller-retour.',
            'return_time.after' => 'L\'heure de retour doit être postérieure à l\'heure de prise en charge.',
        ];
    }

    /**
     * Règles qui dépendent de plusieurs champs à la fois.
     */
    public static function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $input = $validator->getData();

            // Un abonnement (plusieurs jours) impose des jours de circulation.
            if ((int) ($input['days'] ?? 1) > 1 && empty($input['week_days'])) {
                $validator->errors()->add(
                    'week_days',
                    'Les jours de circulation sont requis pour une réservation sur plusieurs jours.',
                );
            }

            // Et le PREMIER jour doit tomber dans ces jours de circulation.
            //
            // La règle existait déjà, dans calculateEndDate() : elle levait une
            // \Exception générique depuis `CreateBooking`, que le contrôleur public
            // remplaçait par « La réservation n'a pas pu être enregistrée. Vérifiez
            // votre trajet et réessayez. » — un message qui envoie sur une fausse piste.
            // Signalé le 2026-09-18 par quelqu'un qui a dû ouvrir l'application Blade
            // pour comprendre pourquoi sa réservation était refusée.
            //
            // La vérifier ICI la rend visible : elle ressort en 422 sur `pickup_date`,
            // avec le champ fautif et la cause.
            if (
                (int) ($input['days'] ?? 1) > 1
                && ! empty($input['week_days'])
                && ! empty($input['pickup_date'])
            ) {
                $jours = WeekDays::tryFrom($input['week_days']);

                try {
                    $depart = Carbon::parse($input['pickup_date']);
                } catch (\Throwable) {
                    return; // format déjà signalé par les règles de base
                }

                // `daysOfWeek()` suit la convention Carbon — dimanche = 0, PAS l'ISO.
                // C'est la même table que calculateEndDate() : les deux doivent rester
                // d'accord, sinon la validation laisserait passer ce que le service
                // refuse ensuite.
                if ($jours && ! in_array($depart->dayOfWeek, $jours->daysOfWeek(), true)) {
                    $validator->errors()->add(
                        'pickup_date',
                        'Le premier jour ne fait pas partie des jours de circulation choisis ('
                        .$jours->label().'). Choisissez une autre date de départ.',
                    );
                }
            }

            // Anticipation minimale de 24 heures.
            if (empty($input['pickup_date']) || empty($input['pickup_time'])) {
                return;
            }

            try {
                $pickupAt = Carbon::parse($input['pickup_date'].' '.$input['pickup_time']);
            } catch (\Throwable) {
                return; // format déjà signalé par les règles de base
            }

            if ($pickupAt->lt(now()->addHours(24))) {
                $validator->errors()->add(
                    'pickup_date',
                    'La réservation doit être effectuée au moins 24 heures à l\'avance.',
                );
            }
        });
    }

    /** Charge utile attendue par `CreateBooking`. */
    public function toServicePayload(): array
    {
        return [
            'from_location' => $this->fromLocation,
            'to_location' => $this->toLocation,
            'from_lat' => $this->fromLat,
            'from_lng' => $this->fromLng,
            'to_lat' => $this->toLat,
            'to_lng' => $this->toLng,
            'pickup_date' => $this->pickupDate,
            'pickup_time' => $this->pickupTime,
            'phone' => $this->phone,
            'days' => $this->days,
            'round_trip' => $this->roundTrip,
            'return_time' => $this->returnTime,
            'week_days' => $this->weekDays?->value,
            'special_requests' => $this->specialRequests,
            'promo_code' => $this->promoCode,
        ];
    }
}
