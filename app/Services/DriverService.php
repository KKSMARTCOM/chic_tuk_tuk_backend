<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Driver;
use App\Models\DriverContract;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleContract;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\Fluent\Concerns\Has;

class DriverService
{
    protected BookingService $bookingService;
    protected DriverContractService $driverContractService;

    public function __construct(BookingService $bookingService, DriverContractService $driverContractService)
    {
        $this->bookingService = $bookingService;
        $this->driverContractService = $driverContractService;
    }

    public function getAllDrivers($filters = [])
    {
        $query = User::query()
            ->where('profil', 'driver')
            ->with('driver');

        if (isset($filters['search']) && !empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', '%' . $search . '%')
                    ->orWhere('email', 'like', '%' . $search . '%')
                    ->orWhere('phone', 'like', '%' . $search . '%')
                    ->orWhereHas('driver', function ($driverQuery) use ($search) {
                        $driverQuery->where('license_number', 'like', '%' . $search . '%')
                            ->orWhere('vehicle_number', 'like', '%' . $search . '%');
                    });
            });
        }

        if (isset($filters['is_active']) && $filters['is_active'] !== '') {
            $query->where('is_active', $filters['is_active']);
        }

        if (isset($filters['is_available']) && $filters['is_available'] !== '') {
            $query->whereHas('driver', function ($q) use ($filters) {
                $q->where('is_available', $filters['is_available']);
            });
        }

        return $query->latest()->get();
    }

    public function getDriverStats()
    {
        $totalDrivers = User::where('profil', 'driver')->count();
        $activeDrivers = User::where('profil', 'driver')->where('is_active', true)->count();
        $inactiveDrivers = $totalDrivers - $activeDrivers;
        $availableDrivers = Driver::where('is_available', true)->count();

        return [
            'total' => $totalDrivers,
            'active' => $activeDrivers,
            'inactive' => $inactiveDrivers,
            'available' => $availableDrivers,
        ];
    }

    public function getDriverById($driverId)
    {
        return User::where('profil', 'driver')
            ->with(['driver', 'driver.bookings' => function ($query) {
                $query->orderByRaw("CONCAT(pickup_date, ' ', pickup_time) DESC");
            }])
            ->findOrFail($driverId);
    }

    public function getDriverBookingStats($driverId)
    {
        $driver = Driver::findOrFail($driverId);

        $bookings = $driver->bookings();

        return [
            'total' => (clone $bookings)->count(),
            'completed' => (clone $bookings)->where('status', 'completed')->count(),
            'cancelled' => (clone $bookings)->where('status', 'cancelled')->count(),
            'confirmed' => (clone $bookings)->where('status', 'confirmed')->count(),
            'in_progress' => (clone $bookings)->where('status', 'in_progress')->count(),
            'total_minutes' => $this->calculateTotalDrivingMinutes($driver),
            'average_rating' => $driver->rating ?? 0,
        ];
    }

    private function calculateTotalDrivingMinutes(Driver $driver)
    {
        $completedBookings = $driver->bookings()
            ->where('status', 'completed')
            ->whereNotNull('started_at')
            ->whereNotNull('completed_at')
            ->get();

        $totalMinutes = 0;

        foreach ($completedBookings as $booking) {
            $totalMinutes += $booking->started_at->diffInMinutes($booking->completed_at);
        }

        return $totalMinutes;
    }

    public function createDriver(array $data)
    {
        return DB::transaction(function () use ($data) {

            $contractMode = $data['_contract_mode'] ?? 'new';

            // 1. Créer l'utilisateur agent
            $user = User::create([
                'name'      => $data['name'],
                'email'     => $data['email']   ?? null,
                'phone'     => $data['phone'],
                'password'  => Hash::make($data['password']),
                'profil'    => 'driver',
                'is_active' => true,
                'adresse'   => $data['adresse'] ?? null,
            ]);

            $user->assignRole('driver');

            // 2. Créer le profil Driver
            $driver = Driver::create([
                'user_id'        => $user->id,
                'license_number' => $data['license_number'],
                'is_available'   => true,
                'agent_code'     => $data['agent_code'] ?? $data['renewal_agent_code'] ?? null,
                'agent_id'       => $data['agent_id']   ?? $data['renewal_agent_id'] ?? null,
            ]);

            // 3. Résoudre le véhicule selon le mode
            if ($contractMode === 'renewal') {
                $this->createRenewalContract($driver, $data);
            } else {
                $this->createNewContract($driver, $data);
            }

            return $user->load('driver');
        });
    }

    // ── Nouveau contrat ───────────────────────────────────────────

    private function createNewContract(Driver $driver, array $data): void
    {
        // Pas de véhicule sélectionné → pas de contrat
        if (empty($data['vehicle_id']) || empty($data['owner_id'])) return;

        $vehicle = Vehicle::findOrFail($data['vehicle_id']);

        if ($vehicle->owner_id !== $data['owner_id']) {
            throw new \Exception('Ce véhicule n\'appartient pas au propriétaire sélectionné.');
        }

        $vehicleContract = $vehicle->activeVehicleContract;

        if (!$vehicleContract) {
            throw new \Exception('Le véhicule sélectionné n\'a pas de contrat actif.');
        }

        // Validation règles métier (1 véhicule = 1 agent)
        $this->driverContractService->validateVehicleAssignment($vehicle);

        // Clôturer la pause active du véhicule si existante
        $vehicle->activePause?->update(['end_date' => $data['start_date'] ?? now()->toDateString()]);

        DriverContract::create([
            'driver_id'           => $driver->id,
            'vehicle_id'          => $vehicle->id,
            'vehicle_contract_id' => $vehicleContract->id,
            'start_date'          => $data['start_date']      ?? now()->toDateString(),
            'contract_months'     => $data['contract_months'] ?? $vehicleContract->contract_months,
            'status'              => 'active',
        ]);
    }

    // ── Reconduction ─────────────────────────────────────────────

    private function createRenewalContract(Driver $driver, array $data): void
    {
        $vehicle = Vehicle::findOrFail($data['renewal_vehicle_id']);

        // Vérifier que le véhicule appartient au propriétaire sélectionné
        if ($vehicle->owner_id !== $data['renewal_owner_id']) {
            throw new \Exception('Ce véhicule n\'appartient pas au propriétaire sélectionné.');
        }

        $vehicleContract = $vehicle->activeVehicleContract;

        if (!$vehicleContract) {
            throw new \Exception('Le véhicule sélectionné n\'a pas de contrat actif.');
        }

        // Calculer les mois déjà utilisés sur ce contrat proprio-véhicule
        $monthsUsed = DriverContract::where('vehicle_id', $vehicle->id)
            ->where('status', 'ended')
            ->get(['start_date', 'end_date'])
            ->sum(function ($contract) {
                return ($contract->end_date->year * 12 + $contract->end_date->month)
                    - ($contract->start_date->year * 12 + $contract->start_date->month)
                    + 1;
            });

        $remainingMonths = max(0, $vehicleContract->contract_months - $monthsUsed);

        if ($remainingMonths <= 0) {
            throw new \Exception('Ce contrat véhicule ne dispose plus de temps restant pour une reconduction.');
        }

        // La durée demandée ne peut pas être inférieure à 1 mois et ne peut pas dépasser le temps restant sur le contrat
        $requestedMonths = (int) $data['renewal_contract_months'];
        if ($requestedMonths < 1) {
            throw new \Exception("La durée demandée ({$requestedMonths} mois) est inférieure à 1 mois.");
        }

        if ($requestedMonths < $remainingMonths) {
            throw new \Exception(
                "La durée demandée ({$requestedMonths} mois) est inférieure au temps restant ({$remainingMonths} mois) sur ce contrat."
            );
        }

        // Validation règles métier (1 véhicule = 1 agent)
        $this->driverContractService->validateVehicleAssignment($vehicle);

        // Clôturer la pause active du véhicule si existante
        $vehicle->activePause?->update(['end_date' => $data['renewal_start_date']]);

        //Mettre le status du véhicule à actif
        $vehicle->update(['is_active' => true]);

        DriverContract::create([
            'driver_id'           => $driver->id,
            'vehicle_id'          => $vehicle->id,
            'vehicle_contract_id' => $vehicleContract->id,
            'start_date'          => $data['renewal_start_date'],
            'contract_months'     => $requestedMonths,
            'status'              => 'active',
        ]);
    }

    public function updateDriver(string $driverId, array $data): User
    {
        return DB::transaction(function () use ($driverId, $data) {

            $user = User::findOrFail($driverId);

            // 1. Mettre à jour les infos du User
            $user->update([
                'name'      => $data['name'],
                'email'     => $data['email']    ?? $user->email,
                'phone'     => $data['phone'],
                'is_active' => $data['is_active'] ?? $user->is_active,
                'adresse'   => $data['adresse']   ?? $user->adresse,
            ]);

            // 2. Mettre à jour le profil Driver
            if ($user->driver) {
                $user->driver->update([
                    'license_number' => $data['license_number'] ?? $user->driver->license_number,
                    'is_available'   => $data['is_available'] ?? $user->driver->is_available,
                    'agent_code'     => $data['agent_code'] ?? $data['renewal_agent_code'] ?? $user->driver->agent_code,
                    'agent_id'       => $data['agent_id']   ?? $data['renewal_agent_id']   ?? $user->driver->agent_id,
                ]);
            }

            // 3. Si pas de contrat actif → créer un nouveau contrat
            if (!($data['_has_active_contract'] ?? true)) {

                $mode = $data['_owner_mode'] ?? 'existing';

                if ($mode === 'renewal') {
                    // Réutilise exactement la même logique que pour la création
                    $this->createRenewalContract($user->driver, $data);
                } elseif ($mode === 'existing') {
                    $vehicle = Vehicle::findOrFail($data['vehicle_id']);

                    if ($vehicle->owner_id !== $data['owner_id']) {
                        throw new \Exception('Ce véhicule n\'appartient pas au propriétaire sélectionné.');
                    }

                    $this->driverContractService->validateVehicleAssignment($vehicle);

                    DriverContract::create([
                        'driver_id'           => $user->driver->id,
                        'vehicle_id'          => $vehicle->id,
                        'vehicle_contract_id' => $vehicle->activeVehicleContract?->id,
                        'start_date'          => $data['existing_start_date'],
                        'contract_months'     => $data['existing_contract_months'],
                        'status'              => 'active',
                    ]);
                } else {
                    // ── mode 'new' : code existant inchangé ──
                    $ownerRole = \Spatie\Permission\Models\Role::firstOrCreate(
                        ['name' => 'proprietaire', 'guard_name' => 'web'],
                        ['label' => 'Propriétaire']
                    );

                    $owner = User::create([
                        'name'      => $data['new_owner_name'],
                        'phone'     => $data['new_owner_phone'],
                        'email'     => $data['new_owner_email'] ?? null,
                        'password'  => Hash::make($data['new_owner_password']),
                        'profil'    => 'client',
                        'is_active' => true,
                    ]);
                    $owner->assignRole($ownerRole);

                    $vehicle = Vehicle::create([
                        'owner_id'       => $owner->id,
                        'vehicle_number' => $data['new_vehicle_number'],
                        'vehicle_type'   => $data['new_vehicle_type']  ?? 'tricycle',
                        'color'          => $data['new_vehicle_color'] ?? null,
                        'is_active'      => true,
                    ]);

                    if (!empty($data['contract_total_amount'])) {
                        VehicleContract::create([
                            'vehicle_id'      => $vehicle->id,
                            'owner_id'        => $owner->id,
                            'total_amount'    => $data['contract_total_amount'],
                            'monthly_payment' => $data['contract_monthly_payment'] ?? 0,
                            'start_date'      => $data['contract_start_date']      ?? now(),
                            'end_date'        => $data['contract_end_date']         ?? null,
                            'status'          => 'active',
                        ]);
                    }

                    DriverContract::create([
                        'driver_id'           => $user->driver->id,
                        'vehicle_id'          => $vehicle->id,
                        'vehicle_contract_id' => $vehicle->activeVehicleContract?->id,
                        'start_date'          => $data['new_start_date'],
                        'contract_months'     => $data['new_contract_months'],
                        'status'              => 'active',
                    ]);
                }
            }

            return $user->load('driver');
        });
    }

    public function updateDriverPassword(string $driverId, string $password)
    {
        $user = User::findOrFail($driverId);
        $user->update(['password' => Hash::make($password)]);
    }

    public function deleteDriver(string $driverId)
    {
        $user = User::findOrFail($driverId);

        // Vérifier s'il a des courses en cours
        if ($user->driver && $user->driver->bookings()->whereIn('status', ['confirmed', 'in_progress'])->exists()) {
            throw new \Exception('Impossible de supprimer un Agent avec des courses en cours.');
        }

        $user->delete();
    }

    public function getDriverDashboardStats(Driver $driver)
    {
        // Calcul du temps total de courses en minutes
        $total_duration_seconds = Booking::where('driver_id', $driver->id)
            ->where('status', 'completed')
            ->whereNotNull('started_at')
            ->whereNotNull('completed_at')
            ->get()
            ->sum(function ($booking) {
                return $booking->started_at->diffInSeconds($booking->completed_at);
            });

        // Courses disponibles pour cet agent (même logique que getAvailableBookings)
        $recentBookings = $this->bookingService->getAvailableBookings($driver->id)
            ->take(5);

        $recentBookingsAccepting = Booking::where('driver_id', $driver->id)
            ->where('status', 'confirmed')
            ->orderByRaw("(pickup_date::date + pickup_time::time) ASC")
            ->take(5)
            ->get();

        $stats = [
            'total_trips' => $driver->total_trips,
            'rating' => $driver->rating,
            'confirmed_trips' => Booking::where('driver_id', $driver->id)
                ->where('status', 'confirmed')->count(),
            'completed_trips' => Booking::where('driver_id', $driver->id)
                ->where('status', 'completed')->count(),
            'cancelled_trips' => Booking::where('driver_id', $driver->id)
                ->where('status', 'cancelled')->count(),

            'earnings_today' => Booking::where('driver_id', $driver->id)
                ->where('status', 'completed')
                ->whereDate('completed_at', today())
                ->sum('driver_earning'),

            'total_earnings' => Booking::where('driver_id', $driver->id)
                ->where('status', 'completed')
                ->sum('driver_earning'),

            'commission_today' => Booking::where('driver_id', $driver->id)
                ->where('status', 'completed')
                ->whereDate('completed_at', today())
                ->sum('commission'),

            'total_commission' => Booking::where('driver_id', $driver->id)
                ->where('status', 'completed')
                ->sum('commission'),

            'total_duration_minutes' => round($total_duration_seconds / 60),
            'recent_bookings' => $recentBookings,
            'recent_bookings_accepting' => $recentBookingsAccepting
        ];

        return $stats;
    }

    public function getAllDriversForExport($filters = [])
    {
        $query = User::where('profil', 'driver')->with('driver');

        if (isset($filters['search']) && !empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', '%' . $search . '%')
                    ->orWhere('email', 'like', '%' . $search . '%')
                    ->orWhere('phone', 'like', '%' . $search . '%')
                    ->orWhereHas('driver', function ($driverQuery) use ($search) {
                        $driverQuery->where('license_number', 'like', '%' . $search . '%')
                            ->orWhere('vehicle_number', 'like', '%' . $search . '%');
                    });
            });
        }

        if (isset($filters['is_active']) && $filters['is_active'] !== '') {
            $query->where('is_active', $filters['is_active']);
        }

        if (isset($filters['is_available']) && $filters['is_available'] !== '') {
            $query->whereHas('driver', function ($q) use ($filters) {
                $q->where('is_available', $filters['is_available']);
            });
        }

        return $query->latest()->get();
    }
}
