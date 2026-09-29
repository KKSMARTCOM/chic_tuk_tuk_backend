<?php

namespace Tests\Feature\Api;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Aucune route ne sert ni ne reçoit les fichiers privés du disque `local`.
 *
 * Laravel 12 ouvre `GET` et `PUT storage/{path}` dès que le disque porte
 * `'serve' => true` — une option que Laravel 11 ignorait, et que la configuration du
 * projet portait déjà. Vu à la montée du 2026-09-29 : une route de plus, qu'aucun code
 * n'avait demandée. Ce test empêche qu'elle revienne sans décision.
 */
class NoStorageRouteTest extends TestCase
{
    public function test_le_disque_local_n_est_expose_par_aucune_route(): void
    {
        $this->assertFalse(Route::has('storage.local'));
        $this->assertFalse(Route::has('storage.local.upload'));
    }
}
