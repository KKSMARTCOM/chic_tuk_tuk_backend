<?php

namespace Tests\Feature\Finance;

use App\Domains\Finance\Application\Actions\BuildStatementFigures;
use App\Domains\Finance\Application\RemunerationStatementPdf;
use App\Domains\Finance\Domain\StatementFigures;
use App\Models\VehicleContract;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RemunerationStatementPdfTest extends TestCase
{
    use RefreshDatabase;

    private function figures(): StatementFigures
    {
        Carbon::setTestNow('2026-11-01 09:00:00');
        config(['remuneration.first_month' => '2026-10']);

        return app(BuildStatementFigures::class)(VehicleContract::factory()->create(['start_date' => '2026-10-01']), '2026-10');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_sheet_carries_its_title_month_and_legal_mentions(): void
    {
        $html = app(RemunerationStatementPdf::class)->html($this->figures(), 'FR-2026-10-001', false, Carbon::parse('2026-11-03'));

        $this->assertStringContainsString('FICHE DE RÉMUNÉRATION PROPRIÉTAIRE TUK TUK', $html);
        $this->assertStringContainsString('OCTOBRE 2026', $html);
        $this->assertStringContainsString('FR-2026-10-001', $html);
        $this->assertStringContainsString('Fait à Cotonou, le 03/11/2026', $html);
        $this->assertStringContainsString('IFU 3202598323524', $html);
        $this->assertStringNotContainsString('facture', mb_strtolower($html));
    }

    public function test_a_draft_says_so(): void
    {
        $draft = app(RemunerationStatementPdf::class)->html($this->figures(), null, true, null);

        $this->assertStringContainsString('BROUILLON', $draft);
    }

    public function test_it_renders_a_pdf(): void
    {
        $bytes = app(RemunerationStatementPdf::class)->render($this->figures(), 'FR-2026-10-001', false, Carbon::parse('2026-11-03'));

        $this->assertStringStartsWith('%PDF', $bytes);
    }

    public function test_the_pdf_stays_light_enough_to_mail(): void
    {
        // Sans sous-ensemble, dompdf embarque les trois graisses de Montserrat en entier :
        // plus d'un mégaoctet par fiche, en pièce jointe à chaque propriétaire.
        $bytes = app(RemunerationStatementPdf::class)->render($this->figures(), 'FR-2026-10-001', false, Carbon::parse('2026-11-03'));

        $this->assertLessThan(400_000, strlen($bytes));
    }

    /**
     * Ni cachet, ni signature, ni ligne « Le gérant » (décidé le 2026-10-05) : la fiche
     * s'arrête à « Fait à Cotonou, le … ».
     */
    public function test_the_sheet_is_neither_stamped_nor_signed(): void
    {
        $validated = app(RemunerationStatementPdf::class)->html($this->figures(), 'FR-2026-10-001', false, Carbon::parse('2026-11-03'));

        $this->assertStringContainsString('Fait à Cotonou, le 03/11/2026', $validated);
        $this->assertStringNotContainsString('SPÉCIMEN', $validated);
        $this->assertStringNotContainsString('non signé', $validated);
        $this->assertStringNotContainsString('Le gérant', $validated);
        // Le logo et les deux copies du filigrane, rien d'autre.
        $this->assertSame(3, substr_count($validated, '<img'));
    }

    public function test_the_watermark_strip_runs_the_whole_page(): void
    {
        // L'image fait 243 × 2500 : à 70 px de large, une copie couvre 720 px, et une page A4
        // en mesure 1 123 à 96 ppp. Deux copies empilées, sans étirer les lettres.
        $html = app(RemunerationStatementPdf::class)->html($this->figures(), 'FR-2026-10-001', false, Carbon::parse('2026-11-03'));

        $this->assertSame(2, substr_count($html, 'class="watermark'));
    }

    public function test_the_agency_share_line_appears_when_there_is_one(): void
    {
        $figures = StatementFigures::fromArray(['agency_share' => 140_000] + $this->figures()->toArray());

        $html = app(RemunerationStatementPdf::class)->html($figures, 'FR-2026-10-001', false, Carbon::parse('2026-11-03'));

        $this->assertStringContainsString("Au-delà du revenu total du contrat (revient à l'agence)", $html);
        $this->assertStringContainsString('(140 000)', $html);
    }

    public function test_no_agency_share_line_without_one(): void
    {
        $html = app(RemunerationStatementPdf::class)->html($this->figures(), 'FR-2026-10-001', false, Carbon::parse('2026-11-03'));

        $this->assertStringNotContainsString('Au-delà du revenu total', $html);
    }

    /** Une fiche d'octobre figée : 22 ouvrés, 2 de pause, 1 en instance, 2 recouvrés. */
    private function frozen(array $overrides = []): StatementFigures
    {
        return StatementFigures::fromArray($overrides + [
            'month' => '2026-10', 'owner_name' => 'X', 'vehicle_number' => '2EV2338 RB', 'contract_months' => 24,
            'start_date' => '2026-07-01', 'business_days' => 22, 'pause_days' => 2, 'immobilization_days' => 0,
            'calendar_days' => 20, 'pending_count' => 1, 'recovered_count' => 2, 'counted_days' => 21,
            'daily_amount' => 5_000, 'revenue' => 95_000, 'recovered' => 10_000, 'total_revenue' => 105_000,
            'received_amount' => 105_000, 'balance_due' => 105_000,
        ]);
    }

    /** Le texte des cellules du bloc A, dans l'ordre. */
    private function rows(string $html): array
    {
        preg_match_all('#<tr[^>]*><td[^>]*>(.*?)</td><td[^>]*>(.*?)</td></tr>#s', $html, $m, PREG_SET_ORDER);

        return collect($m)->map(fn ($r) => [trim(strip_tags($r[1])), trim(strip_tags($r[2]))])->all();
    }

    public function test_the_days_block_follows_the_new_order(): void
    {
        $rows = $this->rows(app(RemunerationStatementPdf::class)->html($this->frozen(), 'FR-2026-10-001', false, Carbon::parse('2026-11-03')));
        $labels = array_column($rows, 0);
        $values = array_column($rows, 1, 0);

        $order = ['Jours ouvrés (Total)', 'Jours de pause', 'Jours d\'immobilisation', 'Paiements en instance', 'Paiements recouvrés', 'Jours comptabilisés'];
        $this->assertSame($order, array_values(array_intersect($labels, $order)));
        $this->assertSame('+02', $values['Paiements recouvrés']);
        $this->assertSame('21', $values['Jours comptabilisés']);
        $this->assertSame('105 000', $values['Total recettes (F CFA)']);
        $this->assertNotContains('Paiements recouvrés (F CFA)', $labels);
    }

    public function test_recovered_days_are_hidden_when_zero(): void
    {
        $html = app(RemunerationStatementPdf::class)->html($this->frozen(['recovered_count' => 0]), 'FR-2026-10-001', false, Carbon::parse('2026-11-03'));

        $this->assertNotContains('Paiements recouvrés', array_column($this->rows($html), 0));
    }

    public function test_the_legacy_layout_is_kept_for_an_old_statement_with_a_gap(): void
    {
        $html = app(RemunerationStatementPdf::class)->html(
            $this->frozen(['legacy_layout' => true, 'counted_days' => 20, 'total_revenue' => 90_000, 'revenue' => 90_000]),
            'FR-2026-09-001', false, Carbon::parse('2026-10-03'),
        );
        $rows = $this->rows($html);
        $values = array_column($rows, 1, 0);

        $this->assertSame('(01)', $values['Paiements en instance']);
        $this->assertSame('20', $values['Jours comptabilisés']);
        $this->assertSame('90 000', $values['Total recettes (F CFA)']);
        $this->assertSame('10 000', $values['Paiements recouvrés (F CFA)']);
    }
}
