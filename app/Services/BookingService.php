<?php

namespace App\Services;

use App\Domains\Booking\Application\Actions\AcceptBooking;
use App\Domains\Notification\Application\Notifier;
use App\Domains\Booking\Application\Actions\CancelBooking;
use App\Domains\Booking\Application\Actions\CompleteBooking;
use App\Domains\Booking\Application\Actions\RevokeFromSubscription;
use App\Domains\Booking\Application\Actions\StartBooking;
use App\Models\Booking;
use App\Models\Driver;
use App\Models\PromoCode;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

use function Symfony\Component\Clock\now;

class BookingService
{
    protected PricingService $pricingService;
    protected CommissionService $commissionService;

    public function __construct(PricingService $pricingService, CommissionService $commissionService)
    {
        $this->pricingService = $pricingService;
        $this->commissionService = $commissionService;
    }

    public function create(array $data)
    {
        // Calcul de la distance
        $distance = $this->pricingService->getDistance(
            $data['from_lng'],
            $data['from_lat'],
            $data['to_lng'],
            $data['to_lat']
        );

        if ($distance === null) {
            throw new \Exception('Erreur lors du calcul de l\'itinéraire.');
        }

        // Prix brut sans majoration
        $basePrice = $data['base_price'] ?? $this->pricingService->getPrice($distance);

        $isRecurring = isset($data['days']) && $data['days'] > 1;
        $isRound      = !empty($data['round_trip']);
        $days         = $data['days'] ?? 1;

        // Prix de l'aller, avec majoration selon l'heure de départ
        $goPrice = $this->pricingService->applyTimeSurcharge($basePrice, $data['pickup_time']);

        // Prix du retour, avec majoration selon l'heure de retour (si aller-retour)
        $returnPrice = ($isRound && !empty($data['return_time']))
            ? $this->pricingService->applyTimeSurcharge($basePrice, $data['return_time'])
            : $goPrice;

        $tripPrice    = $isRound ? $goPrice + $returnPrice : $goPrice;
        $totalPrice   = $tripPrice * ($isRecurring ? $days : 1);

        // Calcul de la date du prochain passage du cron pour les courses récurrentes
        $firstNextDay  = getNextAllowedDay(Carbon::parse($data['pickup_date']), $data['week_days'] ?? 'lun_dim');
        $nextRecurringDate = $isRecurring && $firstNextDay ? $firstNextDay->copy()->subDay()->setTime(1, 0) : null;

        $endDate = null;
        if ($isRecurring && isset($data['week_days'])) {
            $endDate = calculateEndDate($data['pickup_date'], $days, $data['week_days']);
        }

        $booking = Booking::create([
            'user_id'             => $data['user_id'] ?? null,
            'from_location'       => $data['from_location'],
            'to_location'         => $data['to_location'],
            'from_lng'            => $data['from_lng'],
            'from_lat'            => $data['from_lat'],
            'to_lng'              => $data['to_lng'],
            'to_lat'              => $data['to_lat'],
            'phone'               => $data['phone'],
            'days'                => $data['days'] ?? 1,
            'remaining_days'      => $data['days'] ?? 1,
            'week_days'           => $data['week_days'] ?? null,
            'round_trip'          => !empty($data['round_trip']) ? 1 : 0,
            'return_time'         => $isRound ? ($data['return_time'] ?? null) : null,
            'trip_type'           => 'go',
            'pickup_date'         => $data['pickup_date'],
            'pickup_time'         => $data['pickup_time'],
            'special_requests'    => $data['special_requests'] ?? null,
            'distance'            => $distance,
            'base_price'          => $goPrice,
            'total_price'         => $totalPrice,
            'is_recurring'        => $isRecurring,
            'next_recurring_date' => $isRecurring ? $nextRecurringDate : null,
            'client_name'         => !empty($data['client_name']) ? $data['client_name'] : 'Client',
            'status'              => $data['status'] ?? 'pending',
            'tourist_circuit_id'  => $data['tourist_circuit_id'] ?? null,
            'subscription_end_date' => $endDate,
        ]);

        // Créer la course retour si aller-retour et non récurrent
        if (!$isRecurring && $isRound && !empty($data['return_time'])) {
            Booking::create([
                'from_location'          => $data['to_location'],
                'to_location'            => $data['from_location'],
                'from_lng'               => $data['to_lng'],
                'from_lat'               => $data['to_lat'],
                'to_lng'                 => $data['from_lng'],
                'to_lat'                 => $data['from_lat'],
                'distance'               => $distance,
                'phone'                  => $data['phone'],
                'days'                   => 1,
                'remaining_days'         => 1,
                'week_days'              => null,
                'round_trip'             => true,
                'return_time'            => null,
                'trip_type'              => 'return',
                'pickup_date'            => $data['pickup_date'],
                'pickup_time'            => $data['return_time'],
                'special_requests'       => $data['special_requests'] ?? null,
                'tourist_circuit_id'     => $data['tourist_circuit_id'] ?? null,
                'promo_code_id'          => $data['promo_code_id'] ?? null,
                'discount'               => $data['discount'] ?? 0,
                'base_price'             => $returnPrice,
                'total_price'            => $tripPrice,
                'status'                 => 'pending',
                'is_recurring'           => false,
                'parent_booking_id'      => $booking->id,
                'subscription_driver_id' => null, // cachée jusqu'à acceptation de l'aller
                'user_id'                => $data['user_id'] ?? null,
                'client_name'            => $data['client_name'] ?? null,
            ]);
        }

        // Créer la course retour cachée si aller-retour
        if ($isRecurring && $isRound && isset($data['return_time'])) {
            Booking::create([
                'from_location'          => $data['to_location'],   // inversé
                'to_location'            => $data['from_location'],
                'from_lng'               => $data['to_lng'],
                'from_lat'               => $data['to_lat'],
                'to_lng'                 => $data['from_lng'],
                'to_lat'                 => $data['from_lat'],
                'distance'               => $distance,
                'phone'                  => $data['phone'],
                'days'                   => $days,
                'remaining_days'         => $days,
                'week_days'              => $data['week_days'] ?? null,
                'round_trip'             => true,
                'return_time'            => null,
                'trip_type'              => 'return',
                'pickup_date'            => $data['pickup_date'],
                'pickup_time'            => $data['return_time'],
                'special_requests'       => $data['special_requests'] ?? null,
                'base_price'             => $returnPrice,
                'total_price'            => $totalPrice,
                'status'                 => 'pending',
                'is_recurring'           => false, // ne recrée pas
                'parent_booking_id'      => $booking->id,
                'subscription_driver_id' => null, // caché de tous jusqu'à acceptation
                'user_id'                => $data['user_id'] ?? null,
                'client_name'            => $data['client_name'] ?? null,
                'next_recurring_date'    => null,
                'subscription_end_date'  => $endDate ?? null,
            ]);
        }

        // ⚠️ Passe par le Notifier, qui porte la table de routage, plutôt que d'appeler
        // l'envoi directement : c'était le SEUL déclencheur de notification de toute
        // l'application, et son URL pointait vers une route Blade qu'un front Nuxt ne
        // sait pas ouvrir.
        app(Notifier::class)->bookingCreated($booking);

        return $booking;
    }

