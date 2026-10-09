<?php

namespace Tests\Feature\Fleet;

use App\Domains\Identity\Domain\Enums\Profil;
use App\Models\Driver;
use App\Models\InternalAssignment;
use App\Models\User;
use App\Models\VehicleContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/** L'API admin des affectations internes (spec 2026-10-09, §5.1). */
class InternalAssignmentApiTest extends TestCase
{
    use RefreshDatabase;

    private function login(array $permissions): string
    {
        $user = User::factory()->profil(Profil::Admin)->create(['password' => Hash::make('bon-mot-de-passe')]);

        foreach ($permissions as $permission) {
            $user->givePermissionTo(Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']));
        }

        return $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'bon-mot-de-passe',
        ])->json('token');
    }

    private function asBearer(string $token): self
    {
        Auth::forgetGuards();

        return $this->withHeader('Authorization', "Bearer {$token}");
    }

    public function test_assigning_returns_the_contract_with_its_internal_driver(): void
    {
        $contract = VehicleContract::factory()->create(['status' => 'pending', 'start_date' => null]);
        $driver = Driver::factory()->create();

        $this->asBearer($this->login(['edit-contracts']))
            ->postJson("/api/v1/admin/vehicle-contracts/{$contract->id}/internal-assignments", ['driver_id' => $driver->id, 'start_date' => '2026-10-05'])
            ->assertCreated()
            ->assertJsonPath('current_internal_assignment.driver_id', $driver->id)
            ->assertJsonPath('current_internal_assignment.start_date', '2026-10-05');
    }

    public function test_ending_moves_it_to_the_history(): void
    {
        $assignment = InternalAssignment::factory()->create(['start_date' => '2026-10-01']);

        $this->asBearer($this->login(['edit-contracts']))
            ->postJson("/api/v1/admin/internal-assignments/{$assignment->id}/end", ['end_date' => '2026-10-08'])
            ->assertOk()
            ->assertJsonPath('current_internal_assignment', null)
            ->assertJsonPath('internal_assignments.0.end_date', '2026-10-08');
    }

    public function test_the_routes_need_the_edit_permission(): void
    {
        $contract = VehicleContract::factory()->create();

        $this->asBearer($this->login([]))
            ->postJson("/api/v1/admin/vehicle-contracts/{$contract->id}/internal-assignments", ['driver_id' => Driver::factory()->create()->id, 'start_date' => '2026-10-05'])
            ->assertForbidden();
    }

    public function test_a_refusal_keeps_its_business_code(): void
    {
        $contract = VehicleContract::factory()->create();
        InternalAssignment::factory()->for($contract, 'vehicleContract')->create();

        $this->asBearer($this->login(['edit-contracts']))
            ->postJson("/api/v1/admin/vehicle-contracts/{$contract->id}/internal-assignments", ['driver_id' => Driver::factory()->create()->id, 'start_date' => '2026-10-05'])
            ->assertStatus(409)->assertJsonPath('code', 'VEHICLE_ALREADY_DRIVEN');
    }

    public function test_the_driver_detail_shows_the_ongoing_assignment(): void
    {
        $assignment = InternalAssignment::factory()->create(['start_date' => '2026-10-01']);

        $this->asBearer($this->login(['view-drivers']))
            ->getJson("/api/v1/admin/drivers/{$assignment->driver_id}")
            ->assertOk()
            ->assertJsonPath('internal_assignment.vehicle_number', $assignment->vehicleContract->vehicle->vehicle_number);
    }

    public function test_assigning_and_ending_are_logged(): void
    {
        $contract = VehicleContract::factory()->create();
        $driver = Driver::factory()->create();
        $token = $this->login(['edit-contracts']);

        $this->asBearer($token)->postJson("/api/v1/admin/vehicle-contracts/{$contract->id}/internal-assignments", ['driver_id' => $driver->id, 'start_date' => '2026-10-05'])->assertCreated();
        $assignment = InternalAssignment::sole();
        $this->asBearer($token)->postJson("/api/v1/admin/internal-assignments/{$assignment->id}/end", ['end_date' => '2026-10-08'])->assertOk();

        $this->assertSame(1, Activity::where('event', 'vehicle.internal_assignment_created')->where('subject_id', $contract->id)->count());
        $this->assertSame(1, Activity::where('event', 'vehicle.internal_assignment_ended')->where('subject_id', $contract->id)->count());
    }

    public function test_only_free_active_drivers_can_be_assigned(): void
    {
        $free = Driver::factory()->create();
        $underContract = Driver::factory()->create();
        \App\Models\DriverContract::factory()->create(['driver_id' => $underContract->id, 'status' => 'active']);
        $internal = InternalAssignment::factory()->create()->driver;
        $inactive = Driver::factory()->create();
        $inactive->user->update(['is_active' => false]);

        $ids = collect($this->asBearer($this->login(['edit-contracts']))
            ->getJson('/api/v1/admin/internal-assignments/available-drivers')
            ->assertOk()->json())->pluck('id');

        $this->assertTrue($ids->contains($free->id));
        $this->assertFalse($ids->contains($underContract->id));
        $this->assertFalse($ids->contains($internal->id));
        $this->assertFalse($ids->contains($inactive->id));
    }
}
