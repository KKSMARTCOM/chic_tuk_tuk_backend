<?php

namespace Tests\Feature\Audit;

use App\Domains\Audit\Application\ActivityJournal;
use App\Domains\Identity\Domain\Enums\Profil;
use App\Models\Driver;
use App\Models\DriverContract;
use App\Models\LeaveRequest;
use App\Models\Role;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Le journal d'activité, lot 3 : les agents, leurs contrats et leurs pauses, les comptes
 * administrateurs et les rôles (2026-09-29).
 *
 * ⚠️ Un compte AGENT a pour objet l'agent et non son compte utilisateur : sa fiche est
 * adressée par l'agent (`/admin/drivers/{driver}`).
 */
class ActivityJournalWorkforceTest extends TestCase
{
    use RefreshDatabase;

    private function token(array $permissions): string
    {
        $user = User::factory()->profil(Profil::Admin)->create(['name' => 'Awa Dossou', 'password' => Hash::make('bon-mot-de-passe')]);
        foreach ($permissions as $permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        Auth::forgetGuards();

        return $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'bon-mot-de-passe'])->json('token');
    }

    private function as(string $token): self
    {
        Auth::forgetGuards();

        return $this->withHeader('Authorization', "Bearer {$token}");
    }

    private function last(string $event): Activity
    {
        return Activity::query()->where('event', $event)->latest('id')->firstOrFail();
    }

    /** Un agent sous contrat, avec son véhicule : la pause véhicule en dépend. */
    private function agent(): Driver
    {
        $user = User::factory()->profil(Profil::Driver)->create(['name' => 'Koffi Mensah']);
        $driver = Driver::factory()->create(['user_id' => $user->id, 'is_available' => true]);
        $vehicle = Vehicle::factory()->create();
        $vehicleContract = VehicleContract::factory()->create(['vehicle_id' => $vehicle->id]);

        DriverContract::factory()->create([
            'driver_id' => $driver->id,
            'vehicle_id' => $vehicle->id,
            'vehicle_contract_id' => $vehicleContract->id,
            'start_date' => now()->subMonths(6)->startOfDay(),
            'contract_months' => 24,
            'status' => 'active',
        ]);

        return $driver->refresh();
    }

    private function request(Driver $driver): LeaveRequest
    {
        return LeaveRequest::factory()->pending()->create([
            'driver_id' => $driver->id,
            'driver_contract_id' => $driver->activeDriverContract->id,
            'start_date' => '2026-10-05',
            'requested_days' => 3,
        ]);
    }

    public function test_valider_une_pause_dit_l_agent_et_la_periode(): void
    {
        $request = $this->request($this->agent());

        $this->as($this->token(['view-leave-requests', 'approve-leave-requests']))
            ->postJson("/api/v1/admin/leave-requests/{$request->id}/approve")
            ->assertOk();

        $entry = $this->last('leave.approved');
        $this->assertSame('a validé la pause de Koffi Mensah (3 jours à partir du 05/10/2026)', $entry->description);
        $this->assertSame('leave_request', $entry->subject_type);
    }

    public function test_refuser_une_pause_garde_le_motif(): void
    {
        $request = $this->request($this->agent());

        $this->as($this->token(['view-leave-requests', 'reject-leave-requests']))
            ->postJson("/api/v1/admin/leave-requests/{$request->id}/reject", ['rejection_reason' => 'Période chargée'])
            ->assertOk();

        $this->assertStringEndsWith(': « Période chargée »', $this->last('leave.rejected')->description);
    }

    public function test_desactiver_un_agent_a_l_agent_pour_objet(): void
    {
        $driver = $this->agent();

        $this->as($this->token(['edit-drivers']))
            ->postJson("/api/v1/admin/drivers/{$driver->id}/toggle-status", ['is_active' => false])
            ->assertOk();

        $entry = $this->last('account.status_changed');
        $this->assertSame('a désactivé le compte agent de Koffi Mensah', $entry->description);
        // L'agent, pas son compte : c'est lui qui a une fiche.
        $this->assertSame('driver', $entry->subject_type);
        $this->assertSame($driver->id, $entry->subject_id);
    }

    public function test_la_disponibilite_d_un_agent_est_tracee(): void
    {
        $driver = $this->agent();

        $this->as($this->token(['edit-drivers']))
            ->postJson("/api/v1/admin/drivers/{$driver->id}/toggle-availability", ['is_available' => false])
            ->assertOk();

        $this->assertSame('a rendu indisponible Koffi Mensah', $this->last('driver.availability_changed')->description);
    }

    public function test_un_role_est_trace_par_son_libelle(): void
    {
        $role = Role::create(['name' => 'chef-dequipe', 'label' => 'Chef d’équipe', 'guard_name' => 'web']);

        app(ActivityJournal::class)->roleCreated($role);

        $entry = $this->last('account.role_created');
        $this->assertSame('a créé le rôle « Chef d’équipe »', $entry->description);
        $this->assertSame('role', $entry->subject_type);
    }

    public function test_les_pauses_demarrees_par_la_tache_planifiee_font_une_seule_ligne(): void
    {
        foreach ([$this->agent(), $this->agent()] as $driver) {
            LeaveRequest::factory()->create([
                'driver_id' => $driver->id,
                'driver_contract_id' => $driver->activeDriverContract->id,
                'status' => 'ongoing',
                'start_date' => now()->subDay()->toDateString(),
                'requested_days' => 3,
                'effective_days' => null,
            ]);
        }

        $this->artisan('app:activate-leave-pauses')->assertSuccessful();

        $entries = Activity::query()->where('event', 'leave.started')->get();
        $this->assertCount(1, $entries);
        $this->assertSame('a démarré 2 pauses d\'agents arrivées à leur date', $entries->first()->description);
        $this->assertSame('Système', $entries->first()->properties['actor']);
    }
}