    public function get(?string $status = null)
    {
        $query = Booking::query();

        if ($status) {
            $query->where('status', $status);
        } else {
            // Exclure les courses expirées si aucun statut n'est spécifié
            $query->where('status', '!=', 'expired');
        }

        return $query->orderByRaw("CONCAT(pickup_date, ' ', pickup_time) DESC")->get();
    }

    public function getAvailableBookings(string $driverId): \Illuminate\Support\Collection
    {
        return Booking::with(['parentBooking.user'])
            ->where('status', 'pending')
            ->where(function ($query) use ($driverId) {

                // 1. Course unique aller simple (sans aller-retour)
                $query->where(function ($q) {
                    $q->where('is_recurring', false)
                        ->whereNull('parent_booking_id')
                        ->where('trip_type', 'go')
                        ->where('round_trip', false);
                })

                    // 2. Course unique aller avec aller-retour
                    ->orWhere(function ($q) {
                        $q->where('is_recurring', false)
                            ->whereNull('parent_booking_id')
                            ->where('trip_type', 'go')
                            ->where('round_trip', true);
                    })

                    // 3. Abonnement parent sans titulaire → tout le monde
                    ->orWhere(function ($q) {
                        $q->where('is_recurring', true)
                            ->whereNull('parent_booking_id')
                            ->whereNull('subscription_driver_id')
                            ->where('is_revoked', false);
                    })

                    // 4. Abonnement parent lié à cet agent → lui seul
                    ->orWhere(function ($q) use ($driverId) {
                        $q->where('is_recurring', true)
                            ->whereNull('parent_booking_id')
                            ->where('subscription_driver_id', $driverId)
                            ->where('is_revoked', false);
                    })

                    // 5. Enfant abonnement lié à cet agent → lui seul
                    ->orWhere(function ($q) use ($driverId) {
                        $q->whereNotNull('parent_booking_id')
                            ->where('is_recurring', false)
                            ->where('trip_type', 'go')
                            ->where('subscription_driver_id', $driverId)
                            ->where('is_revoked', false)
                            ->whereHas('parentBooking', fn($p) => $p->where('is_recurring', true));
                    })

                    // 6. Enfant abonnement révoqué → tout le monde
                    ->orWhere(function ($q) {
                        $q->whereNotNull('parent_booking_id')
                            ->where('is_recurring', false)
                            ->where('is_revoked', true)
                            ->whereHas('parentBooking', fn($p) => $p->where('is_recurring', true));
                    })

                    // 7. Course retour simple liée à cet agent → lui seul
                    // (rendue visible après acceptation de l'aller)
                    ->orWhere(function ($q) use ($driverId) {
                        $q->whereNotNull('parent_booking_id')
                            ->where('trip_type', 'return')
                            ->where('is_recurring', false)
                            ->where('subscription_driver_id', $driverId)
                            ->whereHas('parentBooking', fn($p) => $p->where('is_recurring', false));
                    })

                    // 8. Course retour abonnement liée à cet agent → lui seul
                    ->orWhere(function ($q) use ($driverId) {
                        $q->whereNotNull('parent_booking_id')
                            ->where('trip_type', 'return')
                            ->where('is_recurring', false)
                            ->where('subscription_driver_id', $driverId)
                            ->where('is_revoked', false)
                            ->whereHas('parentBooking', fn($p) => $p->where('is_recurring', true));
                    })

                    // 9. Course retour révoquée → tout le monde
                    ->orWhere(function ($q) {
                        $q->where('trip_type', 'return')
                            ->where('is_revoked', true);
                    });
            })
            ->orderByRaw("(pickup_date::date + pickup_time::time) ASC")
            ->get();
    }

