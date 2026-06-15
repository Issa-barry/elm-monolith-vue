<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8" />
<title>Fiche de paiement {{ $fiche->reference }}</title>
<style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #1a1a1a; background: #fff; }
    .page { padding: 28px 32px; }

    .header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 18px; border-bottom: 2px solid #1d4ed8; padding-bottom: 14px; }
    .org-name { font-size: 18px; font-weight: 700; color: #1e3a8a; }
    .doc-title { text-align: right; }
    .doc-type { font-size: 14px; font-weight: 700; color: #1e3a8a; text-transform: uppercase; }
    .doc-ref { font-size: 10px; color: #374151; margin-top: 4px; font-family: monospace; }
    .doc-date { font-size: 8px; color: #6b7280; margin-top: 4px; }

    .meta { margin-bottom: 14px; font-size: 9px; color: #374151; }
    .meta-row { margin-bottom: 3px; }
    .meta-row b { color: #111827; display: inline-block; width: 130px; }

    table { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
    thead tr { background: #1e40af; }
    thead th { padding: 6px 8px; text-align: left; font-size: 8px; font-weight: 700; text-transform: uppercase; color: #fff; }
    thead th.right { text-align: right; }
    tbody tr { border-bottom: 1px solid #e5e7eb; }
    tbody td { padding: 5px 8px; font-size: 9px; }
    tbody td.right { text-align: right; font-family: monospace; }
    .gain { color: #047857; }
    .deduction { color: #b91c1c; }

    .totaux { margin-top: 8px; width: 50%; margin-left: auto; }
    .totaux td { padding: 4px 8px; font-size: 9px; }
    .totaux td.right { text-align: right; font-family: monospace; }
    .totaux .net td { font-weight: 700; font-size: 12px; border-top: 2px solid #1d4ed8; color: #1e3a8a; }

    .signatures { display: flex; justify-content: space-between; margin-top: 50px; }
    .sig-box { width: 45%; }
    .sig-line { border-top: 1px solid #9ca3af; margin-top: 40px; padding-top: 4px; font-size: 8px; color: #6b7280; text-align: center; }

    .footer { font-size: 7.5px; color: #9ca3af; border-top: 1px solid #e5e7eb; padding-top: 8px; margin-top: 30px; }
</style>
</head>
<body>
<div class="page">
    <div class="header">
        <div>
            <div class="org-name">{{ $org?->name ?? 'ELM' }}</div>
            @if ($fiche->site)
                <div style="font-size: 9px; color: #6b7280; margin-top: 4px;">Agence : {{ $fiche->site->nom }}</div>
            @endif
        </div>
        <div class="doc-title">
            <div class="doc-type">Fiche de paiement</div>
            <div class="doc-ref">{{ $fiche->reference }}</div>
            <div class="doc-date">Imprimé le {{ $generated_at->format('d/m/Y à H:i') }} par {{ $printed_by }}</div>
        </div>
    </div>

    <div class="meta">
        <div class="meta-row"><b>Bénéficiaire :</b> {{ $fiche->beneficiaire_nom }}</div>
        <div class="meta-row"><b>Type :</b> {{ ucfirst($fiche->beneficiaire_type) }}</div>
        @if ($fiche->periode)
            <div class="meta-row"><b>Période :</b> {{ $fiche->periode->reference }} ({{ $fiche->periode->date_debut?->format('d/m/Y') }} → {{ $fiche->periode->date_fin?->format('d/m/Y') }})</div>
        @endif
        <div class="meta-row"><b>Statut :</b> {{ $fiche->statut?->label() }}</div>
    </div>

    <table>
        <thead>
            <tr>
                <th>Type</th>
                <th>Libellé</th>
                <th class="right">Montant (GNF)</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($fiche->lignes as $ligne)
                <tr>
                    <td>{{ $ligne->type_ligne?->label() }}</td>
                    <td>{{ $ligne->libelle }}</td>
                    <td class="right {{ $ligne->montant < 0 ? 'deduction' : 'gain' }}">
                        {{ number_format((float) $ligne->montant, 0, ',', ' ') }}
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totaux">
        <tr>
            <td>Total gains</td>
            <td class="right gain">{{ number_format((float) $fiche->montant_brut, 0, ',', ' ') }}</td>
        </tr>
        <tr>
            <td>Total déductions</td>
            <td class="right deduction">- {{ number_format((float) $fiche->total_deductions, 0, ',', ' ') }}</td>
        </tr>
        <tr class="net">
            <td>Net à payer</td>
            <td class="right">{{ number_format((float) $fiche->montant_net, 0, ',', ' ') }} GNF</td>
        </tr>
    </table>

    <div class="signatures">
        <div class="sig-box">
            <div class="sig-line">Signature du bénéficiaire</div>
        </div>
        <div class="sig-box">
            <div class="sig-line">Signature de l'agence</div>
        </div>
    </div>

    <div class="footer">
        Document généré automatiquement — {{ $org?->name ?? 'ELM' }}
    </div>
</div>
</body>
</html>
