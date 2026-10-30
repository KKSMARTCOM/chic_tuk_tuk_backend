<?php

namespace Tests\Feature\Content;

use App\Domains\Identity\Domain\Enums\Profil;
use App\Models\GlossaryTerm;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/** Spec 2026-10-30-glossaire-proprietaire. */
class OwnerGlossaryApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        GlossaryTerm::query()->delete();
    }

    private function as(Profil $profil = Profil::Owner): self
    {
        $user = User::factory()->profil($profil)->create(['password' => Hash::make('bon-mot-de-passe')]);
        $user->givePermissionTo(Permission::firstOrCreate(['name' => 'view-own-contracts', 'guard_name' => 'web']));
        $token = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'bon-mot-de-passe'])->json('token');
        Auth::forgetGuards();

        return $this->withHeader('Authorization', "Bearer {$token}");
    }

    public function test_the_owner_reads_visible_terms_alphabetically_rendered_never_the_markdown(): void
    {
        GlossaryTerm::create(['term' => 'Total recettes', 'definition' => '**T**', 'formula' => 'a × b', 'is_visible' => true]);
        GlossaryTerm::create(['term' => 'Échéance', 'definition' => 'E', 'is_visible' => true]);
        GlossaryTerm::create(['term' => 'Masqué', 'definition' => 'M', 'is_visible' => false]);

        $response = $this->as()->getJson('/api/v1/owner/glossary')->assertOk();

        $this->assertSame(['Échéance', 'Total recettes'], array_column($response->json(), 'term'));
        $response->assertJsonPath('1.formula', 'a × b')->assertJsonMissingPath('0.definition');
        $this->assertStringContainsString('<strong>T</strong>', $response->json('1.definition_html'));
    }

    public function test_other_profiles_are_refused(): void
    {
        $this->as(Profil::Admin)->getJson('/api/v1/owner/glossary')->assertForbidden();
    }
}
