<?php

namespace Tests\Feature\Fleet;

use App\Domains\Identity\Domain\Enums\Profil;
use App\Models\Payment;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleContract;
use App\Models\VehiclePause;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class OwnerSettledContractTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    private Vehicle $vehicle;

    /** Comme `OwnerVehicleShowTest::loginOwner()`. */
    private function actingAsOwner(): self
    {
        return $this->withHeader('Authorization', "Bearer {$this->token}");
    }

    protected function setUp(): void
    {
        parent::setUp();
        $owner = User::factory()->profil(Profil::Owner)->create(['password' => Hash::make('bon-mot-de-passe')]);
        foreach (['view-own-contracts', 'view-own-payments', 'view-own-leaves'] as $permission) {
            Permission::findOrCreate($permission, 'web');
            $owner->givePermissionTo($permission);
        }
        $this->token = $this->postJson('/api/v1/auth/login', ['email' => $owner->email, 'password' => 'bon-mot-de-passe'])->json('token');
        $this->vehicle = Vehicle::factory()->create(['owner_id' => $owner->id]);
        $contract = VehicleContract::factory()->forVehicle($this->vehicle)->create([
            'status' => 'completed', 'start_date' => '2026-01-01', 'end_date' => '2026-08-31',
        ]);
        foreach (['2026-08-14', '2026-09-14'] as $day) {
            Payment::factory()->onDay($day)->status('completed')->create(['vehicle_contract_id' => $contract->id, 'net_amount' => 5_000]);
        }
        VehiclePause::factory()->forContract($contract)->create(['start_date' => '2026-09-21', 'end_date' => null]);
    }

    public function test_payments_after_the_end_are_not_the_owner_s(): void
    {
        $response = $this->actingAsOwner()->getJson("/api/v1/owner/vehicles/{$this->vehicle->id}/payment-list")->assertOk();

        $this->assertSame(['2026-08-14'], collect($response->json('payments'))->pluck('payment_date')->all());
        $this->assertEquals(5_000, $response->json('totals.paid'));
    }

    public function test_the_vehicle_shows_completed_without_its_pause(): void
    {
        $this->actingAsOwner()->getJson("/api/v1/owner/vehicles/{$this->vehicle->id}")
            ->assertOk()
            ->assertJsonPath('state', 'completed')
            ->assertJsonPath('active_pause', null)
            ->assertJsonMissingPath('contract.months_remaining');
    }

    public function test_pauses_after_the_end_are_hidden(): void
    {
        $items = $this->actingAsOwner()->getJson("/api/v1/owner/vehicles/{$this->vehicle->id}/pauses")->assertOk()->json('items');

        $this->assertSame([], collect($items)->where('start_date', '2026-09-21')->values()->all());
    }

    public function test_the_monthly_recap_stops_at_the_end_without_disappearing(): void
    {
        // Relecture finale : le récapitulatif « s'arrête à la date de fin » (spec §5), il ne disparaît pas.
        $months = collect($this->actingAsOwner()->getJson("/api/v1/owner/vehicles/{$this->vehicle->id}/payments")->assertOk()->json());

        $this->assertNotEmpty($months);
        $this->assertNotContains('2026-09', $months->pluck('month')->all());
    }
}
