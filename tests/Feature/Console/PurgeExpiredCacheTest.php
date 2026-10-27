<?php

namespace Tests\Feature\Console;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Relecture finale de la recherche de lieux (2026-10-27) : le cache `database` n'efface
 * une ligne expirée que si on la relit, et chaque saisie de la recherche en crée une.
 */
class PurgeExpiredCacheTest extends TestCase
{
    use RefreshDatabase;

    public function test_expired_cache_rows_are_deleted_and_live_ones_kept(): void
    {
        DB::table('cache')->insert([
            ['key' => 'places.photon.vieux', 'value' => 'x', 'expiration' => now()->subMinute()->timestamp],
            ['key' => 'places.photon.frais', 'value' => 'x', 'expiration' => now()->addHour()->timestamp],
        ]);

        $this->artisan('app:purge-expired-cache')->assertSuccessful();

        $this->assertSame(['places.photon.frais'], DB::table('cache')->pluck('key')->all());
    }

    public function test_it_runs_every_night(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('app:purge-expired-cache')->assertSuccessful();
    }
}
