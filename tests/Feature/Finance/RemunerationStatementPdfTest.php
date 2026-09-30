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

    public function test_a_draft_says_so_and_a_missing_signature_makes_a_specimen(): void
    {
        config(['remuneration.branding_dir' => storage_path('framework/testing/no-branding')]);
        $draft = app(RemunerationStatementPdf::class)->html($this->figures(), null, true, null);
        $validated = app(RemunerationStatementPdf::class)->html($this->figures(), 'FR-2026-10-001', false, Carbon::parse('2026-11-03'));

        $this->assertStringContainsString('BROUILLON', $draft);
        $this->assertStringContainsString('SPÉCIMEN — non signé', $validated);
    }

    public function test_it_renders_a_pdf(): void
    {
        $bytes = app(RemunerationStatementPdf::class)->render($this->figures(), 'FR-2026-10-001', false, Carbon::parse('2026-11-03'));

        $this->assertStringStartsWith('%PDF', $bytes);
    }

    public function test_the_pdf_stays_light_enough_to_mail(): void
    {
        // Sans sous-ensemble, dompdf embarque les trois graisses de Montserrat en entier :
        // plus d'un mégaoctet par fiche, en pièce jointe à chaque propriétaire. Mesuré sans
        // cachet ni signature, qui ne sont pas sur tous les postes.
        config(['remuneration.branding_dir' => storage_path('framework/testing/no-branding')]);
        $bytes = app(RemunerationStatementPdf::class)->render($this->figures(), 'FR-2026-10-001', false, Carbon::parse('2026-11-03'));

        $this->assertLessThan(400_000, strlen($bytes));
    }

    public function test_a_draft_never_carries_the_stamp_nor_the_signature(): void
    {
        // Un aperçu téléchargé serait sinon un document signé au nom de la société, sur des
        // chiffres que la relecture peut encore changer.
        $dir = storage_path('framework/testing/branding');
        \Illuminate\Support\Facades\File::ensureDirectoryExists($dir);
        \Illuminate\Support\Facades\File::put($dir.'/cachet.png', 'cachet');
        \Illuminate\Support\Facades\File::put($dir.'/signature.png', 'signature');
        config(['remuneration.branding_dir' => $dir]);

        $draft = app(RemunerationStatementPdf::class)->html($this->figures(), null, true, null);
        $validated = app(RemunerationStatementPdf::class)->html($this->figures(), 'FR-2026-10-001', false, Carbon::parse('2026-11-03'));

        $this->assertStringNotContainsString(base64_encode('cachet'), $draft);
        $this->assertStringNotContainsString(base64_encode('signature'), $draft);
        $this->assertStringContainsString('BROUILLON — non signé', $draft);
        $this->assertStringContainsString(base64_encode('cachet'), $validated);
    }

    public function test_the_watermark_strip_runs_the_whole_page(): void
    {
        // L'image fait 243 × 2500 : à 70 px de large, une copie couvre 720 px, et une page A4
        // en mesure 1 123 à 96 ppp. Deux copies empilées, sans étirer les lettres.
        $html = app(RemunerationStatementPdf::class)->html($this->figures(), 'FR-2026-10-001', false, Carbon::parse('2026-11-03'));

        $this->assertSame(2, substr_count($html, 'class="watermark'));
    }
}
