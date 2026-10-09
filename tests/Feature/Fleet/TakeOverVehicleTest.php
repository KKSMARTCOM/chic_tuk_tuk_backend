<?php

namespace Tests\Feature\Fleet;

use App\Domains\Fleet\Application\Actions\TakeOverVehicle;
use App\Domains\Identity\Domain\Enums\Profil;
use App\Models\Driver;
use App\Models\InternalAssignment;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Spec 2026-10-09, §4. */
class TakeOverVehicleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::firstOrCreate(['name' => 'driver', 'guard_name' => 'web']);
    }

    public function test_it_ends_the_internal_assignment_the_day_before(): void
    {
        $contract = VehicleContract::factory()->create(['start_date' => '2026-09-01']);
        $assignment = InternalAssignment::factory()->for($contract, 'vehicleContract')->create(['start_date' => '2026-10-01']);

        $result = app(TakeOverVehicle::class)($contract, '2026-10-12', Driver::factory()->create());

        $this->assertSame('2026-10-11', $assignment->fresh()->end_date->toDateString());
        $this->assertSame('driver_contract', $assignment->fresh()->ended_reason);
        $this->assertTrue($result->endedAssignment->is($assignment));
        $this->assertFalse($result->activated);
    }

    public function test_it_activates_a_pending_contract_on_the_agent_start(): void
    {
        $contract = VehicleContract::factory()->create(['status' => 'pending', 'start_date' => null]);

        $result = app(TakeOverVehicle::class)($contract, '2026-10-12', Driver::factory()->create());

        $this->assertSame('active', $contract->fresh()->status);
        $this->assertSame('2026-10-12', $contract->fresh()->start_date->toDateString());
        $this->assertTrue($result->activated);
    }

    public function test_the_same_driver_moves_from_internal_to_contract(): void
    {
        $contract = VehicleContract::factory()->create(['status' => 'pending', 'start_date' => null]);
        $driver = Driver::factory()->create();
        InternalAssignment::factory()->for($contract, 'vehicleContract')->for($driver)->create(['start_date' => '2026-10-01']);

        app(TakeOverVehicle::class)($contract, '2026-10-02', $driver);

        $this->assertNull($driver->fresh()->activeInternalAssignment);
    }

    public function test_an_agent_starting_on_the_assignment_start_is_refused(): void
    {
        $contract = VehicleContract::factory()->create(['status' => 'pending', 'start_date' => null]);
        InternalAssignment::factory()->for($contract, 'vehicleContract')->create(['start_date' => '2026-10-05']);

        try {
            app(TakeOverVehicle::class)($contract, '2026-10-05', Driver::factory()->create());
            $this->fail('Un contrat le jour même du début de l\'affectation devait être refusé.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('start_date', $e->errors());
        }
        $this->assertSame('pending', $contract->fresh()->status);
    }

    public function test_moving_a_driver_contract_takes_over_the_arrival_vehicle(): void
    {
        // Relecture du 2026-10-09 : le déplacement laissait l'affectation interne ouverte.
        $from = VehicleContract::factory()->create(['start_date' => '2026-09-01']);
        $contract = \App\Models\DriverContract::factory()->create([
            'vehicle_contract_id' => $from->id, 'vehicle_id' => $from->vehicle_id, 'status' => 'active', 'start_date' => '2026-10-01',
        ]);
        $to = VehicleContract::factory()->create(['status' => 'pending', 'start_date' => null]);
        $assignment = InternalAssignment::factory()->for($to, 'vehicleContract')->create(['start_date' => '2026-09-20']);

        app(\App\Domains\Workforce\Application\Actions\UpdateDriverContract::class)(
            $contract,
            \App\Domains\Workforce\Application\Data\UpdateDriverContractData::from(['start_date' => '2026-10-12', 'contract_months' => 24, 'vehicle_id' => $to->vehicle_id]),
        );

        $this->assertSame($to->id, $contract->fresh()->vehicle_contract_id);
        $this->assertSame('2026-10-11', $assignment->fresh()->end_date->toDateString());
        $this->assertSame('active', $to->fresh()->status);
    }

    public function test_creating_an_agent_through_the_api_takes_over_a_pending_vehicle(): void
    {
        [, $token] = $this->connecter(Profil::Admin, ['create-drivers']);
        $owner = $this->owner();
        $vehicle = Vehicle::factory()->create(['owner_id' => $owner->id]);
        $contract = VehicleContract::factory()->create(['vehicle_id' => $vehicle->id, 'owner_id' => $owner->id, 'status' => 'pending', 'start_date' => null]);
        $assignment = InternalAssignment::factory()->for($contract, 'vehicleContract')->create(['start_date' => '2026-10-01']);

        $this->entete($token)->postJson('/api/v1/admin/drivers', $this->payload([
            'vehicle_id' => $vehicle->id, 'owner_id' => $owner->id, 'start_date' => '2026-10-12', 'contract_months' => 24,
        ]))->assertCreated();

        $this->assertSame('active', $contract->fresh()->status);
        $this->assertSame('2026-10-12', $contract->fresh()->start_date->toDateString());
        $this->assertSame('2026-10-11', $assignment->fresh()->end_date->toDateString());
    }

    public function test_an_agent_assigned_internally_elsewhere_gets_no_contract(): void
    {
        [, $token] = $this->connecter(Profil::Admin, ['create-drivers', 'edit-drivers']);
        $owner = $this->owner();
        $vehicle = Vehicle::factory()->create(['owner_id' => $owner->id]);
        VehicleContract::factory()->create(['vehicle_id' => $vehicle->id, 'owner_id' => $owner->id]);

        // Affecté en interne ailleurs : la modification ne lui donne pas de contrat.
        $driver = Driver::factory()->create();
        InternalAssignment::factory()->for($driver)->create();

        $this->entete($token)->putJson("/api/v1/admin/drivers/{$driver->id}", [
            'name' => $driver->user->name, 'phone' => $driver->user->phone, 'license_number' => 'B',
            'owner_mode' => 'existing', 'owner_id' => $owner->id, 'vehicle_id' => $vehicle->id,
            'existing_start_date' => '2026-10-12', 'existing_contract_months' => 24,
        ])->assertStatus(422);

        $this->assertDatabaseCount('driver_contracts', 0);
    }

    /** @return array{0: User, 1: string} */
    private function connecter(Profil $profil, array $permissions): array
    {
        $user = User::factory()->profil($profil)->create(['password' => Hash::make('bon-mot-de-passe')]);

        foreach ($permissions as $permission) {
            $user->givePermissionTo(Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']));
        }

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'bon-mot-de-passe',
        ])->json('token');

        return [$user, $token];
    }

    private function entete(string $token): self
    {
        Auth::forgetGuards();

        return $this->withHeader('Authorization', "Bearer {$token}");
    }

    private function owner(): User
    {
        $owner = User::factory()->profil(Profil::Owner)->create();
        $owner->assignRole(Role::firstOrCreate(['name' => 'proprietaire', 'guard_name' => 'web']));

        return $owner;
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Nouvel Agent',
            'email' => null,
            'phone' => '90'.random_int(100000, 999999),
            'password' => 'MotDePasse2026!',
            'adresse' => null,
            'license_number' => 'B',
            'agent_code' => null,
            'agent_id' => null,
            'contract_mode' => 'new',
            'owner_id' => null,
            'vehicle_id' => null,
            'contract_months' => null,
            'start_date' => null,
            'renewal_agent_code' => null,
            'renewal_agent_id' => null,
            'renewal_owner_id' => null,
            'renewal_vehicle_id' => null,
            'renewal_contract_months' => null,
            'renewal_start_date' => null,
        ], $overrides);
    }
}
