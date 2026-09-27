<?php

namespace Tests\Feature\Booking;

use App\Domains\Identity\Domain\ReferenceCatalog;
use App\Services\PricingService;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * La section « Tarifs » de l'administration est retirée (2026-09-27), sans toucher au
 * calcul du prix.
 *
 * Elle gérait des tarifs par couple de zones (table `pricing`) que RIEN ne lisait : le
 * prix vient de `PricingService`, qui applique les constantes de `Price` à la distance
 * (prix au kilomètre, minimum, majoration horaire). Aucun lien du menu Blade n'y menait,
 * et ses routes n'exigeaient aucune permission. La table et ses lignes restent en base.
 */
class TariffSectionRemovedTest extends TestCase
{
    public function test_the_admin_tariff_routes_are_gone(): void
    {
        foreach (['index', 'create', 'store', 'show', 'edit', 'update', 'destroy'] as $action) {
            $this->assertFalse(Route::has("admin.pricing.{$action}"), "admin.pricing.{$action}");
        }
    }

    public function test_the_price_computation_routes_stay(): void
    {
        // Le calcul du prix des formulaires Blade, et le devis de l'API publique.
        $this->assertTrue(Route::has('pricing.get-price'));
        $this->assertTrue(Route::has('api.v1.public.pricing.quote'));
    }

    public function test_the_price_still_comes_from_the_distance(): void
    {
        $pricing = app(PricingService::class);

        $this->assertGreaterThan(0, $pricing->getPrice(10));
        $this->assertGreaterThan($pricing->getPrice(10), $pricing->getPrice(40));
    }

    public function test_the_tariff_permissions_leave_the_catalog(): void
    {
        foreach (['view-pricing', 'create-pricing', 'edit-pricing', 'manage-pricing'] as $permission) {
            $this->assertNotContains($permission, ReferenceCatalog::permissionNames(), $permission);
        }
    }
}
