<?php

namespace Tests\Feature\Finance;

use App\Domains\Identity\Domain\Enums\Profil;
use App\Models\Booking;
use App\Models\Commission;
use App\Models\Driver;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Les commissions vues de l'administration — ex-Admin\CommissionController (P1).
 *
 * ⚠️ Décidé le 2026-09-26 : une commission s'ANNULE, elle ne s'efface plus. Le Blade la
 * supprimait définitivement, alors qu'elle est ce que l'agent doit ; l'annulation fait
 * baisser le dû de la même façon et garde la trace.
 */
class AdminCommissionsApiTest extends TestCase
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

    private function commission(Driver $driver, float $amount, string $status = 'active'): Commission
    {
        $booking = Booking::factory()->completed($driver)->create();

        return Commission::create([
            'driver_id' => $driver->id,
            'booking_id' => $booking->id,
            'amount' => $amount,
            'date' => '2026-09-20',
            'status' => $status,
        ]);
    }

    public function test_reads_require_view_commissions(): void
    {
        $commission = $this->commission(Driver::factory()->create(), 750);
        $token = $this->login(['view-payments']);

        $this->asBearer($token)->getJson('/api/v1/admin/commissions')->assertForbidden();
        $this->asBearer($token)->getJson("/api/v1/admin/commissions/{$commission->id}")->assertForbidden();
    }

    public function test_the_list_counts_only_active_commissions_in_the_revenue(): void
    {
        $driver = Driver::factory()->create();
        $active = $this->commission($driver, 750);
        $this->commission($driver, 500, 'cancelled');

        $response = $this->asBearer($this->login(['view-commissions']))
            ->getJson('/api/v1/admin/commissions')
            ->assertOk()
            ->assertJsonPath('stats.total_revenue', 750)
            ->assertJsonPath('stats.total_count', 2)
            ->assertJsonCount(2, 'commissions');

        $row = collect($response->json('commissions'))->firstWhere('id', $active->id);
        $this->assertSame('active', $row['status']);
        $this->assertSame($driver->id, $row['driver']['id']);
        $this->assertSame($active->booking->booking_number, $row['booking']['booking_number']);
    }

    public function test_search_does_not_escape_the_driver_filter(): void
    {
        // Défaut corrigé : le `orWhereHas` de la recherche n'était pas groupé, et un
        // numéro de course trouvé ailleurs passait outre le filtre d'agent.
        $mine = Driver::factory()->create();
        $other = Driver::factory()->create();
        $this->commission($mine, 750);
        $foreign = $this->commission($other, 500);

        $this->asBearer($this->login(['view-commissions']))
            ->getJson('/api/v1/admin/commissions?'.http_build_query([
                'driver_id' => $mine->id,
                'search' => $foreign->booking->booking_number,
            ]))
            ->assertOk()
            ->assertJsonCount(0, 'commissions');
    }

    public function test_the_detail_shows_the_driver_earning_of_the_booking(): void
    {
        $commission = $this->commission(Driver::factory()->create(), 750);

        $this->asBearer($this->login(['view-commissions']))
            ->getJson("/api/v1/admin/commissions/{$commission->id}")
            ->assertOk()
            ->assertJsonPath('id', $commission->id)
            ->assertJsonPath('booking.driver_earning', 4250);
    }

    public function test_a_commission_is_cancelled_not_deleted(): void
    {
        $commission = $this->commission(Driver::factory()->create(), 750);

        $this->asBearer($this->login(['delete-commissions']))
            ->postJson("/api/v1/admin/commissions/{$commission->id}/cancel")
            ->assertOk()
            ->assertJsonPath('status', 'cancelled');

        $this->assertDatabaseHas('commissions', ['id' => $commission->id, 'status' => 'cancelled']);
    }

    public function test_cancelling_requires_delete_commissions_and_happens_once(): void
    {
        $commission = $this->commission(Driver::factory()->create(), 750);

        $this->asBearer($this->login(['view-commissions']))
            ->postJson("/api/v1/admin/commissions/{$commission->id}/cancel")
            ->assertForbidden();

        $commission->update(['status' => 'cancelled']);
        $this->asBearer($this->login(['delete-commissions']))
            ->postJson("/api/v1/admin/commissions/{$commission->id}/cancel")
            ->assertStatus(409)
            ->assertJsonPath('code', 'COMMISSION_ALREADY_CANCELLED');
    }

    public function test_the_driver_file_counts_active_commissions_and_completed_payments(): void
    {
        // Défaut corrigé : le dossier agent additionnait toutes les commissions et tous
        // les paiements de commission, annulés compris.
        $driver = Driver::factory()->create();
        $this->commission($driver, 1000);
        $this->commission($driver, 400, 'cancelled');
        Payment::factory()->create(['driver_id' => $driver->id, 'payment_type' => 'commission', 'amount' => 300, 'net_amount' => 300]);
        Payment::factory()->status('cancelled')->create(['driver_id' => $driver->id, 'payment_type' => 'commission', 'amount' => 200, 'net_amount' => 200]);

        $this->asBearer($this->login(['view-drivers']))
            ->getJson("/api/v1/admin/drivers/{$driver->id}")
            ->assertOk()
            ->assertJsonPath('commission_stats.paid_revenue', 300)
            ->assertJsonPath('commission_stats.unpaid_revenue', 700);
    }
}
