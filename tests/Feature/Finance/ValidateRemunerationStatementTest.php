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
}
