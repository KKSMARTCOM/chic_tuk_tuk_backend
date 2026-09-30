<?php

namespace Tests\Feature\Finance;

use App\Domains\Finance\Domain\RemunerationBranding;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Le cachet et la signature vivent HORS du dépôt (spec §7) : leur absence doit se voir,
 * jamais produire une fiche signée par défaut.
 */
class RemunerationBrandingTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = storage_path('framework/testing/branding');
        File::ensureDirectoryExists($this->dir);
        File::cleanDirectory($this->dir);
        config(['remuneration.branding_dir' => $this->dir]);
    }

    public function test_branding_is_incomplete_without_the_stamp_or_the_signature(): void
    {
        $this->assertFalse(RemunerationBranding::isComplete());

        File::put($this->dir.'/cachet.png', 'x');
        $this->assertFalse(RemunerationBranding::isComplete());
        $this->assertNull(RemunerationBranding::signaturePath());
    }

    public function test_branding_is_complete_with_both_images(): void
    {
        File::put($this->dir.'/cachet.png', 'x');
        File::put($this->dir.'/signature.png', 'x');

        $this->assertTrue(RemunerationBranding::isComplete());
        $this->assertSame($this->dir.'/cachet.png', RemunerationBranding::stampPath());
    }

    public function test_the_check_command_says_what_is_missing(): void
    {
        $this->artisan('app:check-remuneration-branding')
            ->expectsOutputToContain('cachet.png : absent')
            ->expectsOutputToContain('signature.png : absent')
            ->assertFailed();

        File::put($this->dir.'/cachet.png', 'x');
        File::put($this->dir.'/signature.png', 'x');

        $this->artisan('app:check-remuneration-branding')->assertSuccessful();
    }
}
