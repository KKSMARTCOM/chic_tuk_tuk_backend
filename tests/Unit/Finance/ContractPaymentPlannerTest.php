<?php

namespace Tests\Unit\Finance;

use App\Domains\Finance\Domain\ContractPaymentPlanner as P;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

/** Le classement des jours d'une période pour un contrat agent (spec 2026-10-01, §3.2). */
class ContractPaymentPlannerTest extends TestCase
{
    private function pause(string $start, ?string $end, string $reason): object
    {
        return (object) ['start_date' => Carbon::parse($start), 'end_date' => $end ? Carbon::parse($end) : null, 'reason_type' => $reason];
    }

    private function payment(string $date, string $status, string $id = 'p1'): object
    {
        return (object) ['id' => $id, 'payment_date' => Carbon::parse($date), 'status' => $status];
    }

    private function classes(string $from, string $to, array $pauses = [], array $payments = [], string $start = '2026-03-01', ?string $end = null, string $today = '2026-10-01'): array
    {
        $plan = P::plan(Carbon::parse($from), Carbon::parse($to), Carbon::parse($start), $end ? Carbon::parse($end) : null, Carbon::parse($today), $pauses, $payments);

        return collect($plan->days)->mapWithKeys(fn ($d) => [$d->date => $d->class])->all();
    }

    public function test_business_days_are_to_generate_and_weekends_are_not(): void
    {
        $c = $this->classes('2026-03-06', '2026-03-09');
        $this->assertSame([
            '2026-03-06' => P::TO_GENERATE, '2026-03-07' => P::WEEKEND,
            '2026-03-08' => P::WEEKEND, '2026-03-09' => P::TO_GENERATE,
        ], $c);
    }

    public function test_days_outside_the_contract_or_after_today_are_out_of_contract(): void
    {
        $c = $this->classes('2026-03-02', '2026-03-06', start: '2026-03-04', end: '2026-03-05');
        $this->assertSame(P::OUT_OF_CONTRACT, $c['2026-03-03']);
        $this->assertSame(P::TO_GENERATE, $c['2026-03-04']);
        $this->assertSame(P::OUT_OF_CONTRACT, $c['2026-03-06']);

        $c = $this->classes('2026-09-30', '2026-10-02', today: '2026-10-01');
        $this->assertSame(P::TO_GENERATE, $c['2026-10-01']);
        $this->assertSame(P::OUT_OF_CONTRACT, $c['2026-10-02']);
    }

    public function test_an_agent_pause_wins_over_an_immobilization(): void
    {
        $c = $this->classes('2026-03-09', '2026-03-11', [
            $this->pause('2026-03-09', '2026-03-10', 'technical'),
            $this->pause('2026-03-10', '2026-03-10', 'agent_leave'),
        ]);
        $this->assertSame([P::IMMOBILIZED, P::AGENT_PAUSE, P::TO_GENERATE], array_values($c));
    }

    public function test_an_open_pause_covers_every_later_day(): void
    {
        $c = $this->classes('2026-03-09', '2026-03-13', [$this->pause('2026-03-11', null, 'agent_change')]);
        $this->assertSame([P::TO_GENERATE, P::TO_GENERATE, P::IMMOBILIZED, P::IMMOBILIZED, P::IMMOBILIZED], array_values($c));
    }

    public function test_live_payments_make_a_day_paid_and_only_cancelled_ones_make_it_cancelled(): void
    {
        $plan = P::plan(Carbon::parse('2026-03-09'), Carbon::parse('2026-03-11'), Carbon::parse('2026-03-01'), null, Carbon::parse('2026-10-01'), [], [
            $this->payment('2026-03-09', 'pending', 'a'),
            $this->payment('2026-03-10', 'cancelled', 'b'),
            $this->payment('2026-03-11', 'cancelled', 'c'),
            $this->payment('2026-03-11', 'completed', 'd'),
        ]);
        $days = collect($plan->days)->keyBy('date');

        $this->assertSame([P::PAID, 'a'], [$days['2026-03-09']->class, $days['2026-03-09']->paymentId]);
        $this->assertSame([P::CANCELLED, 'b'], [$days['2026-03-10']->class, $days['2026-03-10']->paymentId]);
        $this->assertSame([P::PAID, 'd'], [$days['2026-03-11']->class, $days['2026-03-11']->paymentId]);
        $this->assertSame(['2026-03-10'], $plan->cancelled());
        $this->assertSame([], $plan->toGenerate());
    }

    public function test_a_stopped_day_stays_stopped_even_with_a_payment(): void
    {
        // Le paiement d'un jour d'arrêt est une ANOMALIE de la fiche, pas un jour payé.
        $c = $this->classes('2026-03-09', '2026-03-09', [$this->pause('2026-03-09', '2026-03-09', 'technical')], [$this->payment('2026-03-09', 'completed')]);
        $this->assertSame(P::IMMOBILIZED, $c['2026-03-09']);
    }

    public function test_counts(): void
    {
        $plan = P::plan(Carbon::parse('2026-03-06'), Carbon::parse('2026-03-09'), Carbon::parse('2026-03-01'), null, Carbon::parse('2026-10-01'), [], []);
        $this->assertSame(2, $plan->counts()[P::TO_GENERATE]);
        $this->assertSame(2, $plan->counts()[P::WEEKEND]);
        $this->assertSame(0, $plan->counts()[P::PAID]);
    }
}
