<?php

namespace Tests\Feature\Finance;

use App\Domains\Finance\Application\Actions\CancelRemunerationStatement;
use App\Domains\Finance\Application\Actions\ValidateRemunerationStatement;
use App\Domains\Identity\Domain\Enums\Profil;
use App\Models\Notification;
use App\Models\Payment;
use App\Models\RemunerationStatement;
use App\Models\User;
use App\Models\VehicleContract;
use App\Shared\Http\ApiException;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Kreait\Firebase\Contract\Messaging;
use Mockery;
use Tests\TestCase;

class ContractTargetsReachedTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-02 09:00:00');
        config(['remuneration.first_month' => '2026-07', 'remuneration.owner_visible' => false]);
        // `Notifier` est final : on regarde ce qui arrive en base, comme `NotificationHooksTest`.
        $this->app->instance(Messaging::class, Mockery::mock(Messaging::class)->shouldIgnoreMissing());
        $this->admin = User::factory()->profil(Profil::Admin)->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** 2 mois, revenu total 200 000 : juillet 150 000, août 100 000 → les deux atteints en août. */
    private function contract(): VehicleContract
    {
        $contract = VehicleContract::factory()->create([
            'contract_months' => 2, 'total_amount' => 200_000, 'start_date' => '2026-07-01',
            'unlimited_internet' => 5_000, 'spotify_premium' => 2_500, 'manager_remuneration' => 20_000,
        ]);
        Payment::factory()->onDay('2026-07-01')->status('completed')->create(['vehicle_contract_id' => $contract->id, 'net_amount' => 150_000]);
        Payment::factory()->onDay('2026-08-03')->status('completed')->create(['vehicle_contract_id' => $contract->id, 'net_amount' => 100_000]);

        return $contract;
    }

    /** @return list<string> les messages « cumul atteint » reçus par l'admin */
    private function targetMessages(): array
    {
        return Notification::where('user_id', $this->admin->id)->whereIn('title', ['Cumul du contrat atteint', 'Contrat à solder'])
            ->orderBy('created_at')->orderBy('id')->pluck('message')->all();
    }

    private function validate(VehicleContract $contract, string $month): RemunerationStatement
    {
        $draft = RemunerationStatement::factory()->for($contract, 'contract')->create(['month' => $month.'-01']);

        return app(ValidateRemunerationStatement::class)($draft, User::factory()->create(), Carbon::today(), false);
    }

    public function test_both_targets_reached_on_the_same_sheet(): void
    {
        $contract = $this->contract();
        $this->validate($contract, '2026-07');
        $this->assertNull($contract->refresh()->revenue_reached_month);

        $this->validate($contract, '2026-08');

        $contract->refresh();
        $this->assertSame('2026-08-01', $contract->revenue_reached_month->toDateString());
        $this->assertSame('2026-08-01', $contract->charges_reached_month->toDateString());
        $this->assertTrue($contract->isReadyToSettle());
        // Chaque cumul a son message, puis « vous pouvez solder » (relecture finale).
        $messages = $this->targetMessages();
        $this->assertCount(3, $messages);
        $this->assertStringContainsString('le revenu total du contrat est atteint (fiche de août 2026)', $messages[0]);
        $this->assertStringContainsString('le total des charges du contrat est atteint (fiche de août 2026)', $messages[1]);
        $this->assertStringContainsString('les deux cumuls sont atteints : vous pouvez solder le contrat', $messages[2]);
        $this->assertDatabaseHas('activity_log', ['event' => 'vehicle.contract_target_reached', 'subject_id' => $contract->id]);
    }

    public function test_cancelling_the_sheet_clears_the_target_and_revalidating_warns_again(): void
    {
        $contract = $this->contract();
        $this->validate($contract, '2026-07');
        $august = $this->validate($contract, '2026-08');

        $replacement = app(CancelRemunerationStatement::class)($august, User::factory()->create(), 'erreur de saisie');

        $this->assertNull($contract->refresh()->revenue_reached_month);
        $this->assertNull($contract->charges_reached_month);

        app(ValidateRemunerationStatement::class)($replacement, User::factory()->create(), Carbon::today(), false);

        $this->assertNotNull($contract->refresh()->revenue_reached_month);
        $this->assertCount(6, $this->targetMessages());
    }

    public function test_the_sheet_that_ended_a_settled_contract_cannot_be_cancelled(): void
    {
        $contract = $this->contract();
        $this->validate($contract, '2026-07');
        $august = $this->validate($contract, '2026-08');
        $contract->refresh()->update(['status' => 'completed', 'end_date' => '2026-08-31']);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Cette fiche a soldé le contrat');

        app(CancelRemunerationStatement::class)($august, User::factory()->create(), 'erreur');
    }

    public function test_a_sheet_after_the_end_of_a_settled_contract_is_not_validated(): void
    {
        $contract = $this->contract();
        $contract->update(['status' => 'completed', 'end_date' => '2026-07-31']);
        $august = RemunerationStatement::factory()->for($contract, 'contract')->create(['month' => '2026-08-01']);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('après la fin du contrat');

        app(ValidateRemunerationStatement::class)($august, User::factory()->create(), Carbon::today(), false);
    }
}
