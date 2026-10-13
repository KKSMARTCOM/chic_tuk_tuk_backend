{{--
    Un communiqué (spec 2026-10-13, §5.2). Même gabarit que `remuneration-statement.blade.php` :
    tableaux et styles en ligne, sans image. La version texte est `announcement-text.blade.php`.

    ⚠️ `{!! $bodyHtml !!}` : HTML produit par `AnnouncementBody::toHtml`, HTML brut supprimé et
    liens dangereux refusés. Ne jamais y passer autre chose.
--}}
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>{{ $title }}</title>
</head>
<body style="margin:0; padding:0; background-color:#f3f4f6; font-family:Poppins, Arial, Helvetica, sans-serif; color:#1f2937;">
    <div style="display:none; max-height:0; overflow:hidden; opacity:0;">
        {{ $reminder ? 'Rappel : ' : '' }}{{ $title }}
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
                            @if ($reminder)
                                <p style="margin:0 0 12px; font-size:13px; font-weight:600; color:#b45309; text-transform:uppercase; letter-spacing:0.5px;">Rappel</p>
                            @endif
                            <h1 style="margin:0 0 16px; font-size:22px; line-height:1.3; font-weight:700; color:#111827;">{{ $title }}</h1>
                            <div style="font-size:15px; line-height:1.6;">{!! $bodyHtml !!}</div>
                            @if (count($fileNames) > 0)
                                <p style="margin:16px 0 4px; font-size:14px; font-weight:600;">
                                    {{ $attached ? 'Pièces jointes' : 'Pièces jointes à consulter sur la plateforme' }}
                                </p>
                                <ul style="margin:0 0 16px; padding-left:20px; font-size:14px;">
                                    @foreach ($fileNames as $name)
                                        <li>{{ $name }}</li>
                                    @endforeach
                                </ul>
                            @endif
                            <p style="margin:24px 0 0;">
                                <a href="{{ $url }}" style="display:inline-block; background-color:#286b41; color:#ffffff; text-decoration:none; font-weight:600; padding:12px 20px; border-radius:8px;">Lire sur la plateforme</a>
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
