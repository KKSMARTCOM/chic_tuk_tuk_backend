<?php

namespace Tests\Feature\Finance;

use App\Domains\Finance\Application\Actions\CancelPayment;
use App\Domains\Finance\Application\Actions\UpdateRemunerationStatement;
use App\Domains\Finance\Application\Actions\ValidateRemunerationStatement;
use App\Domains\Finance\Application\Data\UpdateRemunerationStatementData;
use App\Domains\Finance\Application\Jobs\IssueRemunerationStatement;
use App\Models\Payment;
use App\Models\RemunerationStatement;
use App\Models\User;
use App\Models\VehicleContract;
use App\Shared\Http\ApiException;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ValidateRemunerationStatementTest extends TestCase
{
    use RefreshDatabase;

    private VehicleContract $contract;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-02 09:00:00');
        $dir = storage_path('framework/testing/branding');
        config(['remuneration.first_month' => '2026-10', 'remuneration.branding_dir' => $dir]);
        File::ensureDirectoryExists($dir);
        File::put($dir.'/cachet.png', 'x');
        File::put($dir.'/signature.png', 'x');
        Queue::fake();
        $this->contract = VehicleContract::factory()->create(['start_date' => '2026-10-01']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function draft(string $month = '2026-10-01'): RemunerationStatement
    {
        return RemunerationStatement::factory()->for($this->contract, 'contract')->create(['month' => $month]);
    }

    private function validate(RemunerationStatement $statement): RemunerationStatement
    {
        return app(ValidateRemunerationStatement::class)($statement, User::factory()->create());
    }

    private function update(RemunerationStatement $statement, array $values): RemunerationStatement
    {
        return app(UpdateRemunerationStatement::class)($statement, UpdateRemunerationStatementData::from($values));
    }

    /** Le code métier d'une ApiException levée par `$call`. */
    private function refusalCode(callable $call): ?string
    {
        try {
            $call();
        } catch (ApiException $e) {
            return $e->errorCode;
        }

        return null;
    }

    public function test_validation_freezes_numbers_and_attaches_payments(): void
    {
        $payment = Payment::factory()->onDay('2026-10-01')->create(['vehicle_contract_id' => $this->contract->id, 'net_amount' => 100_000]);

        $statement = $this->validate($this->draft());

        $this->assertSame('validated', $statement->status);
        $this->assertSame('FR-2026-10-001', $statement->number);
        $this->assertEquals(100_000, $statement->figures['revenue']);
        $this->assertEquals(100_000 - 27_500, $statement->balance_due);
        $this->assertSame($statement->id, $payment->fresh()->remuneration_statement_id);
        Queue::assertPushed(IssueRemunerationStatement::class);
    }

    public function test_numbers_follow_each_other_within_a_month(): void
    {
        $this->validate($this->draft());
        $other = RemunerationStatement::factory()
            ->for(VehicleContract::factory()->create(['start_date' => '2026-10-01']), 'contract')
            ->create(['month' => '2026-10-01']);

        $this->assertSame('FR-2026-10-002', $this->validate($other)->number);
    }

    public function test_the_previous_month_must_be_validated_first(): void
    {
        $this->draft('2026-10-01');
        $november = $this->draft('2026-11-01');

        $this->assertSame('STATEMENT_PREVIOUS_NOT_VALIDATED', $this->refusalCode(fn () => $this->validate($november)));
    }

    public function test_validation_is_refused_without_the_stamp_and_signature(): void
    {
        File::delete(config('remuneration.branding_dir').'/signature.png');

        $this->assertSame('STATEMENT_BRANDING_MISSING', $this->refusalCode(fn () => $this->validate($this->draft())));
    }

    public function test_a_validated_statement_cannot_be_edited(): void
    {
        $statement = $this->validate($this->draft());

        $this->assertSame('STATEMENT_NOT_DRAFT', $this->refusalCode(fn () => $this->update($statement, ['note' => 'x'])));
    }

    public function test_deducting_beyond_what_is_outstanding_is_a_field_error(): void
    {
        Payment::factory()->onDay('2026-10-01')->create(['vehicle_contract_id' => $this->contract->id, 'net_amount' => 100_000]);

        try {
            $this->update($this->draft(), ['deducted_internet' => 9_000]);
            $this->fail('Le prélèvement excessif devait être refusé.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('deducted_internet', $e->errors());
        }
    }

    public function test_the_opening_balance_is_only_for_the_first_statement(): void
    {
        $this->validate($this->draft('2026-10-01'));

        $this->expectException(ValidationException::class);
        $this->update($this->draft('2026-11-01'), ['opening_manager' => 20_000]);
    }

    public function test_an_attached_payment_can_no_longer_be_cancelled(): void
    {
        $payment = Payment::factory()->onDay('2026-10-01')->create(['vehicle_contract_id' => $this->contract->id]);
        $this->validate($this->draft());

        $this->assertSame('PAYMENT_IN_VALIDATED_STATEMENT', $this->refusalCode(fn () => app(CancelPayment::class)($payment->fresh())));
    }

    public function test_a_month_not_over_cannot_be_validated(): void
    {
        // Le 2 novembre : novembre n'est pas fini, ses chiffres ne sont que partiels.
        $november = $this->draft('2026-11-01');

        $this->assertSame('STATEMENT_MONTH_NOT_OVER', $this->refusalCode(fn () => $this->validate($november)));
        $this->assertContains(
            'Le mois n\'est pas terminé : ses chiffres ne sont que partiels.',
            app(ValidateRemunerationStatement::class)->blockers($november, app(\App\Domains\Finance\Application\Actions\BuildStatementFigures::class)($this->contract, '2026-11', $november)),
        );
    }

    public function test_a_reconstituted_statement_takes_its_issue_date_and_is_not_sent(): void
    {
        $contract = VehicleContract::factory()->create(['start_date' => '2026-03-02']);
        $draft = RemunerationStatement::factory()->for($contract, 'contract')->create(['month' => '2026-03-01']);

        $validated = app(ValidateRemunerationStatement::class)($draft, User::factory()->create(), Carbon::parse('2026-04-03'), send: false);

        $this->assertSame(['2026-04-03', 'none'], [$validated->issued_on->toDateString(), $validated->delivery]);
        $this->assertSame('FR-2026-03-001', $validated->number);
    }

    public function test_an_ordinary_validation_is_issued_today_and_sent(): void
    {
        $validated = app(ValidateRemunerationStatement::class)($this->draft(), User::factory()->create());

        $this->assertSame(['2026-11-02', 'email'], [$validated->issued_on->toDateString(), $validated->delivery]);
    }

    public function test_issue_date_rules(): void
    {
        $contract = VehicleContract::factory()->create(['start_date' => '2026-03-02']);
        $april = RemunerationStatement::factory()->for($contract, 'contract')->create(['month' => '2026-04-01']);
        $validate = app(ValidateRemunerationStatement::class);

        // Après la fin du mois, et pas dans le futur.
        $this->assertArrayHasKey('issued_on', $validate->issueDateViolations($april, Carbon::parse('2026-04-30')));
        $this->assertArrayHasKey('issued_on', $validate->issueDateViolations($april, Carbon::parse('2026-11-03')));
        $this->assertSame([], $validate->issueDateViolations($april, Carbon::parse('2026-05-04')));

        // Jamais avant la fiche validée du mois précédent : avril arrêté tard, le 10/06.
        $april->update(['status' => 'validated', 'number' => 'FR-2026-04-001', 'issued_on' => '2026-06-10', 'figures' => []]);
        $may = RemunerationStatement::factory()->for($contract, 'contract')->create(['month' => '2026-05-01']);
        $this->assertSame(
            ['issued_on' => 'La date d\'établissement ne peut pas précéder celle de la fiche du mois précédent (10/06/2026).'],
            $validate->issueDateViolations($may, Carbon::parse('2026-06-01')),
        );
        $this->assertSame([], $validate->issueDateViolations($may, Carbon::parse('2026-06-10')));
    }

    public function test_a_wrong_issue_date_refuses_the_validation(): void
    {
        $this->expectException(ValidationException::class);
        app(ValidateRemunerationStatement::class)($this->draft(), User::factory()->create(), Carbon::parse('2026-10-15'));
    }

    public function test_the_draft_saves_its_issue_date(): void
    {
        $draft = $this->draft();

        app(UpdateRemunerationStatement::class)($draft, new UpdateRemunerationStatementData(issuedOn: '2026-11-01'));
        $this->assertSame('2026-11-01', $draft->refresh()->issued_on->toDateString());

        $this->expectException(ValidationException::class);
        app(UpdateRemunerationStatement::class)($draft, new UpdateRemunerationStatementData(issuedOn: '2026-10-20'));
    }

    public function test_a_number_is_never_reused_after_a_purge(): void
    {
        $contract = VehicleContract::factory()->create(['start_date' => '2026-03-02']);
        $first = RemunerationStatement::factory()->for($contract, 'contract')->create(['month' => '2026-03-01']);
        app(ValidateRemunerationStatement::class)($first, User::factory()->create(), Carbon::parse('2026-04-03'), send: false);
        RemunerationStatement::whereKey($first->id)->delete();

        $second = RemunerationStatement::factory()->for($contract, 'contract')->create(['month' => '2026-03-01']);
        $this->assertSame('FR-2026-03-002', app(ValidateRemunerationStatement::class)($second, User::factory()->create(), Carbon::parse('2026-04-03'), send: false)->number);
    }

    public function test_validating_a_statement_locks_its_vehicle_contract(): void
    {
        $locked = false;
        \Illuminate\Support\Facades\DB::listen(function ($query) use (&$locked) {
            if (str_contains($query->sql, '"vehicle_contracts"') && str_contains(strtolower($query->sql), 'for update')) {
                $locked = true;
            }
        });

        app(ValidateRemunerationStatement::class)($this->draft(), User::factory()->create());

        $this->assertTrue($locked);
    }

    public function test_the_draft_issue_date_can_be_cleared_and_is_kept_when_absent(): void
    {
        $draft = $this->draft();
        $update = app(UpdateRemunerationStatement::class);

        $update($draft, UpdateRemunerationStatementData::from(['issued_on' => '2026-11-01']));
        $update($draft->refresh(), UpdateRemunerationStatementData::from(['note' => 'relue']));
        $this->assertSame('2026-11-01', $draft->refresh()->issued_on->toDateString());

        $update($draft, UpdateRemunerationStatementData::from(['issued_on' => null]));
        $this->assertNull($draft->refresh()->issued_on);
    }

    public function test_an_issue_date_that_became_invalid_blocks_before_the_click(): void
    {
        $contract = VehicleContract::factory()->create(['start_date' => '2026-09-01']);
        $september = RemunerationStatement::factory()->for($contract, 'contract')->create(['month' => '2026-09-01', 'issued_on' => '2026-10-05']);
        // La fiche d'août, revalidée après coup, est arrêtée plus tard que la date saisie.
        RemunerationStatement::factory()->for($contract, 'contract')->validated()->create(['month' => '2026-08-01', 'issued_on' => '2026-10-20']);

        $validate = app(ValidateRemunerationStatement::class);
        $figures = app(\App\Domains\Finance\Application\Actions\BuildStatementFigures::class)($contract, '2026-09', $september);

        $this->assertContains('La date d\'établissement ne peut pas précéder celle de la fiche du mois précédent (20/10/2026).', $validate->blockers($september, $figures));
    }

    /**
     * Interrupteur éteint (spec 2026-10-02, §3.2) : la fiche partirait vers un propriétaire
     * qui ne peut pas la voir. La validation sans envoi, geste de la reconstitution, passe.
     */
    public function test_switched_off_only_validation_without_sending_passes(): void
    {
        config(['remuneration.owner_visible' => false]);
        $statement = $this->draft();
        $by = User::factory()->create();

        $this->assertSame('STATEMENTS_HIDDEN_FROM_OWNERS', $this->refusalCode(
            fn () => app(ValidateRemunerationStatement::class)($statement, $by, null, true),
        ));
        $this->assertSame('draft', $statement->refresh()->status);

        $validated = app(ValidateRemunerationStatement::class)($statement, $by, null, false);
        $this->assertSame('validated', $validated->status);
        $this->assertSame('none', $validated->delivery);
    }
}
