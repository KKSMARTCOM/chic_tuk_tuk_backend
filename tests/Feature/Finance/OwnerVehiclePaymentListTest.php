<?php

namespace Tests\Feature\Finance;

use App\Domains\Identity\Domain\Enums\Profil;
use App\Models\Driver;
use App\Models\Payment;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * `GET /owner/vehicles/{id}/payment-list` (2026-10-07) : les paiements d'un véhicule un par
 * un, pour son propriétaire. Le NET seulement, payés et en attente seulement, et ni agent
 * ni versement brut : ce qui revient au propriétaire, rien de plus.
 */
class OwnerVehiclePaymentListTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private string $token;

    private Vehicle $vehicle;

    private VehicleContract $contract;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->profil(Profil::Owner)->create(['password' => Hash::make('bon-mot-de-passe')]);
        Permission::findOrCreate('view-own-payments', 'web');
        $this->owner->givePermissionTo('view-own-payments');
        $this->token = $this->postJson('/api/v1/auth/login', ['email' => $this->owner->email, 'password' => 'bon-mot-de-passe'])->json('token');

        $this->vehicle = Vehicle::factory()->create(['owner_id' => $this->owner->id]);
        $this->contract = VehicleContract::factory()->forVehicle($this->vehicle)->create(['owner_id' => $this->owner->id]);
    }

    private function pay(string $date, string $status = 'completed', array $attributes = []): Payment
    {
        return Payment::factory()->create([
            'driver_id' => Driver::factory(),
            'payment_type' => 'contract',
            'vehicle_contract_id' => $this->contract->id,
            'driver_contract_id' => null,
            'payment_date' => $date,
            'payment_month' => substr($date, 0, 7).'-01',
            'amount' => 6112,
            'net_amount' => 5871,
            'status' => $status,
            ...$attributes,
        ]);
    }

    private function list(string $query = '')
    {
        return $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson("/api/v1/owner/vehicles/{$this->vehicle->id}/payment-list{$query}");
    }

    public function test_le_vehicule_d_un_autre_proprietaire_renvoie_404(): void
    {
        $other = Vehicle::factory()->create();

        // La 200 d'abord : une route absente répondrait 404 comme un refus de portée.
        $this->list()->assertOk();
        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson("/api/v1/owner/vehicles/{$other->id}/payment-list")
            ->assertStatus(404);
    }

    public function test_seuls_les_paiements_de_contrat_payes_ou_en_attente_de_ses_contrats(): void
    {
        $paid = $this->pay('2026-09-01');
        $pending = $this->pay('2026-09-02', 'pending');
        $this->pay('2026-09-03', 'cancelled');
        $this->pay('2026-09-04', 'completed', ['payment_type' => 'commission']);

        // Le contrat d'un ANCIEN propriétaire du même véhicule : son historique ne se montre pas.
        $former = VehicleContract::factory()->forVehicle($this->vehicle)->create(['owner_id' => User::factory()->profil(Profil::Owner), 'status' => 'completed']);
        $this->pay('2026-08-01', 'completed', ['vehicle_contract_id' => $former->id]);

        $this->list()->assertOk()
            ->assertJsonCount(2, 'payments')
            ->assertJsonPath('payments.0.id', $pending->id)
            ->assertJsonPath('payments.1.id', $paid->id)
            ->assertJsonPath('pagination.total', 2);
    }

    public function test_une_ligne_ne_porte_que_la_date_le_net_et_le_statut(): void
    {
        $this->pay('2026-09-01');

        $row = $this->list()->assertOk()->json('payments.0');

        $this->assertSame(['id', 'payment_date', 'net_amount', 'status'], array_keys($row));
        $this->assertSame('2026-09-01', $row['payment_date']);
        $this->assertEquals(5871, $row['net_amount']);
        $this->assertSame('completed', $row['status']);
    }

    public function test_filtres_mois_statut_et_periode_et_totaux_sur_tout_le_filtre(): void
    {
        $this->pay('2026-08-31');
        $this->pay('2026-09-01');
        $this->pay('2026-09-02', 'pending');
        $this->pay('2026-09-15');
        $this->pay('2026-10-01');

        $this->list('?filter[month]=2026-09')->assertOk()
            ->assertJsonCount(3, 'payments')
            ->assertJsonPath('totals.paid', 2 * 5871)
            ->assertJsonPath('totals.pending', 5871);

        $this->list('?filter[status]=pending')->assertOk()
            ->assertJsonCount(1, 'payments')
            ->assertJsonPath('payments.0.payment_date', '2026-09-02');

        // Bornes incluses.
        $this->list('?filter[from]=2026-09-01&filter[to]=2026-09-15')->assertOk()
            ->assertJsonCount(3, 'payments');

        // Les totaux portent sur tout le filtre, pas sur la page.
        $this->list('?per_page=1')->assertOk()
            ->assertJsonCount(1, 'payments')
            ->assertJsonPath('pagination.total', 5)
            ->assertJsonPath('totals.paid', 4 * 5871);
    }

    public function test_un_filtre_mal_forme_est_ignore_et_un_filtre_inconnu_refuse(): void
    {
        $this->pay('2026-09-01');

        $this->list('?filter[month]=septembre&filter[from]=hier')->assertOk()->assertJsonCount(1, 'payments');
        $this->list('?filter[driver_id]=x')->assertStatus(400)->assertJsonPath('code', 'INVALID_LIST_QUERY');
    }

    public function test_sans_la_permission_c_est_403(): void
    {
        $this->owner->revokePermissionTo('view-own-payments');

        $this->list()->assertStatus(403);
    }
}
