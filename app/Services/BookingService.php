<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Driver;
use App\Models\PromoCode;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;


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

        app(FcmNotificationService::class)->sendToDrivers(
            'Nouvelle réservation disponible',
            "Trajet : {$booking->from_location} → {$booking->to_location}",
            ['url' => route('driver.bookings.available')]
        );

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

    public function take(string $bookingId, string $driverId)
    {
        DB::transaction(function () use ($bookingId, $driverId) {

            $booking = Booking::lockForUpdate()->findOrFail($bookingId);

            if ($booking->status !== 'pending' || $booking->driver_id) {
                throw new \Exception('Réservation déjà prise ou annulée.');
            }

            if (!$booking->isVisibleToDriver($driverId)) {
                throw new \Exception('Cette course n\'est pas accessible.');
            }

            $driver = Driver::lockForUpdate()->findOrFail($driverId);

            $updateData = [
                'driver_id' => $driver->id,
                'status'    => 'confirmed',
            ];

            // Abonnement parent → lier le titulaire + course retour abonnement
            if (
                $booking->is_recurring
                && is_null($booking->parent_booking_id)
                && !$booking->subscription_driver_id
            ) {
                $updateData['subscription_driver_id'] = $driver->id;

                Booking::where('parent_booking_id', $booking->id)
                    ->where('trip_type', 'return')
                    ->whereNull('subscription_driver_id')
                    ->update(['subscription_driver_id' => $driver->id]);
            }

            // Course unique aller avec aller-retour → lier l'agent à la course retour
            if (
                !$booking->is_recurring
                && $booking->round_trip
                && $booking->trip_type === 'go'
                && is_null($booking->parent_booking_id) // course aller principale
            ) {
                $updated = Booking::where('parent_booking_id', $booking->id)
                    ->where('trip_type', 'return')
                    ->whereNull('subscription_driver_id')
                    ->update(['subscription_driver_id' => $driver->id]);

                Log::info("[take] Courses retour simples liées à l'agent : {$updated}");
            }

            $booking->update($updateData);

            Log::info("[take] Booking {$bookingId} accepté par driver {$driverId}");
        });

        // Hors de la transaction d'acceptation : chaque journée prend son propre verrou.
        $this->catchUpRecurringBookings($bookingId);
    }

    public function cancel(string $bookingId, string $driverId, string $reason): Booking
    {
        return DB::transaction(function () use ($bookingId, $driverId, $reason) {

            $booking = Booking::lockForUpdate()->findOrFail($bookingId);

            if ($booking->driver_id !== $driverId) {
                throw new \Exception('Accès non autorisé.');
            }

            if (!$booking->canBeCancelled()) {
                throw new \Exception('Cette réservation ne peut plus être annulée.');
            }

            // CAS 1 — Course enfant d'abonnement → révocation
            if ($booking->is_subscription_child) {
                $booking->update([
                    'status'              => 'cancelled',
                    'cancelled_at'        => now(),
                    'cancellation_reason' => $reason,
                ]);

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
                    'remaining_days'         => $booking->remaining_days,
                    'week_days'              => $booking->week_days,
                    'round_trip'             => $booking->round_trip,
                    'return_time'            => $booking->return_time,
                    'trip_type'              => $booking->trip_type,
                    'pickup_date'            => $booking->pickup_date,
                    'pickup_time'            => $booking->pickup_time,
                    'special_requests'       => $booking->special_requests,
                    'tourist_circuit_id'     => $booking->tourist_circuit_id,
                    'discount'               => $booking->discount,
                    'promo_code_id'          => $booking->promo_code_id,
                    'base_price'             => $booking->base_price,
                    'total_price'            => $booking->total_price,
                    'status'                 => 'pending',
                    'is_recurring'           => $booking->is_recurring,
                    'parent_booking_id'      => $booking->parent_booking_id,
                    'subscription_driver_id' => $booking->subscription_driver_id, // toujours A
                    'user_id'                => $booking->user_id,
                    'client_name'            => $booking->client_name,
                    'next_recurring_date'    => null,
                ]);

                return $booking;
            }

            // CAS 2 — Abonnement parent → annulation + recréation sans agent
            if ($booking->is_subscription_parent && $booking->is_recurring) {
                // Capturer le prix propre à la course retour AVANT toute suppression/écrasement
                $oldReturn = Booking::where('parent_booking_id', $booking->id)
                    ->where('trip_type', 'return')
                    ->first();

                $returnBasePrice  = $oldReturn->base_price  ?? $booking->base_price;
                $returnTotalPrice = $oldReturn->total_price ?? $booking->total_price;

                $booking->update([
                    'status'              => 'cancelled',
                    'cancelled_at'        => now(),
                    'cancellation_reason' => $reason,
                ]);

                // Vérifier s'il y a déjà des enfants J2+ (courses aller uniquement)
                $hasChildren = Booking::where('parent_booking_id', $booking->id)
                    ->where('trip_type', 'go')
                    ->exists();

                if (!$hasChildren) {
                    // Pas encore d'enfants → supprimer la course retour cachée du J1
                    Booking::where('parent_booking_id', $booking->id)
                        ->where('trip_type', 'return')
                        ->delete();
                }

                // Recréer sans subscription_driver_id → visible par tous
                $newParent = Booking::create([
                    'from_location'         => $booking->from_location,
                    'to_location'           => $booking->to_location,
                    'from_lng'              => $booking->from_lng,
                    'from_lat'              => $booking->from_lat,
                    'to_lng'                => $booking->to_lng,
                    'to_lat'                => $booking->to_lat,
                    'distance'              => $booking->distance,
                    'phone'                 => $booking->phone,
                    'days'                  => $booking->days,
                    'remaining_days'        => $booking->remaining_days,
                    'week_days'             => $booking->week_days,
                    'round_trip'            => $booking->round_trip,
                    'return_time'           => $booking->return_time,
                    'trip_type'             => 'go',
                    'pickup_date'           => $booking->pickup_date,
                    'pickup_time'           => $booking->pickup_time,
                    'special_requests'      => $booking->special_requests,
                    'tourist_circuit_id'    => $booking->tourist_circuit_id,
                    'discount'              => $booking->discount,
                    'promo_code_id'         => $booking->promo_code_id,
                    'base_price'            => $booking->base_price,
                    'total_price'           => $booking->total_price,
                    'status'                => 'pending',
                    'is_recurring'          => true,
                    'next_recurring_date'   => $booking->next_recurring_date,
                    'subscription_end_date' => $booking->subscription_end_date,
                    'subscription_driver_id' => null,
                    'user_id'               => $booking->user_id,
                    'client_name'           => $booking->client_name,
                ]);

                // Recréer la course retour cachée si aller-retour
                if ($booking->round_trip && $booking->return_time) {
                    Booking::create([
                        'from_location'          => $booking->to_location,   // inversé
                        'to_location'            => $booking->from_location,
                        'from_lng'               => $booking->to_lng,
                        'from_lat'               => $booking->to_lat,
                        'to_lng'                 => $booking->from_lng,
                        'to_lat'                 => $booking->from_lat,
                        'distance'               => $booking->distance,
                        'phone'                  => $booking->phone,
                        'days'                   => $booking->days,
                        'remaining_days'         => $booking->remaining_days,
                        'week_days'              => $booking->week_days,
                        'round_trip'             => true,
                        'return_time'            => null,
                        'trip_type'              => 'return',
                        'pickup_date'            => $booking->pickup_date,
                        'pickup_time'            => $booking->return_time, // heure retour
                        'special_requests'       => $booking->special_requests,
                        'tourist_circuit_id'     => $booking->tourist_circuit_id,
                        'discount'               => $booking->discount,
                        'promo_code_id'          => $booking->promo_code_id,
                        'base_price'             => $returnBasePrice,
                        'total_price'            => $returnTotalPrice,
                        'status'                 => 'pending',
                        'is_recurring'           => false, // ne recrée pas
                        'parent_booking_id'      => $newParent->id,
                        'subscription_driver_id' => null, // cachée de tous
                        'user_id'                => $booking->user_id,
                        'client_name'            => $booking->client_name,
                        'subscription_end_date'  => $booking->subscription_end_date,
                    ]);
                }

                return $booking;
            }

            // CAS 3 — Course unique → annulation + recréation simple
            // Capturer le prix propre à la course retour AVANT toute suppression
            $oldReturn = Booking::where('parent_booking_id', $booking->id)
                ->where('trip_type', 'return')
                ->first();

            $returnBasePrice  = $oldReturn->base_price  ?? $booking->base_price;
            $returnTotalPrice = $oldReturn->total_price ?? $booking->total_price;

            $booking->update([
                'status'               => 'cancelled',
                'cancelled_at'         => now(),
                'cancellation_reason'  => $reason,
            ]);

            // Créer une nouvelle course avec les mêmes données
            $newBooking = Booking::create([
                'from_location'      => $booking->from_location,
                'to_location'        => $booking->to_location,
                'from_lng'           => $booking->from_lng,
                'from_lat'           => $booking->from_lat,
                'to_lng'             => $booking->to_lng,
                'to_lat'             => $booking->to_lat,
                'distance'           => $booking->distance,
                'phone'              => $booking->phone,
                'days'               => 1,
                'remaining_days'     => 1,
                'pickup_date'        => $booking->pickup_date,
                'pickup_time'        => $booking->pickup_time,
                'round_trip'         => $booking->round_trip,
                'return_time'        => $booking->return_time,
                'trip_type'          => $booking->trip_type,
                'special_requests'   => $booking->special_requests,
                'tourist_circuit_id' => $booking->tourist_circuit_id,
                'discount'           => $booking->discount,
                'promo_code_id'      => $booking->promo_code_id,
                'base_price'         => $booking->base_price,
                'total_price'        => $booking->total_price,
                'status'             => 'pending',
                'is_recurring'       => false,
                'user_id'            => $booking->user_id,
                'client_name'        => $booking->client_name,
            ]);

            // Si aller-retour → recréer aussi la course retour cachée liée au nouvel aller
            if ($booking->round_trip  && $booking->return_time) {
                // Supprimer l'ancienne course retour
                Booking::where('parent_booking_id', $booking->id)
                    ->where('trip_type', 'return')
                    ->delete();

                // Recréer liée au nouvel aller
                Booking::create([
                    'from_location'          => $booking->to_location,   // inversé
                    'to_location'            => $booking->from_location,
                    'from_lng'               => $booking->to_lng,
                    'from_lat'               => $booking->to_lat,
                    'to_lng'                 => $booking->from_lng,
                    'to_lat'                 => $booking->from_lat,
                    'distance'               => $booking->distance,
                    'phone'                  => $booking->phone,
                    'days'                   => 1,
                    'remaining_days'         => 1,
                    'pickup_date'            => $booking->pickup_date,
                    'pickup_time'            => $booking->return_time, // heure retour
                    'round_trip'             => true,
                    'return_time'            => null,
                    'trip_type'              => 'return',
                    'special_requests'       => $booking->special_requests,
                    'tourist_circuit_id'     => $booking->tourist_circuit_id,
                    'discount'               => $booking->discount,
                    'promo_code_id'          => $booking->promo_code_id,
                    'base_price'             => $returnBasePrice,
                    'total_price'            => $returnTotalPrice,
                    'status'                 => 'pending',
                    'is_recurring'           => false, // ne recrée pas
                    'parent_booking_id'      => $newBooking->id,
                    'subscription_driver_id' => null, // cachée de tous
                    'user_id'                => $booking->user_id,
                    'client_name'            => $booking->client_name,
                ]);
            }

            return $booking;
        });
    }

    public function start(string $bookingId, string $driverId)
    {
        return DB::transaction(function () use ($bookingId, $driverId) {

            $booking = Booking::lockForUpdate()->findOrFail($bookingId);

            $driver = Driver::lockForUpdate()->findOrFail($driverId);

            if ($booking->driver_id !== $driverId || $booking->status !== 'confirmed') {
                throw new \Exception('Démarrage non autorisé.');
            }

            $hasOngoingTrip = Booking::where('driver_id', $driverId)
                ->where('status', 'in_progress')
                ->lockForUpdate()
                ->exists();

            if ($hasOngoingTrip) {
                throw new \Exception(
                    'Vous avez déjà une course en cours.'
                );
            }

            if ($driver->hasBlockingPreviousBookings($booking)) {
                throw new \Exception(
                    'Vous devez terminer ou annuler toutes les courses précédentes avant de démarrer celle-ci.'
                );
            }

            $booking->update([
                'status'      => 'in_progress',
                'started_at'  => now(),
            ]);

            return $booking;
        });
    }

    public function complete(string $bookingId, string $driverId)
    {
        return DB::transaction(function () use ($bookingId, $driverId) {

            $booking = Booking::lockForUpdate()->findOrFail($bookingId);

            if (
                $booking->driver_id !== $driverId ||
                $booking->status !== 'in_progress'
            ) {
                throw new \Exception('Finalisation non autorisée.');
            }

            // Calcul à la completion
            $commission    = (int) ceil((($booking->base_price * 15) / 100) / 50) * 50;
            $driverEarning = $booking->base_price - $commission;

            $booking->update([
                'status'        => 'completed',
                'completed_at'  => now(),
                'commission'     => $commission,
                'driver_earning' => $driverEarning,
            ]);

            Driver::where('id', $driverId)->lockForUpdate()->increment('total_trips');

            $commissionData = [
                'driver_id'       => $driverId,
                'booking_id'      => $bookingId,
                'amount'          => $commission,
                'status'          => 'active',
                'date'            => now(),
            ];

            $this->commissionService->create($commissionData);

            return $booking;
        });
    }

    public function revokeFromSubscription(string $bookingId, string $driverId): Booking
    {
        return DB::transaction(function () use ($bookingId, $driverId) {

            $booking = Booking::lockForUpdate()->findOrFail($bookingId);

            // Seul l'agent lié peut révoquer
            if ($booking->subscription_driver_id !== $driverId) {
                throw new \Exception('Vous n\'êtes pas autorisé à révoquer cette course.');
            }

            if (!in_array($booking->status, ['pending', 'confirmed'])) {
                throw new \Exception('Cette course ne peut plus être révoquée.');
            }

            $booking->update([
                'driver_id'   => null,
                'status'      => 'pending',
                'is_revoked'  => true,
                'revoked_at'  => now(),
                'revoked_by'  => $driverId,
                'subscription_driver_id' => null,
            ]);

            return $booking;
        });
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
            if ($booking->is_subscription_child) {
                $this->recordMissedChild($booking->id);
                continue;
            }

            $booking->update([
                'status' => 'expired',
                'expired_at' => Carbon::now(),
            ]);
        }

        return $expiredBookings->count();
    }

    /**
     * Une course enfant d'abonnement que personne n'a prise : elle passe « non traitée »
     * (`missed`), et le trajet est dû au client, rattrapé à la fin de l'abonnement.
     *
     * ⚠️ Le compteur est tenu PAR SENS : un aller-retour dont seul le retour a été manqué
     * ne rattrape que le retour — un jour entier donnerait au client un aller de trop.
     *
     * `expired_at` garde son rôle de date du constat, et empêche un second passage.
     */
    public function recordMissedChild(string $childId): void
    {
        DB::transaction(function () use ($childId) {
            $child = Booking::lockForUpdate()->find($childId);
            if (!$child || !in_array($child->status, ['pending', 'expired'])) return;

            $parent = Booking::lockForUpdate()->find($child->parent_booking_id);
            if (!$parent) return;

            $child->update(['status' => 'missed', 'expired_at' => $child->expired_at ?? now()]);
            $parent->increment($child->trip_type === 'return' ? 'makeup_return_count' : 'makeup_go_count');

            // Abonnement déjà au bout de ses jours normaux : la génération s'était arrêtée.
            // Elle reprend sur le prochain jour autorisé qui n'a pas encore sa course —
            // jamais aujourd'hui, pour que le rattrapage soit lui aussi généré la veille.
            if (!$parent->next_recurring_date && $parent->remaining_days <= 0) {
                $lastChildDate = Booking::where('parent_booking_id', $parent->id)->max('pickup_date');
                $base = Carbon::parse(max($lastChildDate ?? $parent->pickup_date, now()->toDateString()));
                $makeupDay = getNextAllowedDay($base, $parent->week_days ?? 'lun_dim');

                if ($makeupDay) {
                    $parent->update(['next_recurring_date' => $makeupDay->copy()->subDay()->setTime(1, 0)]);
                }
            }
        });

        $child = Booking::find($childId);
        if ($child?->parent_booking_id) {
            $this->catchUpRecurringBookings($child->parent_booking_id);
        }
    }

    /**
     * Reprise du passé : les courses enfants déjà marquées « expirée » des abonnements
     * ENCORE EN COURS passent « non traitée », et sont rattrapées. Les abonnements terminés
     * restent en l'état — décision de l'utilisateur.
     *
     * Un abonnement est en cours s'il lui reste des jours à générer, ou si sa dernière
     * course n'est pas encore passée.
     *
     * @return \Illuminate\Support\Collection<int, Booking> les courses concernées
     */
    public function expiredChildrenOfOngoingSubscriptions(): \Illuminate\Support\Collection
    {
        $today = now()->toDateString();

        return Booking::with('parentBooking')
            ->where('status', 'expired')
            ->whereNotNull('parent_booking_id')
            ->whereHas('parentBooking', function ($parent) use ($today) {
                $parent->where('is_recurring', true)
                    ->whereNull('parent_booking_id')
                    ->whereIn('status', ['confirmed', 'in_progress', 'completed'])
                    ->where(function ($ongoing) use ($today) {
                        $ongoing->where('remaining_days', '>', 0)
                            ->orWhereHas('childBookings', fn ($c) => $c->whereDate('pickup_date', '>=', $today));
                    });
            })
            ->orderBy('pickup_date')
            ->get()
            ->filter(fn ($booking) => $booking->is_subscription_child)
            ->values();
    }

    public function createRecurringBookings()
    {
        $recurringBookings = Booking::where('is_recurring', true)
            ->whereIn('status', ['confirmed', 'in_progress', 'completed'])
            ->where(function ($query) {
                $query->where('remaining_days', '>', 0)
                    ->orWhere('makeup_go_count', '>', 0)
                    ->orWhere('makeup_return_count', '>', 0);
            })
            ->where('trip_type', 'go')
            ->whereNull('parent_booking_id')
            ->where(function ($query) {
                $query->whereNull('next_recurring_date')
                    ->orWhere('next_recurring_date', '<=', now());
            })
            ->get();

        foreach ($recurringBookings as $booking) {
            $this->generateNextRecurringDay($booking->id);
        }

        return $recurringBookings->count();
    }

    /**
     * Génère les journées d'un abonnement dont l'heure de génération est déjà passée.
     *
     * ⚠️ Appelée à l'acceptation : la commande de 1h ne voit que les abonnements déjà
     * acceptés. Accepté le jour de son démarrage APRÈS 1h, un abonnement attendait le
     * passage suivant — le jour J lui-même — pour générer la course du lendemain.
     */
    public function catchUpRecurringBookings(string $bookingId): int
    {
        $generated = 0;

        // Borné par le nombre de jours de l'abonnement : chaque tour en consomme un.
        while ($this->generateNextRecurringDay($bookingId)) {
            $generated++;
        }

        return $generated;
    }

    /**
     * Génère la journée suivante d'un abonnement si son heure est venue. Renvoie false
     * s'il n'y avait rien à générer.
     */
    private function generateNextRecurringDay(string $bookingId): bool
    {
        return DB::transaction(function () use ($bookingId) {
            // Recharger avec verrou
            $booking = Booking::lockForUpdate()->find($bookingId);

            if (
                !$booking
                || !$booking->is_subscription_parent
                || $booking->trip_type !== 'go'
                || ($booking->remaining_days <= 0 && $booking->makeup_go_count <= 0 && $booking->makeup_return_count <= 0)
                || !in_array($booking->status, ['confirmed', 'in_progress', 'completed'])
                || ($booking->next_recurring_date && $booking->next_recurring_date->isFuture())
            ) {
                return false;
            }

            // Créer la nouvelle course pour le jour suivant
            $nextAllowedDay = $booking->next_recurring_date ? Carbon::parse($booking->next_recurring_date)->addDay()->startOfDay() : getNextAllowedDay(Carbon::parse($booking->pickup_date), $booking->week_days ?? 'lun_dim');
            if (!$nextAllowedDay) return false;

            $newPickupDate  = $nextAllowedDay->copy()->setTimeFromTimeString($booking->pickup_time);

            // Jours normaux d'abord ; une fois épuisés, les trajets dus au client après une
            // course non traitée — et seulement ceux-là, sens par sens.
            $hasReturnLeg = $booking->round_trip && $booking->return_time;
            $isMakeup     = $booking->remaining_days <= 0;
            $withGo       = !$isMakeup || $booking->makeup_go_count > 0;
            $withReturn   = $hasReturnLeg && (!$isMakeup || $booking->makeup_return_count > 0);

            $newRemaining = $isMakeup ? 0 : $booking->remaining_days - 1;
            $goLeft       = $booking->makeup_go_count - ($isMakeup && $withGo ? 1 : 0);
            // Sans heure de retour, un retour dû ne peut pas être généré : il ne doit pas
            // non plus faire tourner la commande indéfiniment.
            $returnLeft   = $hasReturnLeg ? $booking->makeup_return_count - ($isMakeup && $withReturn ? 1 : 0) : 0;
            $moreToDo     = $newRemaining > 0 || $goLeft > 0 || $returnLeft > 0;

            // Prochain passage du cron = jour suivant autorisé à 1h
            $nextAllowedForCron = getNextAllowedDay($nextAllowedDay, $booking->week_days ?? 'lun_dim');
            $nextRecurring = $moreToDo && $nextAllowedForCron ? $nextAllowedForCron->copy()->subDay()->setTime(1, 0) : null;

            $subDriverId = $booking->subscription_driver_id;

            // --- Course ALLER ---
            if ($withGo) Booking::create([
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
            if ($withReturn) {
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
            $endDate = $booking->subscription_end_date;
            $booking->update([
                'remaining_days'        => $newRemaining,
                'makeup_go_count'       => max(0, $goLeft),
                'makeup_return_count'   => max(0, $returnLeft),
                'next_recurring_date'   => $nextRecurring,
                // Un rattrapage repousse la fin de l'abonnement.
                'subscription_end_date' => $endDate && $endDate->greaterThan($newPickupDate) ? $endDate : $newPickupDate->toDateString(),
                // ⚠️ Ne PAS repasser `is_recurring` à false au dernier jour : le parent
                // cesserait d'être un abonnement, et ses enfants avec lui — ils sortaient du
                // récap des revenus, et les courses du dernier jour devenaient visibles de
                // tous. `remaining_days` à 0 suffit à arrêter la génération.
            ]);

            return true;
        });
    }
}
