<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta http-equiv="refresh" content="30" />
    <title>Maintenance — Eau la maman</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            background-color: #ffffff;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 5rem 1.25rem;
        }

        .wrap {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 2.5rem;
            width: 100%;
            max-width: 480px;
        }

        .glow {
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 3.5rem 2rem;
            border-radius: 1rem;
            background: radial-gradient(50% 109% at 50% 50%, rgba(99, 102, 241, 0.08) 0%, transparent 100%);
        }

        .badge-wrap {
            background: #ffffff;
            padding: 0.25rem 1rem;
            border-radius: 0.625rem;
        }

        .badge {
            display: inline-block;
            background: #eef2ff;
            color: #6366f1;
            font-size: 0.875rem;
            font-weight: 600;
            border-radius: 0.5rem;
            padding: 0.25rem 0.75rem;
        }

        .content {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 1rem;
            text-align: center;
        }

        h1 {
            font-size: 2.25rem;
            font-weight: 700;
            line-height: 1.25;
            color: #111827;
        }

        p {
            font-size: 1.0625rem;
            line-height: 1.7;
            color: #6b7280;
        }

        .brand { color: #6366f1; font-weight: 600; }

        .dots {
            display: flex;
            gap: 0.4rem;
            margin-top: 0.5rem;
        }

        .dot {
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: #6366f1;
            opacity: 0.3;
            animation: pulse 1.4s ease-in-out infinite;
        }
        .dot:nth-child(2) { animation-delay: 0.2s; }
        .dot:nth-child(3) { animation-delay: 0.4s; }

        @keyframes pulse {
            0%, 80%, 100% { opacity: 0.3; transform: scale(1); }
            40% { opacity: 1; transform: scale(1.3); }
        }

        @media (prefers-color-scheme: dark) {
            body { background-color: #09090b; }
            .badge-wrap { background: #09090b; }
            .badge { background: rgba(99, 102, 241, 0.18); color: #a5b4fc; }
            h1 { color: #f9fafb; }
            p { color: #9ca3af; }
        }

        @media (max-width: 480px) {
            h1 { font-size: 1.75rem; }
        }
    </style>
</head>
<body>
    <div class="wrap">
        <div class="glow">
            <div class="badge-wrap">
                <span class="badge">Maintenance</span>
            </div>
        </div>

        <div class="content">
            <h1>Maintenace en cours !</h1>
            <p>
                <span class="brand">Eau la maman</span> est momentanément indisponible.<br>
                Nous revenons très bientôt, merci de votre patience.
            </p>
            <div class="dots">
                <div class="dot"></div>
                <div class="dot"></div>
                <div class="dot"></div>
            </div>
        </div>
    </div>
</body>
</html>
