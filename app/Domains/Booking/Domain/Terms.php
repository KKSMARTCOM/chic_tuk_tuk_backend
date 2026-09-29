<?php

namespace App\Domains\Booking\Domain;

/**
 * Les conditions générales d'utilisation que le client accepte en réservant (2026-09-29).
 *
 * La version est la date du document, « Fait à Cotonou, le 10 septembre 2026 » : elle
 * est gardée sur chaque réservation avec la date d'acceptation, pour savoir en cas de
 * litige QUEL texte le client a accepté. Une nouvelle version des CGU — nouveau PDF sur le
 * landing — change cette constante, sans toucher aux réservations déjà faites.
 */
final class Terms
{
    public const VERSION = '2026-09-10';
}
