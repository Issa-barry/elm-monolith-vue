<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#f4f4f4">
    <title>Compte activé – {{ config('app.name') }}</title>
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
            box-shadow: 0 2px 20px rgba(0,0,0,.07);
        }
        .icon-wrap {
            width: 80px; height: 80px;
            background: #d1fae5;
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            margin: 0 auto 28px;
        }
        .icon-wrap svg { width: 40px; height: 40px; }
        h1 { font-size: 22px; font-weight: 700; color: #111827; line-height: 1.3; margin-bottom: 12px; }
        .desc { font-size: 15px; color: #6b7280; line-height: 1.6; margin-bottom: 6px; }
        .divider { width: 40px; height: 3px; background: #d1fae5; border-radius: 2px; margin: 24px auto; }
        .btn-app {
            display: block;
            background: #111827; color: #fff; text-decoration: none;
            font-size: 16px; font-weight: 600;
            padding: 15px 24px; border-radius: 12px;
            margin-top: 24px;
            -webkit-tap-highlight-color: transparent;
            transition: opacity .15s;
        }
        .btn-app:active { opacity: .8; }
        .redirect-hint { margin-top: 14px; font-size: 12px; color: #9ca3af; }
        .footer { margin-top: 28px; font-size: 12px; color: #9ca3af; line-height: 1.6; }
        @media (min-width: 480px) {
            .card { padding: 52px 48px 44px; }
            h1 { font-size: 24px; }
        }
    </style>
</head>
<body>
    <div class="card">
        <div class="icon-wrap">
            <svg fill="none" stroke="#059669" stroke-width="2.5" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/>
            </svg>
        </div>

        <h1>Compte validé avec succès&nbsp;!</h1>

        <p class="desc">Votre adresse email a bien été confirmée.</p>
        <p class="desc">Vous pouvez maintenant vous connecter.</p>

        <div class="divider"></div>

        <a href="{{ env('MOBILE_CLIENT_SCHEME', 'mobile') }}://email-verified" class="btn-app">
            Ouvrir l'application
        </a>

        <p class="redirect-hint" id="hint">Redirection automatique dans <span id="countdown">3</span>s…</p>

        <p class="footer">&copy; {{ date('Y') }} {{ config('app.name') }}. Tous droits réservés.</p>
    </div>

    <script>
        var scheme  = "{{ env('MOBILE_CLIENT_SCHEME', 'mobile') }}";
        var deepLink = scheme + "://email-verified";
        var seconds  = 3;

        var el = document.getElementById('countdown');

        var timer = setInterval(function () {
            seconds--;
            if (el) el.textContent = seconds;
            if (seconds <= 0) {
                clearInterval(timer);
                window.location.href = deepLink;
            }
        }, 1000);

        // Si l'app n'est pas installée, le redirect échoue silencieusement —
        // l'utilisateur reste sur cette page et peut fermer manuellement.
        window.addEventListener('blur', function () { clearInterval(timer); });
    </script>
</body>
</html>
