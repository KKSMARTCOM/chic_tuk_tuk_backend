<?php

namespace App\Console\Commands;

use App\Domains\Finance\Application\Actions\GenerateRemunerationStatements;
use Carbon\Carbon;
use Illuminate\Console\Command;

class GenerateRemunerationStatementsCommand extends Command
{
    protected $signature = 'app:generate-remuneration-statements {--month= : le mois, AAAA-MM (par défaut le mois écoulé)}';

    protected $description = 'Crée les brouillons des fiches de rémunération d\'un mois';

    public function handle(GenerateRemunerationStatements $generate): int
    {
        $month = $this->option('month') ?: Carbon::today()->subMonthNoOverflow()->format('Y-m');
        $this->info($generate($month)." brouillon(s) créé(s) pour {$month}.");

        return self::SUCCESS;
    }
}
