<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invitation</title>
</head>
<body style="margin:0;padding:0;background-color:#f4f4f4;font-family:Arial,Helvetica,sans-serif;">

    <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f4f4f4;padding:40px 16px;">
        <tr>
            <td align="center">

                <table width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:560px;background:#ffffff;border-radius:8px;overflow:hidden;">

                    {{-- En-tête logo --}}
                    <tr>
                        <td align="center" style="padding:32px 40px 24px;">
                            <img
                                src="{{ url('/images/logo-email-dark.svg') }}"
                                width="48"
                                height="48"
                                alt="{{ config('app.name') }}"
                                style="display:block;margin:0 auto;"
                            >
                        </td>
                    </tr>

                    {{-- Séparateur --}}
                    <tr>
                        <td style="padding:0 40px;">
                            <hr style="border:none;border-top:1px solid #e5e7eb;margin:0;">
                        </td>
                    </tr>

                    {{-- Corps --}}
                    <tr>
                        <td style="padding:32px 40px;">

                            <h1 style="margin:0 0 20px;font-size:20px;font-weight:700;color:#111827;line-height:1.3;">
                                Invitation à rejoindre le {{ $invitation->site->type_label }} {{ $invitation->site->nom }}
                            </h1>

                            <p style="margin:0 0 12px;font-size:15px;color:#374151;line-height:1.6;">
                                Bonjour,
                            </p>

                            <p style="margin:0 0 12px;font-size:15px;color:#374151;line-height:1.6;">
                                Vous avez été invité(e) à rejoindre la plateforme
                                <strong>{{ config('app.name') }}</strong>
                                en tant que <strong>{{ ucfirst($invitation->role) }}</strong>
                                du {{ $invitation->site->type_label }} <strong>{{ $invitation->site->nom }}</strong>.
                            </p>

                            <p style="margin:0 0 28px;font-size:15px;color:#374151;line-height:1.6;">
                                Cliquez sur le bouton ci-dessous pour accepter l'invitation.
                                Ce lien est valable pendant <strong>24 heures</strong>.
                            </p>

                            {{-- Bouton --}}
                            <table cellpadding="0" cellspacing="0" border="0">
                                <tr>
                                    <td style="border-radius:6px;background-color:#111827;">
                                        <a href="{{ $acceptUrl }}"
                                           target="_blank"
                                           style="display:inline-block;padding:13px 28px;font-size:15px;font-weight:600;color:#ffffff;text-decoration:none;border-radius:6px;">
                                            Accepter l'invitation
                                        </a>
                                    </td>
                                </tr>
                            </table>

                            <p style="margin:28px 0 0;font-size:13px;color:#9ca3af;line-height:1.6;">
                                Si vous n'attendiez pas cette invitation, vous pouvez ignorer ce message.
                            </p>

                        </td>
                    </tr>

                    {{-- Séparateur --}}
                    <tr>
                        <td style="padding:0 40px;">
                            <hr style="border:none;border-top:1px solid #e5e7eb;margin:0;">
                        </td>
                    </tr>

                    {{-- Pied de page --}}
                    <tr>
                        <td style="padding:20px 40px 28px;">
                            <p style="margin:0;font-size:13px;color:#6b7280;line-height:1.6;">
                                Cordialement,<br>
                                <strong style="color:#374151;">L'équipe Eau_la-maman</strong>
                            </p>
                        </td>
                    </tr>

                </table>

            </td>
        </tr>
    </table>

</body>
</html>
