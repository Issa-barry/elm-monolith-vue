<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#f4f4f4">
    <title>Lien invalide – {{ config('app.name') }}</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }

        html, body { height: 100%; }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Arial, sans-serif;
            background: #f4f4f4;
            min-height: 100dvh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: env(safe-area-inset-top, 24px) 20px env(safe-area-inset-bottom, 24px);
        }

        .card {
            background: #fff;
            border-radius: 20px;
            padding: 40px 24px 36px;
            width: 100%;
            max-width: 400px;
            text-align: center;
            box-shadow: 0 2px 20px rgba(0, 0, 0, .07);
        }

        .icon-wrap {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 28px;
            background: {{ $expired ? '#fef3c7' : '#fee2e2' }};
        }

        .icon-wrap svg { width: 40px; height: 40px; }

        h1 {
            font-size: 22px;
            font-weight: 700;
            color: #111827;
            line-height: 1.3;
            margin-bottom: 12px;
        }

        .desc {
            font-size: 15px;
            color: #6b7280;
            line-height: 1.6;
            margin-bottom: 6px;
        }

        .divider {
            width: 40px;
            height: 3px;
            border-radius: 2px;
            margin: 24px auto;
            background: {{ $expired ? '#fde68a' : '#fca5a5' }};
        }

        .footer {
            margin-top: 28px;
            font-size: 12px;
            color: #9ca3af;
            line-height: 1.6;
        }

        @media (min-width: 480px) {
            .card { padding: 52px 48px 44px; }
            h1 { font-size: 24px; }
        }
    </style>
</head>
<body>
    <div class="card">

        @if($expired)
            <div class="icon-wrap">
                <svg fill="none" stroke="#d97706" stroke-width="2.5" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round"
                          d="M12 6v6l4 2m6-2a10 10 0 11-20 0 10 10 0 0120 0z"/>
                </svg>
            </div>
            <h1>Lien expiré</h1>
            <p class="desc">Ce lien de validation n'est plus valide.</p>
            <p class="desc">Il a expiré après 24 heures. Veuillez vous réinscrire.</p>
        @else
            <div class="icon-wrap">
                <svg fill="none" stroke="#dc2626" stroke-width="2.5" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round"
                          d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </div>
            <h1>Lien invalide</h1>
            <p class="desc">Ce lien de validation est invalide ou a déjà été utilisé.</p>
            <p class="desc">Si votre compte est déjà actif, vous pouvez vous connecter.</p>
        @endif

        <div class="divider"></div>

        <p class="footer">
            &copy; {{ date('Y') }} {{ config('app.name') }}. Tous droits réservés.
        </p>

    </div>
</body>
</html>
