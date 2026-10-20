<?php

namespace Tests\Feature\Fleet;

use App\Domains\Fleet\Application\Actions\AssignInternalDriver;
use App\Domains\Fleet\Application\Actions\TakeOverVehicle;
use App\Domains\Fleet\Domain\PreparationSteps;
use App\Domains\Identity\Domain\Enums\Profil;
use App\Models\Driver;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleContract;
use App\Models\VehicleContractPreparationStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/** La naissance des étapes et les statuts du contrat (spec 2026-10-20, §4.1, §4.3, §4.3 bis, §4.3 ter). */
class PreparationStepsLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function asAdmin(): self
    {
        $user = User::factory()->profil(Profil::Admin)->create(['password' => Hash::make('bon-mot-de-passe')]);
        foreach (['create-contracts', 'edit-contracts'] as $permission) {
            $user->givePermissionTo(Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']));
        }
        $token = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'bon-mot-de-passe'])->json('token');
        Auth::forgetGuards();

        return $this->withHeader('Authorization', "Bearer {$token}");
    }

    private function payload(array $overrides = []): array
    {
        return array_merge(['contract_months' => 30, 'total_amount' => 3_604_872, 'start_date' => '2026-10-01'], $overrides);
    }

    private function pending(): VehicleContract
    {
        $contract = VehicleContract::factory()->create(['status' => 'pending', 'start_date' => null, 'contract_months' => 30, 'total_amount' => 3_604_872]);
        PreparationSteps::seedFor($contract);

        return $contract;
    }

    private function arrival(VehicleContract $contract): VehicleContractPreparationStep
    {
        return $contract->preparationSteps()->where('is_agent_arrival', true)->firstOrFail();
    }

    public function test_a_pending_contract_is_born_with_the_steps(): void
    {
        $vehicle = Vehicle::factory()->create();

        $id = $this->asAdmin()->postJson('/api/v1/admin/vehicle-contracts', $this->payload(['vehicle_id' => $vehicle->id, 'pending' => true]))
            ->assertCreated()->json('contract.id') ?? VehicleContract::firstOrFail()->id;

        $this->assertSame(10, VehicleContract::findOrFail($id)->preparationSteps()->count());
    }

    public function test_an_active_contract_is_born_without_steps(): void
    {
        $vehicle = Vehicle::factory()->create();

        $this->asAdmin()->postJson('/api/v1/admin/vehicle-contracts', $this->payload(['vehicle_id' => $vehicle->id]))->assertCreated();

        $this->assertSame(0, VehicleContractPreparationStep::count());
    }

    public function test_a_contract_agent_ticks_the_arrival_at_its_start_date(): void
    {
        $contract = $this->pending();

        DB::transaction(fn () => app(TakeOverVehicle::class)($contract, '2026-10-21', Driver::factory()->create()));

        $this->assertSame('active', $contract->fresh()->status);
        $this->assertSame('2026-10-21', $this->arrival($contract)->done_on?->toDateString());
        $this->assertSame(9, $contract->preparationSteps()->whereNull('done_on')->count());
    }

    public function test_an_internal_assignment_ticks_nothing(): void
    {
        $contract = $this->pending();

        app(AssignInternalDriver::class)($contract, Driver::factory()->create(), '2026-10-15', null, null);

        $this->assertSame(0, $contract->preparationSteps()->whereNotNull('done_on')->count());
    }

    public function test_manual_activation_ticks_the_arrival_at_the_start_date(): void
    {
        $contract = $this->pending();

        $this->asAdmin()->putJson("/api/v1/admin/vehicle-contracts/{$contract->id}", $this->payload(['status' => 'active', 'start_date' => '2026-10-18']))->assertOk();

        $this->assertSame('2026-10-18', $this->arrival($contract)->done_on?->toDateString());
    }

    public function test_back_to_pending_reopens_the_arrival(): void
    {
        $contract = $this->pending();
        $this->arrival($contract)->update(['done_on' => '2026-10-18']);
        $contract->update(['status' => 'active', 'start_date' => '2026-10-18']);

        $this->asAdmin()->putJson("/api/v1/admin/vehicle-contracts/{$contract->id}", $this->payload(['status' => 'pending']))->assertOk();

        $this->assertNull($this->arrival($contract)->done_on);
    }

    public function test_back_to_pending_seeds_a_contract_without_steps(): void
    {
        $contract = VehicleContract::factory()->create(['status' => 'active', 'start_date' => '2026-10-01', 'contract_months' => 30, 'total_amount' => 3_604_872]);

        $this->asAdmin()->putJson("/api/v1/admin/vehicle-contracts/{$contract->id}", $this->payload(['status' => 'pending']))->assertOk();

        $this->assertSame(10, $contract->preparationSteps()->count());
    }

    public function test_cancel_then_pending_keeps_the_steps(): void
    {
        $contract = $this->pending();
        $contract->preparationSteps()->first()->update(['done_on' => '2026-10-10']);

        $this->asAdmin()->putJson("/api/v1/admin/vehicle-contracts/{$contract->id}", $this->payload(['status' => 'cancelled']))->assertOk();
        $this->asAdmin()->putJson("/api/v1/admin/vehicle-contracts/{$contract->id}", $this->payload(['status' => 'pending']))->assertOk();

        $this->assertSame(10, $contract->preparationSteps()->count());
        $this->assertSame(1, $contract->preparationSteps()->whereNotNull('done_on')->count());
    }
}