    public function getById(string $bookingId)
    {
        return Booking::findOrFail($bookingId);
    }

    public function getByUserId(string $userId, ?string $search = null)
    {
        $query = Booking::where('user_id', $userId)
            ->whereIn('status', ['completed', 'cancelled']);

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('booking_number', 'LIKE', "%{$search}%")
                    ->orWhere('phone', 'LIKE', "%{$search}%")
                    ->orWhereHas('from_location', function ($zoneQuery) use ($search) {
                        $zoneQuery->where('name', 'LIKE', "%{$search}%");
                    })
                    ->orWhereHas('to_location', function ($zoneQuery) use ($search) {
                        $zoneQuery->where('name', 'LIKE', "%{$search}%");
                    });
            });
        }

        return $query->orderByRaw("CONCAT(pickup_date, ' ', pickup_time) DESC")->get();
    }

    public function getByDriverId(string $driverId, string|array|null $status = null, ?string $search = null)
    {
        $query = Booking::query()
            ->where('driver_id', $driverId)
            ->when($status, function ($query, $status) {
                is_array($status)
                    ? $query->whereIn('status', $status)
                    : $query->where('status', $status);
            });

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('booking_number', 'LIKE', "%{$search}%")
                    ->orWhere('phone', 'LIKE', "%{$search}%")
                    ->orWhere('from_location', 'LIKE', "%{$search}%")
                    ->orWhere('to_location', 'LIKE', "%{$search}%");
            });
        }

        return $query->orderByRaw("CONCAT(pickup_date, ' ', pickup_time) ASC")->get();
    }

    /**
     * Accepter une course.
     *
     * Le corps vit dans AcceptBooking depuis le 2026-09-18. Cette méthode reste pour le
     * chemin Blade, qui l'appelle depuis Web\BookingController — et elle DÉLÈGUE plutôt
     * que de garder sa copie : deux implémentations de la cascade de recréation
     * dériveraient tôt ou tard sans que rien ne le signale.
     *
     * `app()` plutôt que l'injection par constructeur : BookingService est instancié
     * dans une dizaine d'endroits, dont deux commandes Artisan, et lui ajouter cinq
     * paramètres obligerait à tous les toucher.
     */
    public function take(string $bookingId, string $driverId)
    {
        app(AcceptBooking::class)($bookingId, $driverId);
    }

    /** Annuler une course — le corps vit dans CancelBooking. */
    public function cancel(string $bookingId, string $driverId, string $reason): Booking
    {
        return app(CancelBooking::class)($bookingId, $driverId, $reason);
    }

    /** Démarrer une course — le corps vit dans StartBooking. */
    public function start(string $bookingId, string $driverId)
    {
        return app(StartBooking::class)($bookingId, $driverId);
    }

    /** Terminer une course — le corps vit dans CompleteBooking. */
    public function complete(string $bookingId, string $driverId)
    {
        return app(CompleteBooking::class)($bookingId, $driverId);
    }

    /** Révoquer une course d'abonnement — le corps vit dans RevokeFromSubscription. */
    public function revokeFromSubscription(string $bookingId, string $driverId): Booking
    {
        return app(RevokeFromSubscription::class)($bookingId, $driverId);
    }

    public function update(Booking $booking, array $data)
    {
        return DB::transaction(function () use ($booking, $data) {

            // Mise à jour partielle
            if (!empty($data['_partial'])) {
                unset($data['_partial']);

                // Retrait : vérifier si c'est un abonnement parent avec enfants
                if (array_key_exists('driver_id', $data) && is_null($data['driver_id']) && $booking->is_recurring) {
                    $hasChildren = Booking::where('parent_booking_id', $booking->id)->exists();
                    if ($hasChildren) {
                        throw new \Exception(
                            'Des courses enfants existent déjà. Vous ne pouvez pas retirer l\'agent de l\'abonnement parent.'
                        );
                    }
                    // Retirer aussi le titulaire si pas d'enfants
                    $data['subscription_driver_id'] = null;
                }

                if ($booking->is_subscription_parent) {
                    Booking::where('parent_booking_id', $booking->id)
                        ->whereIn('status', ['pending', 'confirmed'])
                        ->update([
                            'status'              => 'cancelled',
                            'cancelled_at'        => now(),
                            'cancellation_reason' => $validated['cancellation_reason'] ?? 'Abonnement annulé',
                        ]);
                }

                $booking->update($data);

                return $booking->refresh();
            }

            // Recalcul distance & prix
            $distance = $booking->distance;
            $basePrice = $booking->base_price;

            $fromChanged = isset($data['from_lng']) && (float) $data['from_lng'] !== (float) $booking->from_lng;
            $toChanged   = isset($data['to_lng'])   && (float) $data['to_lng']   !== (float) $booking->to_lng;

            if ($fromChanged || $toChanged) {
                $distance = $this->pricingService->getDistance($data['from_lng'], $data['from_lat'], $data['to_lng'], $data['to_lat']);

                if ($distance === null) {
                    throw new \Exception('Erreur lors du calcul de l\'itinéraire.');
                }

                $basePrice = $this->pricingService->getPrice($distance);
            } elseif (isset($data['base_price'])) {
                $basePrice = (float) $data['base_price'];
            }

            // Recalcul total si prix modifié manuellement ou trajet changé
            $isRound    = (bool) ($data['round_trip'] ?? $booking->round_trip ?? false);
            $days       = (int)  ($data['days']       ?? $booking->days       ?? 1);
            $weekDays   = $data['week_days'] ?? $booking->week_days ?? 'lun_dim';

            // Heures effectives (nouvelles si fournies, sinon celles déjà en base)
            $pickupTime = $data['pickup_time'] ?? $booking->pickup_time;
            $returnTime = $isRound ? ($data['return_time'] ?? $booking->return_time) : null;

            // Prix par trajet, avec majoration selon l'heure propre à chacun
            $goPrice     = $this->pricingService->applyTimeSurcharge($basePrice, $pickupTime);
            $returnPrice = $returnTime
                ? $this->pricingService->applyTimeSurcharge($basePrice, $returnTime)
                : $goPrice;

            $tripPrice  = $isRound ? $goPrice + $returnPrice : $goPrice;
            $totalPrice = $days > 1 ? $tripPrice * $days : $tripPrice;

            // Recalcul récurrence
            $isRecurring = $booking->is_recurring;
            $nextRecurringDate = $booking->next_recurring_date;
            $subscriptionEndDate  = $booking->subscription_end_date;

            $daysChanged = isset($data['days']) && (int) $data['days'] !== (int) $booking->days;
            $dateChanged = isset($data['pickup_date']) && $data['pickup_date'] !== $booking->pickup_date->toDateString();
            $weekChanged = isset($data['week_days'])   && $data['week_days']     !== $booking->week_days;

            if ($daysChanged || $dateChanged || $weekChanged) {
                $isRecurring = ($days > 1);
                $pickupDate  = $data['pickup_date'] instanceof Carbon ? $data['pickup_date']->toDateString() : ($data['pickup_date'] ?? $booking->pickup_date->toDateString());
                if ($isRecurring) {
                    // Prochain jour autorisé après J1 → heure du cron
                    $firstNext         = getNextAllowedDay(Carbon::parse($pickupDate), $weekDays);
                    $nextRecurringDate = $firstNext?->copy()->subDay()->setTime(1, 0);

                    // Date de fin selon jours ouvrés
                    $subscriptionEndDate = calculateEndDate($pickupDate, $days, $weekDays);
                } else {
                    $nextRecurringDate   = null;
                    $subscriptionEndDate = null;
                }
            }

            // Capturer AVANT la mise à jour
            $wasRoundTrip = (bool) $booking->round_trip;

            // Mise à jour
            $booking->update(
                [
                    'user_id'             => $data['user_id'] ?? $booking->user_id,
                    'client_name'         => isset($data['client_name']) && !empty($data['client_name']) ? $data['client_name'] : ($booking->client_name ?? 'Client'),
                    'from_location'       => $data['from_location'] ?? $booking->from_location,
                    'to_location'         => $data['to_location'] ?? $booking->to_location,
                    'from_lng'            => $data['from_lng'] ?? $booking->from_lng,
                    'from_lat'            => $data['from_lat'] ?? $booking->from_lat,
                    'to_lng'              => $data['to_lng'] ?? $booking->to_lng,
                    'to_lat'              => $data['to_lat'] ?? $booking->to_lat,
                    'phone'               => $data['phone'] ?? $booking->phone,
                    'days'                => $days,
                    'remaining_days'      => $days,
                    'week_days'           => $data['week_days'] ?? $booking->week_days,
                    'round_trip'          => $isRound,
                    'return_time'         => $isRound ? ($data['return_time'] ?? $booking->return_time) : null,
                    'pickup_date'         => $data['pickup_date'] ?? $booking->pickup_date,
                    'pickup_time'         => $data['pickup_time'] ?? $booking->pickup_time,
                    'status'              => $data['status'] ?? $booking->status,
                    'special_requests'    => $data['special_requests'] ?? $booking->special_requests,
                    'distance'            => $distance,
                    'base_price'          => $goPrice,
                    'total_price'         => $totalPrice,
                    'is_recurring'        => $isRecurring,
                    'next_recurring_date' => $nextRecurringDate,
                    'subscription_end_date' => $subscriptionEndDate,
                ]
            );

            // Cas désactivation aller-retour : était true, devient false
            if ($wasRoundTrip && !$isRound) {
                Booking::where('parent_booking_id', $booking->id)
                    ->where('trip_type', 'return')
                    ->delete();
            }

            // Cas mise à jour ou activation aller-retour
            if ($isRound) {
                $hasChildrenGo = Booking::where('parent_booking_id', $booking->id)
                    ->where('trip_type', 'go')
                    ->exists();

                if (!$hasChildrenGo) {
                    $returnBooking = Booking::where('parent_booking_id', $booking->id)
                        ->where('trip_type', 'return')
                        ->first();

                    if ($returnBooking) {
                        // Mise à jour de la course retour existante
                        $returnBooking->update([
                            'from_location'         => $data['to_location']   ?? $booking->to_location,
                            'to_location'           => $data['from_location'] ?? $booking->from_location,
                            'from_lng'              => $data['to_lng']        ?? $booking->to_lng,
                            'from_lat'              => $data['to_lat']        ?? $booking->to_lat,
                            'to_lng'                => $data['from_lng']      ?? $booking->from_lng,
                            'to_lat'                => $data['from_lat']      ?? $booking->from_lat,
                            'pickup_date'           => $data['pickup_date']   ?? $booking->pickup_date,
                            'pickup_time'           => $data['return_time']   ?? $returnBooking->pickup_time,
                            'phone'                 => $data['phone']         ?? $booking->phone,
                            'days'                  => $days,
                            'remaining_days'        => $days,
                            'week_days'             => $data['week_days']     ?? $booking->week_days,
                            'distance'              => $distance,
                            'base_price'            => $returnPrice,
                            'total_price'           => $totalPrice,
                            'special_requests'      => $data['special_requests'] ?? $booking->special_requests,
                            'client_name'           => $data['client_name']   ?? $booking->client_name,
                            'subscription_end_date' => $subscriptionEndDate,
                            'round_trip'            => true,
                        ]);
                    } elseif (!$wasRoundTrip && isset($data['return_time'])) {
                        // Aller-retour nouvellement activé → créer la course retour
                        Booking::create([
                            'from_location'          => $data['to_location']   ?? $booking->to_location,
                            'to_location'            => $data['from_location'] ?? $booking->from_location,
                            'from_lng'               => $data['to_lng']        ?? $booking->to_lng,
                            'from_lat'               => $data['to_lat']        ?? $booking->to_lat,
                            'to_lng'                 => $data['from_lng']      ?? $booking->from_lng,
                            'to_lat'                 => $data['from_lat']      ?? $booking->from_lat,
                            'distance'               => $distance,
                            'phone'                  => $data['phone']         ?? $booking->phone,
                            'days'                   => $days,
                            'remaining_days'         => $days,
                            'week_days'              => $weekDays,
                            'round_trip'             => true,
                            'return_time'            => null,
                            'trip_type'              => 'return',
                            'pickup_date'            => $data['pickup_date']   ?? $booking->pickup_date,
                            'pickup_time'            => $data['return_time'],
                            'special_requests'       => $data['special_requests'] ?? $booking->special_requests,
                            'base_price'             => $returnPrice,
                            'total_price'            => $totalPrice,
                            'status'                 => 'pending',
                            'is_recurring'           => false,
                            'parent_booking_id'      => $booking->id,
                            'subscription_driver_id' => null,
                            'client_name'            => $data['client_name']   ?? $booking->client_name,
                            'subscription_end_date'  => $subscriptionEndDate,
                        ]);
                    }
                }
            }

            return $booking->refresh();
        });
    }

    public function delete(string $bookingId)
    {
        $booking = Booking::findOrFail($bookingId);

        if (!$booking) {
            throw new \Exception('La course demandée est introuvable.');
        }

        if (!in_array($booking->status, ['cancelled', 'expired'])) {
            throw new \Exception('Cette course ne peut pas être supprimée car elle est en cours ou en attente.');
        }

        $booking->delete();
    }

    public function markExpiredBookings(): int
    {
        $expiredBookings = Booking::where('status', 'pending')
            ->whereRaw("(pickup_date::date + pickup_time::time) < ?", [Carbon::now()->subHours(24)])
            ->whereNull('expired_at')
            ->get();

        foreach ($expiredBookings as $booking) {
            $booking->update([
                'status' => 'expired',
                'expired_at' => Carbon::now(),
            ]);
        }

        return $expiredBookings->count();
    }

    public function createRecurringBookings()
    {
        $recurringBookings = Booking::where('is_recurring', true)
            ->whereIn('status', ['confirmed', 'in_progress', 'completed'])
            ->where('remaining_days', '>', 0)
            ->where('trip_type', 'go')
            ->whereNull('parent_booking_id')
            ->where(function ($query) {
                $query->whereNull('next_recurring_date')
                    ->orWhere('next_recurring_date', '<=', now());
            })
            ->get();

        foreach ($recurringBookings as $booking) {
            // Recharger avec verrou
            $booking = Booking::lockForUpdate()->find($booking->id);

            if ($booking->next_recurring_date && $booking->next_recurring_date->isFuture()) {
                continue;
            }

            // Créer la nouvelle course pour le jour suivant
            $nextAllowedDay = $booking->next_recurring_date ? Carbon::parse($booking->next_recurring_date)->addDay()->startOfDay() : getNextAllowedDay(Carbon::parse($booking->pickup_date), $booking->week_days ?? 'lun_dim');
            if (!$nextAllowedDay) continue;

            $newPickupDate  = $nextAllowedDay->copy()->setTimeFromTimeString($booking->pickup_time);
            $newRemaining   = $booking->remaining_days - 1;

            // Prochain passage du cron = jour suivant autorisé à 1h
            $nextAllowedForCron = getNextAllowedDay($nextAllowedDay, $booking->week_days ?? 'lun_dim');
            $nextRecurring = $newRemaining > 0 && $nextAllowedForCron ? $nextAllowedForCron->copy()->subDay()->setTime(1, 0) : null;

            // Si plus de jours restants, pas de next_recurring_date
            if ($newRemaining <= 0) {
                $nextRecurring = null;
            }

            $subDriverId = $booking->subscription_driver_id;

            // --- Course ALLER ---
            Booking::create([
                'from_location'          => $booking->from_location,
                'to_location'            => $booking->to_location,
                'from_lng'               => $booking->from_lng,
                'from_lat'               => $booking->from_lat,
                'to_lng'                 => $booking->to_lng,
                'to_lat'                 => $booking->to_lat,
                'distance'               => $booking->distance,
                'phone'                  => $booking->phone,
                'days'                   => $booking->days,
                'remaining_days'         => $newRemaining,
                'week_days'              => $booking->week_days,
                'round_trip'             => $booking->round_trip,
                'return_time'            => $booking->return_time,
                'trip_type'              => 'go',
                'pickup_date'            => $newPickupDate->toDateString(),
                'pickup_time'            => $newPickupDate->format('H:i'),
                'special_requests'       => $booking->special_requests,
                'tourist_circuit_id'     => $booking->tourist_circuit_id,
                'promo_code_id'          => $booking->promo_code_id,
                'discount'               => $booking->discount,
                'base_price'             => $booking->base_price,
                'total_price'            => $booking->total_price,
                'status'                 => 'pending',
                'is_recurring'           => false,
                'parent_booking_id'      => $booking->id,
                'subscription_driver_id' => $subDriverId,
                'next_recurring_date'    => null,
                'client_name'            => $booking->client_name,
            ]);

            // --- Course RETOUR (si aller-retour) ---
            if ($booking->round_trip && $booking->return_time) {
                $returnDate = $newPickupDate->copy()->setTimeFromTimeString($booking->return_time);

                Booking::create([
                    'from_location'          => $booking->to_location,
                    'to_location'            => $booking->from_location,
                    'from_lng'               => $booking->to_lng,
                    'from_lat'               => $booking->to_lat,
                    'to_lng'                 => $booking->from_lng,
                    'to_lat'                 => $booking->from_lat,
                    'distance'               => $booking->distance,
                    'phone'                  => $booking->phone,
                    'days'                   => $booking->days,
                    'remaining_days'         => $newRemaining,
                    'week_days'              => $booking->week_days,
                    'round_trip'             => true,
                    'return_time'            => null,
                    'trip_type'              => 'return',
                    'pickup_date'            => $returnDate->toDateString(),
                    'pickup_time'            => $returnDate->format('H:i'),
                    'special_requests'       => $booking->special_requests,
                    'tourist_circuit_id'     => $booking->tourist_circuit_id,
                    'promo_code_id'          => $booking->promo_code_id,
                    'discount'               => $booking->discount,
                    'base_price'             => $booking->base_price,
                    'total_price'            => $booking->total_price,
                    'status'                 => 'pending',
                    'is_recurring'           => false,
                    'parent_booking_id'      => $booking->id,
                    'subscription_driver_id' => $subDriverId,
                    'client_name'            => $booking->client_name,
                ]);
            }

            // Mettre à jour la course actuelle avec le nombre de jours restants
            $booking->update([
                'remaining_days'      => $newRemaining,
                'next_recurring_date' => $newRemaining > 0 ? $nextRecurring : null,
                'is_recurring'        => $newRemaining > 0, // désactive si dernier jour
            ]);
        }

        return $recurringBookings->count();
    }
}
