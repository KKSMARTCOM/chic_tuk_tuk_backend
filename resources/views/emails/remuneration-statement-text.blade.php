{{-- La version texte de l'e-mail de la fiche de rémunération, pour les messageries sans HTML.
     ⚠️ `{!! !!}` : un texte brut n'a pas d'entités HTML — `{{ }}` y écrirait « d&#039;octobre ». --}}
Bonjour{!! $ownerName !== '' ? ' '.$ownerName : '' !!},

Veuillez trouver en pièce jointe votre fiche de rémunération propriétaire Chic Tuk Tuk relative au mois {!! $monthPhrase !!} (n° {!! $number !!}).

Elle est aussi disponible dans votre espace propriétaire, onglet Paiements du véhicule.

Nous vous remercions pour votre confiance et restons à votre disposition pour toute information complémentaire.

Cordialement,

Service Comptabilité
Chic Tuk Tuk
