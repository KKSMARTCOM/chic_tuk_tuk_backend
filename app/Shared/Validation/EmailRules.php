<?php

namespace App\Shared\Validation;

/**
 * Les règles d'une adresse e-mail saisie — à utiliser à la place de `'email'` seul.
 *
 * ⚠️ La règle `email` de Laravel 11 accepte un retour à la ligne dans une partie
 * « repliée » de l'adresse — entre guillemets (`"awa\r\n "@…`) ou dans un commentaire
 * (`awa(\r\n )@…`) : GHSA-5vg9-5847-vvmq. Enregistrée, une telle adresse injecterait des
 * en-têtes dans les e-mails qu'on lui envoie, dont celui de réinitialisation du mot de
 * passe. Corrigé dans Laravel 12.60 ; le projet y est passé le 2026-09-29, et ce refus
 * explicite est GARDÉ : il ne coûte rien, et documente le piège pour qui réécrirait une
 * règle e-mail à la main.
 */
final class EmailRules
{
    /** @return list<string> */
    public static function rules(): array
    {
        return ['email', 'not_regex:/[\r\n]/'];
    }
}
