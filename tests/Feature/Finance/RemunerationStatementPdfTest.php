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
}
