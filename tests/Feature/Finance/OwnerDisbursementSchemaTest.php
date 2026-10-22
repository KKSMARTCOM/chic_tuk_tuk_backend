<?php

namespace Tests\Feature\Finance;

use App\Domains\Fleet\Application\Actions\DeleteVehicleContract;
use App\Models\OwnerDisbursement;
use App\Models\VehicleContract;
use App\Shared\Http\ApiException;
use Database\Seeders\ReferenceRolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Spec 2026-10-22-versement-proprietaire, §3 et §4.6. */
class OwnerDisbursementSchemaTest extends TestCase
{
    use RefreshDatabase;

    private function disbursement(VehicleContract $contract, array $attributes = []): OwnerDisbursement
    {
        return OwnerDisbursement::create($attributes + [
            'vehicle_contract_id' => $contract->id, 'paid_on' => '2026-10-20', 'amount' => 50_000,
            'method' => 'mobile_money', 'status' => 'recorded',
        ]);
    }

    public function test_a_disbursement_belongs_to_its_contract(): void
    {
        $contract = VehicleContract::factory()->create();
        $disbursement = $this->disbursement($contract);

        $this->assertTrue($disbursement->contract->is($contract));
        $this->assertTrue($contract->disbursements()->first()->is($disbursement));
        $this->assertSame('2026-10-20', $disbursement->fresh()->paid_on->toDateString());
    }

    public function test_the_database_refuses_an_unknown_method(): void
    {
        $this->expectException(QueryException::class);
        $this->disbursement(VehicleContract::factory()->create(), ['method' => 'cash']);
    }

    public function test_the_database_refuses_a_zero_amount(): void
    {
        $this->expectException(QueryException::class);
        $this->disbursement(VehicleContract::factory()->create(), ['amount' => 0]);
    }

    public function test_a_contract_with_a_disbursement_cannot_be_deleted(): void
    {
        $contract = VehicleContract::factory()->create(['status' => 'cancelled']);
        $this->disbursement($contract, ['status' => 'cancelled', 'cancelled_at' => now(), 'cancellation_reason' => 'Erreur']);

        try {
            app(DeleteVehicleContract::class)($contract);
            $this->fail('La suppression aurait dû être refusée.');
        } catch (ApiException $e) {
            $this->assertSame('VEHICLE_CONTRACT_NOT_DELETABLE', $e->errorCode);
        }
    }

    public function test_the_user_role_carries_the_three_disbursement_permissions(): void
    {
        $this->seed(ReferenceRolesAndPermissionsSeeder::class);

        foreach (['utilisateur', 'admin'] as $role) {
            $this->assertTrue(Role::findByName($role, 'web')->hasAllPermissions(['view-disbursements', 'create-disbursements', 'edit-disbursements']), $role);
        }
    }
}
