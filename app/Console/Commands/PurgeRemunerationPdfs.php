<?php

namespace App\Console\Commands;

use App\Models\RemunerationStatement;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Les PDF des fiches vivent un an sur le serveur, depuis leur génération (spec 2026-10-01,
 * §7.2). La fiche — chiffres figés, numéro — reste en base ; l'admin peut régénérer le PDF.
 */
class PurgeRemunerationPdfs extends Command
{
    protected $signature = 'app:purge-remuneration-pdfs';

    protected $description = 'Efface les PDF de fiches de rémunération générés depuis plus d\'un an';

    public function handle(): int
    {
        $limit = now()->subDays((int) config('remuneration.pdf_retention_days'));
        $count = 0;

        RemunerationStatement::query()
            ->whereNotNull('pdf_path')
            ->where('pdf_generated_at', '<=', $limit)
            ->each(function (RemunerationStatement $statement) use (&$count) {
                Storage::disk('local')->delete($statement->pdf_path);
                $statement->update(['pdf_path' => null, 'pdf_purged_at' => now()]);
                $count++;
            });

        $this->info("{$count} PDF effacé(s).");

        return self::SUCCESS;
    }
}
