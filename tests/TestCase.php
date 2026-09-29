<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Aucun test ne part sur le réseau : un appel HTTP sans `Http::fake()` lève au lieu
        // d'interroger le vrai service. Ajouté le 2026-09-29, quand un test de réservation
        // publique a échoué sur un délai d'OpenRouteService — il passait ou non selon la
        // connexion du moment, et quatre autres tests en dépendaient sans le savoir.
        Http::preventStrayRequests();
    }
}
