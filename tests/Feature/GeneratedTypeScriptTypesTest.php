<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Les types TypeScript des fronts sont générés depuis les classes `Data` — et ce test
 * refuse qu'ils prennent du retard.
 *
 * Avant eux, chaque réponse de l'API était décrite deux fois, en PHP et en TypeScript
 * écrit à la main, et rien ne signalait l'écart quand l'une changeait sans l'autre : c'est
 * le front qui cassait, en production. Ici, modifier une classe `Data` sans relancer
 * `php artisan typescript:transform` fait échouer la suite.
 *
 * Le fichier régénéré est ensuite copié dans le dépôt `client` par `npm run types:sync`.
 */
class GeneratedTypeScriptTypesTest extends TestCase
{
    public function test_le_fichier_de_types_commite_est_a_jour(): void
    {
        $committed = resource_path('types/generated.d.ts');
        $fresh = tempnam(sys_get_temp_dir(), 'types').'.d.ts';

        config(['typescript-transformer.output_file' => $fresh]);
        Artisan::call('typescript:transform');

        $this->assertFileExists($committed);
        $this->assertSame(
            file_get_contents($fresh),
            file_get_contents($committed),
            'resources/types/generated.d.ts est périmé : lancer `php artisan typescript:transform`, '
                .'puis `npm run types:sync` dans le dépôt client.',
        );

        @unlink($fresh);
    }
}
