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

    /**
     * Deux traductions imprécises que le transformateur produit sans prévenir :
     *
     * - `any`, qui désarme le typecheck du front : un tableau sans `@var` sur sa
     *   PROPRIÉTÉ — un `@param` de constructeur ne suffit pas —, ou un `mixed` ;
     * - `{ [key: number]: … }`, un objet indexé et non un tableau, sans `.length` : ce que
     *   donne `array<int, string>`. Écrire `string[]`.
     */
    public function test_aucun_champ_ne_sort_imprecis(): void
    {
        $content = file_get_contents(resource_path('types/generated.d.ts'));

        preg_match_all('/^(\w+): .*(\bany\b|\[key: number\]).*$/m', $content, $matches);

        $this->assertSame([], $matches[0], 'Champs sans type précis : voir le docblock de ce test.');
    }
}
