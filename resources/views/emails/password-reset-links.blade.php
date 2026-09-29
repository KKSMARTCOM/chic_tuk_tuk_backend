{{--
    L'e-mail de réinitialisation du mot de passe (mise en forme du 2026-09-29).

    ⚠️ Un e-mail n'est pas une page web : mise en page en TABLEAUX et styles EN LIGNE,
    seuls respectés par Gmail et Outlook ; pas de feuille de style externe, pas de
    police web garantie (Poppins, puis Arial). Pas de logo en image : beaucoup de
    messageries bloquent les images par défaut, et un bandeau cassé inquiète davantage
    qu'un nom écrit. Le lien est répété en clair sous chaque bouton, pour les
    messageries qui n'affichent pas le bouton. La version texte est
    `password-reset-links-text.blade.php`.
--}}
@php
    $minutes = config('identity.password_reset.expire_minutes');
    $several = count($links) > 1;
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>Réinitialisation de votre mot de passe</title>
</head>
<body style="margin:0; padding:0; background-color:#f3f4f6; font-family:Poppins, Arial, Helvetica, sans-serif; color:#1f2937;">
    {{-- Aperçu affiché par la messagerie à côté de l'objet, invisible dans le message. --}}
    <div style="display:none; max-height:0; overflow:hidden; opacity:0;">
        Votre lien pour choisir un nouveau mot de passe, valable {{ $minutes }} minutes.
    </div>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f3f4f6;">
        <tr>
            <td align="center" style="padding:32px 16px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:560px; background-color:#ffffff; border-radius:12px; overflow:hidden;">
                    <tr>
                        <td style="background-color:#286b41; padding:24px 32px;">
                            <p style="margin:0; font-size:22px; font-weight:700; color:#ffffff; letter-spacing:0.2px;">Chic Tuk Tuk</p>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:32px 32px 8px;">
                            <h1 style="margin:0 0 16px; font-size:22px; line-height:1.3; font-weight:700; color:#111827;">
                                Réinitialiser votre mot de passe
                            </h1>
                            <p style="margin:0 0 12px; font-size:15px; line-height:1.6;">Bonjour,</p>
                            @if ($several)
                                <p style="margin:0 0 24px; font-size:15px; line-height:1.6;">
                                    Plusieurs comptes ChicTukTuk utilisent cette adresse. Choisissez celui
                                    dont vous souhaitez réinitialiser le mot de passe.
                                </p>
                            @else
                                <p style="margin:0 0 24px; font-size:15px; line-height:1.6;">
                                    Vous avez demandé à changer le mot de passe de votre compte ChicTukTuk.
                                    Cliquez sur le bouton pour en choisir un nouveau.
                                </p>
                            @endif
                        </td>
                    </tr>

                    @foreach ($links as $link)
                        <tr>
                            <td style="padding:0 32px 24px;">
                                <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                                    <tr>
                                        <td style="border-radius:8px; background-color:#286b41;">
                                            <a href="{{ $link['url'] }}" target="_blank"
                                               style="display:inline-block; padding:14px 24px; font-size:15px; font-weight:600; color:#ffffff; text-decoration:none; border-radius:8px;">
                                                Réinitialiser mon accès {{ $link['label'] }}
                                            </a>
                                        </td>
                                    </tr>
                                </table>
                                <p style="margin:12px 0 0; font-size:12px; line-height:1.5; color:#6b7280;">
                                    Le bouton ne s'affiche pas ? Copiez ce lien dans votre navigateur :<br>
                                    <a href="{{ $link['url'] }}" style="color:#286b41; word-break:break-all;">{{ $link['url'] }}</a>
                                </p>
                            </td>
                        </tr>
                    @endforeach

                    <tr>
                        <td style="padding:0 32px 32px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#fff7e6; border-radius:8px;">
                                <tr>
                                    <td style="padding:16px 20px; font-size:13px; line-height:1.6; color:#374151;">
                                        {{ $several ? 'Ces liens sont valables' : 'Ce lien est valable' }} <strong>{{ $minutes }} minutes</strong>.
                                        Si vous n'êtes pas à l'origine de cette demande, ignorez ce message :
                                        votre mot de passe reste inchangé.
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                </table>

                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:560px;">
                    <tr>
                        <td style="padding:20px 32px; font-size:12px; line-height:1.5; color:#9ca3af; text-align:center;">
                            Chic Tuk Tuk — transport en tricycle à Cotonou.<br>
                            Vous recevez ce message parce qu'une réinitialisation a été demandée pour cette adresse.
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
