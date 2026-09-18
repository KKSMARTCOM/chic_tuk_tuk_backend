<?php

namespace Tests\Feature\Workforce;

use App\Domains\Identity\Domain\Enums\Profil;
use App\Models\Driver;
use App\Models\DriverContract;
use App\Models\LeaveRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DriverLeavesApiTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Driver, 1: string} */
    private function loginDriver(bool $avecContrat = true): array
    {
        $user = User::factory()->profil(Profil::Driver)->create([
            'password' => Hash::make('bon-mot-de-passe'),
        ]);
        $driver = Driver::factory()->create(['user_id' => $user->id]);

        if ($avecContrat) {
            DriverContract::factory()->create([
                'driver_id' => $driver->id,
                'start_date' => now()->subMonths(6)->startOfDay(),
                'status' => 'active',
            ]);
        }

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'bon-mot-de-passe',
        ])->json('token');

        return [$driver, $token];
    }

    private function entete(string $token): self
    {
        return $this->withHeader('Authorization', "Bearer {$token}");
    }

    // ----- Gardes ---------------------------------------------------------------

    public function test_sans_jeton_la_reponse_est_un_401_json(): void
    {
        $this->getJson('/api/v1/driver/leaves')
            ->assertStatus(401)
            ->assertJsonPath('code', 'UNAUTHENTICATED');
    }

    public function test_un_jeton_d_un_autre_profil_est_refuse(): void
    {
        $user = User::factory()->profil(Profil::Owner)->create(['password' => Hash::make('bon-mot-de-passe')]);
        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email, 'password' => 'bon-mot-de-passe',
        ])->json('token');

        $this->entete($token)->getJson('/api/v1/driver/leaves')
            ->assertStatus(403)
            ->assertJsonPath('code', 'FORBIDDEN');
    }

    public function test_un_profil_driver_sans_ligne_drivers_donne_409(): void
    {
        $user = User::factory()->profil(Profil::Driver)->create(['password' => Hash::make('bon-mot-de-passe')]);
        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email, 'password' => 'bon-mot-de-passe',
        ])->json('token');

        $this->entete($token)->getJson('/api/v1/driver/leaves')
            ->assertStatus(409)
            ->assertJsonPath('code', 'DRIVER_PROFILE_MISSING');
    }

    // ----- Lecture --------------------------------------------------------------

    public function test_la_lecture_renvoie_le_solde_les_quatre_listes_et_le_droit_de_demander(): void
    {
        [, $token] = $this->loginDriver();

        $this->entete($token)->getJson('/api/v1/driver/leaves')
            ->assertOk()
            ->assertJsonStructure([
                'leave_days_per_month', 'total_leave_days', 'leave_days_used',
                'available_leave_days', 'remaining_leave_days',
                'pending', 'ongoing', 'history', 'rejected', 'can_request',
            ])
            ->assertJsonPath('can_request', true);
    }

    public function test_chaque_demande_est_rangee_dans_sa_liste(): void
    {
        [$driver, $token] = $this->loginDriver();

        foreach (['pending', 'ongoing', 'completed', 'rejected'] as $statut) {
            LeaveRequest::factory()->create(['driver_id' => $driver->id, 'status' => $statut]);
        }

        $reponse = $this->entete($token)->getJson('/api/v1/driver/leaves')->assertOk();

        $this->assertCount(1, $reponse->json('pending'));
        $this->assertNotNull($reponse->json('ongoing'));
        $this->assertCount(1, $reponse->json('history'));
        $this->assertCount(1, $reponse->json('rejected'));
    }

    public function test_les_conges_d_un_autre_agent_ne_fuient_pas(): void
    {
        [, $token] = $this->loginDriver();
        $autre = Driver::factory()->create();
        LeaveRequest::factory()->create(['driver_id' => $autre->id, 'status' => 'pending']);

        $this->entete($token)->getJson('/api/v1/driver/leaves')
            ->assertOk()
            ->assertJsonCount(0, 'pending');
    }

    public function test_une_demande_en_attente_retire_le_droit_d_en_deposer_une_autre(): void
    {
        [$driver, $token] = $this->loginDriver();
        LeaveRequest::factory()->create(['driver_id' => $driver->id, 'status' => 'pending']);

        $this->entete($token)->getJson('/api/v1/driver/leaves')
            ->assertOk()
            ->assertJsonPath('can_request', false);
    }

    public function test_la_fin_prevue_ignore_les_week_ends(): void
    {
        // `addBusinessDays` : un vendredi + 3 jours ouvrés tombe le mardi suivant.
        [$driver, $token] = $this->loginDriver();
        $vendredi = now()->addWeek()->next(\Carbon\CarbonInterface::FRIDAY);
        LeaveRequest::factory()->create([
            'driver_id' => $driver->id,
            'status' => 'pending',
            'start_date' => $vendredi,
            'requested_days' => 3,
        ]);

        $fin = $this->entete($token)->getJson('/api/v1/driver/leaves')
            ->json('pending.0.expected_end_date');

        $this->assertSame($vendredi->copy()->addDays(4)->format('Y-m-d'), $fin);
    }

    // ----- Écriture -------------------------------------------------------------

    public function test_une_demande_valide_est_creee_en_201(): void
    {
        [$driver, $token] = $this->loginDriver();

        $this->entete($token)->postJson('/api/v1/driver/leaves', [
            'start_date' => now()->addDays(3)->toDateString(),
            'requested_days' => 2,
        ])
            ->assertStatus(201)
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('requested_days', 2);

        $this->assertSame(1, LeaveRequest::where('driver_id', $driver->id)->count());
    }

    public function test_sans_contrat_actif_le_refus_porte_son_code(): void
    {
        [, $token] = $this->loginDriver(avecContrat: false);

        $this->entete($token)->postJson('/api/v1/driver/leaves', [
            'start_date' => now()->addDays(3)->toDateString(),
            'requested_days' => 2,
        ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'LEAVE_NO_ACTIVE_CONTRACT');
    }

    public function test_une_date_anterieure_au_contrat_porte_son_code(): void
    {
        $user = User::factory()->profil(Profil::Driver)->create(['password' => Hash::make('bon-mot-de-passe')]);
        $driver = Driver::factory()->create(['user_id' => $user->id]);
        DriverContract::factory()->create([
            'driver_id' => $driver->id,
            'start_date' => now()->addMonth()->startOfDay(),
            'status' => 'active',
        ]);
        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email, 'password' => 'bon-mot-de-passe',
        ])->json('token');

        $this->entete($token)->postJson('/api/v1/driver/leaves', [
            'start_date' => now()->addDays(3)->toDateString(),
            'requested_days' => 2,
        ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'LEAVE_BEFORE_CONTRACT_START');
    }

    public function test_une_demande_deja_en_attente_porte_son_code(): void
    {
        [$driver, $token] = $this->loginDriver();
        LeaveRequest::factory()->create(['driver_id' => $driver->id, 'status' => 'pending']);

        $this->entete($token)->postJson('/api/v1/driver/leaves', [
            'start_date' => now()->addDays(3)->toDateString(),
            'requested_days' => 2,
        ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'LEAVE_ALREADY_PENDING');
    }

    public function test_une_date_a_moins_de_24_heures_est_refusee_en_422(): void
    {
        [, $token] = $this->loginDriver();

        $this->entete($token)->postJson('/api/v1/driver/leaves', [
            'start_date' => now()->toDateString(),
            'requested_days' => 2,
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_FAILED')
            ->assertJsonStructure(['errors' => ['start_date']]);
    }

    public function test_zero_jour_est_refuse_en_422(): void
    {
        [, $token] = $this->loginDriver();

        $this->entete($token)->postJson('/api/v1/driver/leaves', [
            'start_date' => now()->addDays(3)->toDateString(),
            'requested_days' => 0,
        ])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['requested_days']]);
    }

    public function test_aucun_refus_ne_ressort_en_500(): void
    {
        // Le point de tout le sous-lot : ces refus étaient des flash de session, donc
        // invisibles pour une API. Sans conversion, ils donneraient tous un 500.
        [$driver, $token] = $this->loginDriver();
        LeaveRequest::factory()->create(['driver_id' => $driver->id, 'status' => 'ongoing']);

        $this->entete($token)->postJson('/api/v1/driver/leaves', [
            'start_date' => now()->addDays(3)->toDateString(),
            'requested_days' => 2,
        ])->assertStatus(409);
    }
}
