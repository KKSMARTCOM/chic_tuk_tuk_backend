<?php

namespace Tests\Feature\Finance;

use App\Models\Payment;
use App\Models\RemunerationStatement;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Les colonnes de la reconstitution des fiches (spec 2026-10-01, §4.2 et §7). */
class ReconstitutionSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_new_columns_exist(): void
    {
        $this->assertTrue(Schema::hasColumn('payments', 'collected_on'));
        foreach (['issued_on', 'delivery', 'pdf_generated_at', 'pdf_purged_at', 'owner_download_count'] as $column) {
            $this->assertTrue(Schema::hasColumn('remuneration_statements', $column), $column);
        }
        $this->assertTrue(Schema::hasTable('remuneration_statement_numbers'));
    }

    public function test_defaults_and_casts(): void
    {
        $statement = RemunerationStatement::factory()->create()->refresh();
        $this->assertSame('email', $statement->delivery);
        $this->assertSame(0, $statement->owner_download_count);

        $payment = Payment::factory()->create(['collected_on' => '2026-03-31'])->refresh();
        $this->assertSame('2026-03-31', $payment->collected_on->toDateString());
    }

    public function test_the_pdf_state(): void
    {
        $s = RemunerationStatement::factory()->validated()->make(['pdf_path' => null]);
        $this->assertSame('preparing', $s->pdfState());
        $s->pdf_path = 'statements/2026/x.pdf';
        $this->assertSame('ready', $s->pdfState());
        $s->pdf_path = null;
        $s->pdf_purged_at = now();
        $this->assertSame('expired', $s->pdfState());
    }

    public function test_downloads_left_never_goes_negative(): void
    {
        config(['remuneration.owner_download_limit' => 3]);
        $s = RemunerationStatement::factory()->make(['owner_download_count' => 5]);
        $this->assertSame(0, $s->ownerDownloadsLeft());
        $s->owner_download_count = 1;
        $this->assertSame(2, $s->ownerDownloadsLeft());
    }

    public function test_the_number_counter_table_has_month_as_key(): void
    {
        DB::table('remuneration_statement_numbers')->insert(['month' => '2026-03-01', 'last_rank' => 4]);
        $this->expectException(QueryException::class);
        DB::table('remuneration_statement_numbers')->insert(['month' => '2026-03-01', 'last_rank' => 5]);
    }
}
