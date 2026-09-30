<?php

namespace App\Console\Commands;

use App\Domains\Finance\Domain\RemunerationBranding;
use Illuminate\Console\Command;

/** Dit si le cachet et la signature sont en place — à lancer après chaque dépôt sur un serveur. */
class CheckRemunerationBranding extends Command
{
    protected $signature = 'app:check-remuneration-branding';

    protected $description = 'Vérifie la présence du cachet et de la signature des fiches de rémunération';

    public function handle(): int
    {
        $this->line('cachet.png : '.(RemunerationBranding::stampPath() ? 'présent' : 'absent'));
        $this->line('signature.png : '.(RemunerationBranding::signaturePath() ? 'présent' : 'absent'));

        return RemunerationBranding::isComplete() ? self::SUCCESS : self::FAILURE;
    }
}
