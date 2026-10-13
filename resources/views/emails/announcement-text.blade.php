{{-- La version texte d'un communiqué. ⚠️ `{!! !!}` : un texte brut n'a pas d'entités HTML. --}}
@if ($reminder)
RAPPEL
@endif
{!! $title !!}

{!! $bodyText !!}

@if (count($fileNames) > 0)
{{ $attached ? 'Pièces jointes' : 'Pièces jointes à consulter sur la plateforme' }} :
@foreach ($fileNames as $name)
- {!! $name !!}
@endforeach

@endif
Lire sur la plateforme : {!! $url !!}

L'équipe Chic Tuk Tuk — transport en tricycle à Cotonou.
