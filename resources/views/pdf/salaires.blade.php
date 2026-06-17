<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8" />
<title>Salaires {{ $periode_label }}</title>
<style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 9px; color: #1a1a1a; background: #fff; }
    .page { padding: 24px 28px; }

    .header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 16px; border-bottom: 2px solid #1d4ed8; padding-bottom: 12px; }
    .org-name { font-size: 16px; font-weight: 700; color: #1e3a8a; }
    .doc-title { text-align: right; }
    .doc-type { font-size: 13px; font-weight: 700; color: #1e3a8a; text-transform: uppercase; letter-spacing: 0.05em; }
    .doc-periode { font-size: 11px; font-weight: 600; color: #374151; margin-top: 3px; }
    .doc-date { font-size: 7.5px; color: #6b7280; margin-top: 3px; }

    table { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
    thead tr { background: #1e40af; }
    thead th { padding: 5px 7px; text-align: left; font-size: 7.5px; font-weight: 700; text-transform: uppercase; color: #fff; letter-spacing: 0.04em; }
    thead th.right { text-align: right; }
    tbody tr { border-bottom: 1px solid #e5e7eb; }
    tbody tr:nth-child(even) { background: #f9fafb; }
    tbody td { padding: 4px 7px; font-size: 8.5px; }
    tbody td.right { text-align: right; font-family: monospace; }
    tbody td.name { font-weight: 600; }
    .pos { font-size: 7px; color: #6b7280; }
    .gain { color: #047857; }
    .deduction { color: #b91c1c; }
    .amber { color: #d97706; font-weight: 700; }

    .totaux { margin-top: 4px; width: 55%; margin-left: auto; border: 1px solid #e5e7eb; border-radius: 4px; overflow: hidden; }
    .totaux table { margin-bottom: 0; }
    .totaux thead tr { background: #374151; }
    .totaux tbody td { padding: 4px 10px; font-size: 9px; }
    .totaux tbody td.right { text-align: right; font-family: monospace; font-weight: 600; }
    .totaux .net td { font-weight: 700; font-size: 11px; color: #1e3a8a; border-top: 2px solid #1d4ed8; }
    .totaux .reste td { font-weight: 700; font-size: 10px; color: #d97706; }

    tfoot td { padding: 6px 7px; font-size: 8.5px; font-weight: 700; background: #f1f5f9; border-top: 2px solid #1d4ed8; }
    tfoot td.right { text-align: right; font-family: monospace; }

    .footer { font-size: 7px; color: #9ca3af; border-top: 1px solid #e5e7eb; padding-top: 6px; margin-top: 24px; display: flex; justify-content: space-between; }
</style>
</head>
<body>
<div class="page">
    <div class="header">
        <div>
            <div class="org-name">{{ $org_nom ?: 'ELM' }}</div>
            <div style="font-size: 8px; color: #6b7280; margin-top: 3px;">Module Comptabilité · Paiement salaires</div>
        </div>
        <div class="doc-title">
            <div class="doc-type">État des salaires</div>
            <div class="doc-periode">{{ $periode_label }}</div>
            <div class="doc-date">Généré le {{ $generated_at }}</div>
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th style="width:22%">Salarié</th>
                <th style="width:13%">Poste</th>
                <th style="width:10%">Agence</th>
                <th class="right" style="width:11%">Brut</th>
                <th class="right" style="width:9%">Primes</th>
                <th class="right" style="width:9%">Déductions</th>
                <th class="right" style="width:10%">Net</th>
                <th class="right" style="width:9%">Déjà payé</th>
                <th class="right" style="width:9%">Reste</th>
                <th style="width:8%">Statut</th>
            </tr>
        </thead>
        <tbody>
            @foreach($lignes as $l)
            <tr>
                <td class="name">{{ $l['employe_nom'] }}</td>
                <td><span class="pos">{{ $l['poste'] }}</span></td>
                <td>{{ $l['site'] }}</td>
                <td class="right">{{ number_format($l['brut'], 0, ',', ' ') }}</td>
                <td class="right gain">{{ $l['total_primes'] > 0 ? '+'.number_format($l['total_primes'], 0, ',', ' ') : '—' }}</td>
                <td class="right deduction">{{ $l['deductions'] > 0 ? '-'.number_format($l['deductions'], 0, ',', ' ') : '—' }}</td>
                <td class="right" style="font-weight:600">{{ number_format($l['net'], 0, ',', ' ') }}</td>
                <td class="right gain">{{ number_format($l['deja_paye'], 0, ',', ' ') }}</td>
                <td class="right {{ $l['reste_a_payer'] > 0 ? 'amber' : '' }}">{{ number_format($l['reste_a_payer'], 0, ',', ' ') }}</td>
                <td>{{ $l['statut_label'] }}</td>
            </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <td colspan="3">TOTAUX ({{ count($lignes) }} salarié{{ count($lignes) > 1 ? 's' : '' }})</td>
                <td class="right">{{ number_format($total_brut, 0, ',', ' ') }}</td>
                <td class="right">—</td>
                <td class="right">—</td>
                <td class="right">{{ number_format($total_net, 0, ',', ' ') }}</td>
                <td class="right">{{ number_format($total_paye, 0, ',', ' ') }}</td>
                <td class="right" style="color: #d97706">{{ number_format($total_reste, 0, ',', ' ') }}</td>
                <td></td>
            </tr>
        </tfoot>
    </table>

    <div class="footer">
        <span>{{ $org_nom ?: 'ELM' }} · État des salaires · {{ $periode_label }}</span>
        <span>Généré le {{ $generated_at }}</span>
    </div>
</div>
</body>
</html>
