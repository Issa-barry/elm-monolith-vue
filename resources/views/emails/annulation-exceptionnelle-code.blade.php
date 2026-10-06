<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Code de confirmation — Annulation exceptionnelle</title>
<style>
  body { margin: 0; padding: 0; background: #f1f5f9; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; }
  .wrap { max-width: 480px; margin: 40px auto; padding: 0 16px; }
  .card { background: #ffffff; border-radius: 16px; overflow: hidden; box-shadow: 0 4px 24px rgba(0,0,0,0.08); }
  .header { background: #b91c1c; padding: 32px 24px 24px; text-align: center; }
  .header-icon { font-size: 40px; margin-bottom: 12px; }
  .header-title { color: #ffffff; font-size: 20px; font-weight: 700; margin: 0; }
  .body { padding: 32px 24px; }
  .intro { font-size: 15px; color: #475569; line-height: 1.6; margin: 0 0 16px; }
  .reference { font-size: 14px; color: #334155; margin: 0 0 24px; }
  .code-box { background: #fef2f2; border: 2px dashed #fca5a5; border-radius: 12px; padding: 24px; text-align: center; margin: 0 0 24px; }
  .code-label { font-size: 12px; color: #64748b; font-weight: 600; letter-spacing: 1px; text-transform: uppercase; margin: 0 0 8px; }
  .code { font-size: 42px; font-weight: 800; color: #b91c1c; letter-spacing: 12px; margin: 0; font-variant-numeric: tabular-nums; }
  .expiry { font-size: 13px; color: #64748b; text-align: center; margin: 0 0 24px; line-height: 1.5; }
  .warning { background: #fef9c3; border-left: 4px solid #facc15; border-radius: 8px; padding: 12px 16px; font-size: 13px; color: #713f12; line-height: 1.5; margin: 0 0 24px; }
  .footer { padding: 20px 24px; border-top: 1px solid #e2e8f0; text-align: center; font-size: 12px; color: #94a3b8; line-height: 1.6; }
</style>
</head>
<body>
<div class="wrap">
  <div class="card">
    <div class="header">
      <div class="header-icon">⚠️</div>
      <p class="header-title">Annulation exceptionnelle</p>
    </div>
    <div class="body">
      <p class="intro">Une demande d'annulation exceptionnelle nécessite votre confirmation.</p>
      <p class="reference">Commande concernée : <strong>{{ $reference }}</strong></p>
      <div class="code-box">
        <p class="code-label">Votre code de confirmation est</p>
        <p class="code">{{ $code }}</p>
      </div>
      <p class="expiry">Ce code est valable <strong>{{ $dureeMinutes }} minutes</strong> et ne peut être utilisé qu'une seule fois.</p>
      <div class="warning">
        Si vous n'êtes pas à l'origine de cette demande, ignorez cet e-mail.
      </div>
    </div>
    <div class="footer">
      Eau La Maman &mdash; Ne répondez pas à cet e-mail.
    </div>
  </div>
</div>
</body>
</html>
