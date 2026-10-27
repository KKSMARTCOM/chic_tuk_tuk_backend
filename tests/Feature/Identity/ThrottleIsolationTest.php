<?php

namespace Tests\Feature\Identity;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Chaque route throttlée doit avoir son propre compteur.
 *
 * Le throttle de Laravel ne le fait PAS par défaut : pour une requête anonyme,
 * `ThrottleRequests::resolveRequestSignature()` renvoie `sha1(domaine|IP)`, sans la
 * route ni l'URI. Toutes les routes throttlées non authentifiées partagent donc un
 * compteur unique par IP, et la fenêtre de la route qui a créé le minuteur bloque
 * toutes les autres.
 *
 * Conséquence concrète, observée sur staging le 2026-09-17 : `POST /public/bookings`
 * étant plafonnée à 10 par heure, dix réservations anonymes depuis une IP
 * d'opérateur mobile — qui en couvre de nombreux abonnés au Bénin — bloquaient une
 * heure durant, en plus des réservations, la connexion des agents.
 */
class ThrottleIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_epuiser_le_devis_public_ne_bloque_pas_la_connexion(): void
    {
        // Ce test DISCRIMINE, et c'est tout son intérêt : on épuise la route au
        // plafond le PLUS HAUT (le devis, 60 par minute), puis on vérifie une route
        // au plafond plus bas (la connexion, 30 par minute).
        //
        // Avec un compteur partagé, la connexion voit un compteur à 61, dépasse son
        // plafond de 30 et renvoie 429. Avec des compteurs par route, elle voit son
        // propre compteur à zéro. L'inverse — épuiser une route basse puis tester une
        // route haute — passe dans les deux cas et ne prouve rien.
        for ($i = 0; $i < 61; $i++) {
            $this->getJson('/api/v1/public/pricing/quote');
        }

        $this->assertSame(
            429,
            $this->getJson('/api/v1/public/pricing/quote')->status(),
            'le devis public devrait être throttlé après son plafond de 60',
        );

        $this->postJson('/api/v1/auth/login', [])->assertStatus(422);
    }

    public function test_epuiser_la_connexion_ne_bloque_pas_la_reservation_publique(): void
    {
        for ($i = 0; $i < 31; $i++) {
            $this->postJson('/api/v1/auth/login', []);
        }

        $this->assertSame(
            429,
            $this->postJson('/api/v1/auth/login', [])->status(),
            'la connexion devrait être throttlée après son plafond de 30',
        );

        // La réservation publique est plafonnée à 10 : avec un compteur partagé à 31,
        // elle serait bloquée alors qu'aucune réservation n'a été tentée.
        $this->postJson('/api/v1/public/bookings', [])->assertStatus(422);
    }

    public function test_epuiser_la_recherche_de_lieux_ne_bloque_pas_le_devis(): void
    {
        // La recherche de lieux (2026-10-27) devient la route au plafond le plus haut
        // (120 par minute) : on l'épuise, puis on éprouve le devis (60).
        for ($i = 0; $i < 121; $i++) {
            $this->getJson('/api/v1/public/places?q=a');
        }

        $this->assertSame(429, $this->getJson('/api/v1/public/places?q=a')->status());

        $this->getJson('/api/v1/public/pricing/quote')->assertStatus(422);
    }
}
