{{--
    L'e-mail d'un versement au propriétaire, avec la preuve en pièce jointe quand elle existe
    (2026-10-26), dans le ton du Service Comptabilité. Même gabarit que celui de la fiche. La
    version texte est `disbursement-text.blade.php`.
--}}
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>Versement de votre revenu</title>
</head>
<body style="margin:0; padding:0; background-color:#f3f4f6; font-family:Poppins, Arial, Helvetica, sans-serif; color:#1f2937;">
    <div style="display:none; max-height:0; overflow:hidden; opacity:0;">
        {{ $amount }} versés le {{ $paidOn }} — véhicule {{ $vehicleNumber }}.
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
                        <td style="padding:32px;">
                            <h1 style="margin:0 0 16px; font-size:22px; line-height:1.3; font-weight:700; color:#111827;">
                                Versement de votre revenu
                            </h1>
                            <p style="margin:0 0 12px; font-size:15px; line-height:1.6;">Bonjour{{ $ownerName !== '' ? ' '.$ownerName : '' }},</p>
                            <p style="margin:0 0 12px; font-size:15px; line-height:1.6;">
                                @if ($withProof)
                                    Veuillez trouver en pièce jointe la preuve du versement de <strong>{{ $amount }}</strong>
                                @else
                                    Nous vous informons du versement de <strong>{{ $amount }}</strong>
                                @endif
                                effectué le {{ $paidOn }} au titre de votre revenu propriétaire Chic Tuk Tuk
                                (véhicule {{ $vehicleNumber }}).
                            </p>
                            <p style="margin:0 0 12px; font-size:15px; line-height:1.6;">
                                Vous retrouvez vos versements dans votre espace propriétaire, onglet Paiements du véhicule.
                            </p>

                            <p style="margin:0 0 12px; font-size:15px; line-height:1.6;">
                                Nous vous remercions pour votre confiance et restons à votre disposition pour toute
                                information complémentaire.
                            </p>
                            <p style="margin:0 0 4px; font-size:15px; line-height:1.6;">Cordialement,</p>
                            <p style="margin:0; font-size:15px; line-height:1.6;">
                                <strong>Service Comptabilité</strong><br>
                                Chic Tuk Tuk
                            </p>
                        </td>
                    </tr>
                </table>

                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:560px;">
                    <tr>
                        <td style="padding:20px 32px; font-size:12px; line-height:1.5; color:#9ca3af; text-align:center;">
                            L'équipe Chic Tuk Tuk — transport en tricycle à Cotonou.
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
