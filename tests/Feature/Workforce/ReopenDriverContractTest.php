<?php

namespace Tests\Feature\Workforce;

use App\Domains\Identity\Domain\Enums\Profil;
use App\Domains\Workforce\Application\Actions\EndDriverContract;
use App\Domains\Workforce\Application\Data\EndDriverContractData;
use App\Models\DriverContract;
use App\Models\InternalAssignment;
use App\Models\RemunerationStatement;
use App\Models\User;
use App\Models\VehicleContract;
use App\Models\VehiclePause;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * « Annuler la fin du contrat » (spec 2026-10-21, §5) : l'administrateur seul défait une fin
 * de contrat agent saisie par erreur — contrat, pause, véhicule et agent rétablis d'un coup.
 */
class ReopenDriverContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-21 10:00:00'); // un mercredi
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function token(array $permissions): string
    {
        $user = User::factory()->profil(Profil::Admin)->create(['password' => Hash::make('bon-mot-de-passe')]);
        foreach ($permissions as $permission) {
            $user->givePermissionTo(Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']));
        }

        return $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'bon-mot-de-passe'])->json('token');
    }

    private function as(string $token): self
    {
        Auth::forgetGuards();

        return $this->withHeader('Authorization', "Bearer {$token}");
    }

    /** Un contrat terminé le lundi 2026-10-19 par une démission. */
    private function endedContract(): DriverContract
    {
        $vehicleContract = VehicleContract::factory()->create();
        $contract = DriverContract::factory()->forVehicleContract($vehicleContract)->create(['start_date' => '2026-06-01']);
        app(EndDriverContract::class)($contract, new EndDriverContractData('2026-10-19', 'demission'));

        return $contract->refresh();
    }

    private function reopen(DriverContract $contract, array $permissions = ['reopen-driver-contracts'])
    {
        return $this->as($this->token($permissions))->postJson("/api/v1/admin/driver-contracts/{$contract->id}/reopen");
    }

    public function test_reopening_undoes_the_end(): void
    {
        $contract = $this->endedContract();

        $this->reopen($contract)->assertOk()->assertJsonPath('contract.status', 'active');

        $contract->refresh();
        $this->assertNull($contract->end_date);
        $this->assertNull($contract->end_reason);
        $this->assertFalse(VehiclePause::where('driver_contract_id', $contract->id)->where('reason_type', 'agent_change')->exists());
        $this->assertTrue((bool) $contract->vehicle->refresh()->is_active);
        $this->assertTrue((bool) $contract->driver->user->refresh()->is_active);
        $this->assertTrue($contract->driver->refresh()->is_available);
        $this->assertDatabaseHas('activity_log', ['event' => 'driver.contract_reopened', 'subject_id' => $contract->id]);
        $this->assertDatabaseHas('notifications', ['user_id' => $contract->vehicle->owner_id, 'title' => 'Reprise du véhicule']);
    }

    public function test_reopening_twice_is_refused(): void
    {
        $contract = $this->endedContract();
        $token = $this->token(['reopen-driver-contracts']);

        $this->as($token)->postJson("/api/v1/admin/driver-contracts/{$contract->id}/reopen")->assertOk();
        $this->as($token)->postJson("/api/v1/admin/driver-contracts/{$contract->id}/reopen")
            ->assertStatus(409)->assertJsonPath('code', 'DRIVER_CONTRACT_NOT_ENDED');
    }

    public function test_a_vehicle_taken_by_another_agent_is_refused(): void
    {
        $contract = $this->endedContract();
        DriverContract::factory()->forVehicleContract($contract->vehicleContract)->create(['start_date' => '2026-10-20']);

        $this->reopen($contract)->assertStatus(409)->assertJsonPath('code', 'DRIVER_CONTRACT_VEHICLE_TAKEN');
    }

    public function test_a_vehicle_taken_by_an_internal_agent_is_refused(): void
    {
        $contract = $this->endedContract();
        InternalAssignment::factory()->create(['vehicle_contract_id' => $contract->vehicle_contract_id, 'start_date' => '2026-10-20']);

        $this->reopen($contract)->assertStatus(409)->assertJsonPath('code', 'DRIVER_CONTRACT_VEHICLE_TAKEN');
    }

    public function test_an_agent_with_another_contract_is_refused(): void
    {
        $contract = $this->endedContract();
        DriverContract::factory()->create(['driver_id' => $contract->driver_id]);

        $this->reopen($contract)->assertStatus(409)->assertJsonPath('code', 'DRIVER_HAS_ACTIVE_CONTRACT');
    }

    public function test_a_validated_statement_after_the_end_is_refused(): void
    {
        $contract = $this->endedContract();
        RemunerationStatement::factory()->validated()->create([
            'vehicle_contract_id' => $contract->vehicle_contract_id,
            'month' => '2026-10-01',
        ]);

        $this->reopen($contract)->assertStatus(409)->assertJsonPath('code', 'DRIVER_CONTRACT_STATEMENT_VALIDATED');
    }

    public function test_the_utilisateur_cannot_reopen(): void
    {
        $contract = $this->endedContract();

        $this->reopen($contract, ['edit-contracts'])->assertForbidden();
    }

    public function test_the_detail_says_whether_it_can_reopen_and_the_days_to_generate(): void
    {
        $contract = $this->endedContract();

        // Fin le lundi 19 : mardi 20 et mercredi 21 restent à générer.
        $this->as($this->token(['view-contracts']))
            ->getJson("/api/v1/admin/driver-contracts/{$contract->id}")
            ->assertOk()
            ->assertJsonPath('is_reopenable', true)
            ->assertJsonPath('days_to_generate', 2);
    }
}
