<?php

namespace Tests\Feature\Api;

use App\Domains\Identity\Application\Mail\PasswordResetLinksMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Laravel ne sert plus que l'API (2026-09-27) : plus de vue, plus de route web.
 */
class ApiOnlyTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_the_api_and_the_health_check_are_routed(): void
    {
        $uris = collect(Route::getRoutes()->getRoutes())->map->uri()
            ->reject(fn (string $uri) => str_starts_with($uri, 'api/') || $uri === 'up'
                || str_starts_with($uri, 'sanctum/') || str_starts_with($uri, 'storage/'))
            ->values()->all();

        $this->assertSame([], $uris, 'aucune route hors API');
    }

    /** Sans `Accept: application/json` : c'est le cas qui tombait en 500 sur `route('login')`. */
    public function test_a_guest_gets_a_json_401_even_without_the_accept_header(): void
    {
        $response = $this->get('/api/v1/admin/bookings');

        $response->assertStatus(401);
        $this->assertStringContainsString('application/json', (string) $response->headers->get('Content-Type'));
    }

    public function test_former_blade_addresses_answer_a_json_404(): void
    {
        foreach (['/', '/login', '/admin/dashboard', '/driver/dashboard'] as $path) {
            $response = $this->get($path);
            $response->assertStatus(404);
            $this->assertStringContainsString('application/json', (string) $response->headers->get('Content-Type'), $path);
        }
    }

    public function test_the_password_reset_email_still_renders(): void
    {
        $html = (new PasswordResetLinksMail([
            ['label' => 'Administrateur', 'url' => 'https://app-staging.chictuktuk.com/reset-password?token=x'],
        ]))->render();

        $this->assertStringContainsString('https://app-staging.chictuktuk.com/reset-password', $html);
        $this->assertStringContainsString('Administrateur', $html);
    }
}
