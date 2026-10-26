{{-- La version texte de l'e-mail d'un versement, pour les messageries sans HTML.
     ⚠️ `{!! !!}` : un texte brut n'a pas d'entités HTML. --}}
Bonjour{!! $ownerName !== '' ? ' '.$ownerName : '' !!},

@if ($withProof)
Veuillez trouver en pièce jointe la preuve du versement de {!! $amount !!} effectué le {!! $paidOn !!} au titre de votre revenu propriétaire Chic Tuk Tuk (véhicule {!! $vehicleNumber !!}).
@else
Nous vous informons du versement de {!! $amount !!} effectué le {!! $paidOn !!} au titre de votre revenu propriétaire Chic Tuk Tuk (véhicule {!! $vehicleNumber !!}).
@endif

Vous retrouvez vos versements dans votre espace propriétaire, onglet Paiements du véhicule.

Nous vous remercions pour votre confiance et restons à votre disposition pour toute information complémentaire.

Cordialement,

Service Comptabilité
Chic Tuk Tuk
