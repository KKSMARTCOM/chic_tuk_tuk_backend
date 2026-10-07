<?php

namespace Tests\Feature\Finance;

use App\Domains\Finance\Application\Jobs\IssueRemunerationStatement;
use App\Domains\Identity\Domain\Enums\Profil;
use App\Models\Payment;
use App\Models\RemunerationStatement;
use App\Models\User;
use App\Models\VehicleContract;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * L'API d'administration des fiches de rémunération (spec 2026-09-30, §5.1 et §5.2).
 *
 * Trois permissions : consulter, préparer (générer, ajuster), valider — cette dernière
 * réservée à l'administrateur.
 */
class AdminRemunerationStatementsApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-02 09:00:00');
        config(['remuneration.first_month' => '2026-10']);
        Queue::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

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

    public function test_listing_needs_the_view_permission(): void
    {
        $this->asBearer($this->login([]))->getJson('/api/v1/admin/remuneration-statements')->assertForbidden();
        $this->asBearer($this->login(['view-remuneration-statements']))->getJson('/api/v1/admin/remuneration-statements')->assertOk();
    }

    public function test_the_list_filters_by_month_and_computes_draft_balances(): void
    {
        $contract = VehicleContract::factory()->create(['start_date' => '2026-10-01']);
        Payment::factory()->onDay('2026-10-01')->create(['vehicle_contract_id' => $contract->id, 'net_amount' => 100_000]);
        RemunerationStatement::factory()->for($contract, 'contract')->create(['month' => '2026-10-01']);
        RemunerationStatement::factory()->create(['month' => '2026-11-01']);

        $this->asBearer($this->login(['view-remuneration-statements']))
            ->getJson('/api/v1/admin/remuneration-statements?filter[month]=2026-10')
            ->assertOk()
            ->assertJsonCount(1, 'statements')
            ->assertJsonPath('statements.0.balance_due', 72_500)
            ->assertJsonPath('statements.0.status_label', 'Brouillon');
    }

    public function test_editing_needs_edit_and_validating_needs_validate(): void
    {
        $statement = RemunerationStatement::factory()
            ->for(VehicleContract::factory()->create(['start_date' => '2026-10-01']), 'contract')
            ->create(['month' => '2026-10-01']);
        $editor = $this->login(['view-remuneration-statements', 'edit-remuneration-statements']);

        $this->asBearer($editor)->patchJson("/api/v1/admin/remuneration-statements/{$statement->id}", ['note' => 'Vu'])
            ->assertOk()->assertJsonPath('note', 'Vu');
        $this->asBearer($editor)->postJson("/api/v1/admin/remuneration-statements/{$statement->id}/validate")
            ->assertForbidden();

        $this->asBearer($this->login(['view-remuneration-statements', 'validate-remuneration-statements']))
            ->postJson("/api/v1/admin/remuneration-statements/{$statement->id}/validate")
            ->assertOk()
            ->assertJsonPath('status', 'validated');
    }

    public function test_business_refusals_are_409_with_their_code(): void
    {
        $contract = VehicleContract::factory()->create(['start_date' => '2026-10-01']);
        RemunerationStatement::factory()->for($contract, 'contract')->create(['month' => '2026-10-01']);
        $november = RemunerationStatement::factory()->for($contract, 'contract')->create(['month' => '2026-11-01']);

        $this->asBearer($this->login(['validate-remuneration-statements']))
            ->postJson("/api/v1/admin/remuneration-statements/{$november->id}/validate")
            ->assertStatus(409)
            ->assertJsonPath('code', 'STATEMENT_PREVIOUS_NOT_VALIDATED');
    }

    public function test_the_detail_announces_what_blocks_validation(): void
    {
        $contract = VehicleContract::factory()->create(['start_date' => '2026-10-01']);
        RemunerationStatement::factory()->for($contract, 'contract')->create(['month' => '2026-10-01']);
        $november = RemunerationStatement::factory()->for($contract, 'contract')->create(['month' => '2026-11-01']);

        $this->asBearer($this->login(['view-remuneration-statements']))
            ->getJson("/api/v1/admin/remuneration-statements/{$november->id}")
            ->assertOk()
            ->assertJsonPath('can_validate', false)
            ->assertJsonPath('blocking.0', 'La fiche du mois précédent doit être validée d\'abord.');
    }

    /**
     * Les fiches voisines du même contrat, pour passer d'un mois à l'autre sans revenir à la
     * liste (2026-10-07) : par mois, sans les annulées, sans les autres contrats — sauf la
     * fiche ouverte elle-même, annulée ou non.
     */
    public function test_the_detail_lists_the_other_statements_of_the_same_contract(): void
    {
        $contract = VehicleContract::factory()->create(['start_date' => '2026-09-01']);
        $november = RemunerationStatement::factory()->for($contract, 'contract')->create(['month' => '2026-11-01']);
        $september = RemunerationStatement::factory()->for($contract, 'contract')->create(['month' => '2026-09-01']);
        $cancelled = RemunerationStatement::factory()->for($contract, 'contract')
            ->create(['month' => '2026-10-01', 'status' => 'cancelled', 'cancelled_at' => now(), 'cancel_reason' => 'Erreur']);
        $october = RemunerationStatement::factory()->for($contract, 'contract')->create(['month' => '2026-10-01', 'replaces_id' => $cancelled->id]);
        RemunerationStatement::factory()->create(['month' => '2026-10-01']);
        $token = $this->login(['view-remuneration-statements']);

        $siblings = $this->asBearer($token)->getJson("/api/v1/admin/remuneration-statements/{$november->id}")
            ->assertOk()->json('siblings');
        $this->assertSame([$september->id, $october->id, $november->id], array_column($siblings, 'id'));
        $this->assertSame(['2026-09', '2026-10', '2026-11'], array_column($siblings, 'month'));
        $this->assertSame(['id', 'month', 'number', 'status', 'status_label'], array_keys($siblings[0]));

        $this->assertSame([$september->id, $cancelled->id, $october->id, $november->id], array_column(
            $this->asBearer($token)->getJson("/api/v1/admin/remuneration-statements/{$cancelled->id}")->json('siblings'), 'id'));
    }

    public function test_generate_then_cancel(): void
    {
        VehicleContract::factory()->create(['start_date' => '2026-10-01']);
        $token = $this->login(['edit-remuneration-statements', 'validate-remuneration-statements', 'cancel-remuneration-statements']);

        $this->asBearer($token)->postJson('/api/v1/admin/remuneration-statements/generate', ['month' => '2026-10'])
            ->assertOk()->assertJsonPath('created', 1);
        // Générée à la main : l'auteur est l'administrateur, pas « Système ».
        $this->assertNotSame('Système', Activity::query()->where('event', 'remuneration_statement.generated')->sole()->properties['actor']);

        $statement = RemunerationStatement::first();
        $this->asBearer($token)->postJson("/api/v1/admin/remuneration-statements/{$statement->id}/validate")->assertOk();

        $this->asBearer($token)->postJson("/api/v1/admin/remuneration-statements/{$statement->id}/cancel", ['reason' => ''])
            ->assertUnprocessable();
        $this->asBearer($token)->postJson("/api/v1/admin/remuneration-statements/{$statement->id}/cancel", ['reason' => 'Erreur'])
            ->assertOk()->assertJsonPath('status', 'draft');
    }

    public function test_the_pdf_preview_of_a_draft(): void
    {
        $statement = RemunerationStatement::factory()
            ->for(VehicleContract::factory()->create(['start_date' => '2026-10-01']), 'contract')
            ->create(['month' => '2026-10-01']);

        $response = $this->asBearer($this->login(['view-remuneration-statements']))
            ->get("/api/v1/admin/remuneration-statements/{$statement->id}/pdf")
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    // ----- Envoyer, ou renvoyer, une fiche validée (2026-10-02) ----------------------------

    public function test_a_statement_validated_without_sending_can_be_sent(): void
    {
        $statement = RemunerationStatement::factory()->validated()->create(['delivery' => 'none', 'pdf_path' => 'statements/x.pdf']);

        $this->asBearer($this->login(['validate-remuneration-statements']))
            ->postJson("/api/v1/admin/remuneration-statements/{$statement->id}/send")
            ->assertOk()
            ->assertJsonPath('id', $statement->id)
            ->assertJsonPath('delivery', 'email')
            ->assertJsonPath('owner_email', $statement->contract->vehicle?->owner?->email);

        $this->assertSame('email', $statement->refresh()->delivery);
        Queue::assertPushed(IssueRemunerationStatement::class, fn ($job) => $job->statementId === $statement->id);
        $this->assertTrue(Activity::query()->where('event', 'remuneration_statement.send_requested')->exists());
    }

    /** Un propriétaire qui redemande sa fiche : `sent_at` s'efface, sinon la tâche ne repartirait pas. */
    public function test_a_sent_statement_can_be_sent_again(): void
    {
        $statement = RemunerationStatement::factory()->validated()->create(['sent_at' => now()->subMonth(), 'pdf_path' => 'statements/x.pdf']);

        $this->asBearer($this->login(['validate-remuneration-statements']))
            ->postJson("/api/v1/admin/remuneration-statements/{$statement->id}/send")
            ->assertOk();

        $this->assertNull($statement->refresh()->sent_at);
        Queue::assertPushed(IssueRemunerationStatement::class);
    }

    public function test_sending_needs_the_validate_permission(): void
    {
        $statement = RemunerationStatement::factory()->validated()->create();

        $this->asBearer($this->login(['edit-remuneration-statements']))
            ->postJson("/api/v1/admin/remuneration-statements/{$statement->id}/send")
            ->assertForbidden();
        Queue::assertNothingPushed();
    }

    public function test_only_a_validated_statement_with_its_pdf_is_sent(): void
    {
        $token = $this->login(['validate-remuneration-statements']);
        $draft = RemunerationStatement::factory()->create();
        $expired = RemunerationStatement::factory()->validated()->create(['pdf_path' => null, 'pdf_purged_at' => now()]);

        $this->asBearer($token)->postJson("/api/v1/admin/remuneration-statements/{$draft->id}/send")
            ->assertStatus(409)->assertJsonPath('code', 'STATEMENT_NOT_VALIDATED');
        $this->asBearer($token)->postJson("/api/v1/admin/remuneration-statements/{$expired->id}/send")
            ->assertStatus(409)->assertJsonPath('code', 'STATEMENT_PDF_EXPIRED');
        Queue::assertNothingPushed();
    }

    /** Valider ne suffit plus pour annuler : l'annulation est à l'administrateur seul (2026-10-02). */
    public function test_cancelling_needs_its_own_permission(): void
    {
        $statement = RemunerationStatement::factory()->validated()->create();

        $this->asBearer($this->login(['validate-remuneration-statements']))
            ->postJson("/api/v1/admin/remuneration-statements/{$statement->id}/cancel", ['reason' => 'Erreur'])
            ->assertForbidden();
        $this->assertSame('validated', $statement->refresh()->status);
    }

    public function test_switched_off_a_statement_is_not_sent(): void
    {
        config(['remuneration.owner_visible' => false]);
        $statement = RemunerationStatement::factory()->validated()->create(['delivery' => 'none', 'pdf_path' => 'statements/x.pdf']);

        $this->asBearer($this->login(['validate-remuneration-statements']))
            ->postJson("/api/v1/admin/remuneration-statements/{$statement->id}/send")
            ->assertStatus(409)
            ->assertJsonPath('code', 'STATEMENTS_HIDDEN_FROM_OWNERS');

        $this->assertSame('none', $statement->refresh()->delivery);
        Queue::assertNothingPushed();
    }
}
