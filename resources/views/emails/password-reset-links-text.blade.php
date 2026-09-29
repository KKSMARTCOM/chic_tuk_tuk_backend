{{-- La version texte de l'e-mail de réinitialisation, pour les messageries sans HTML. --}}
@php($minutes = config('identity.password_reset.expire_minutes'))
Bonjour,

@if (count($links) > 1)
Plusieurs comptes ChicTukTuk utilisent cette adresse. Choisissez celui dont vous souhaitez réinitialiser le mot de passe :
@else
Vous avez demandé à changer le mot de passe de votre compte ChicTukTuk. Ouvrez ce lien pour en choisir un nouveau :
@endif

@foreach ($links as $link)
Réinitialiser mon accès {{ $link['label'] }} :
{{ $link['url'] }}

@endforeach
{{ count($links) > 1 ? 'Ces liens sont valables' : 'Ce lien est valable' }} {{ $minutes }} minutes. Si vous n'êtes pas à l'origine de cette demande, ignorez ce message : votre mot de passe reste inchangé.

Chic Tuk Tuk — transport en tricycle à Cotonou.
