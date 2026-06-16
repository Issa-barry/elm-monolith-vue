<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8" />
<title>{{ $title }}</title>
<style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 9px; color: #1a1a1a; background: #fff; }

    .page-break {
        page-break-before: always;
        break-before: page;
        display: block;
        height: 0;
        line-height: 0;
        font-size: 0;
    }

    .site-page { padding: 22px 26px; }

    .header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 16px; border-bottom: 2px solid #1d4ed8; padding-bottom: 12px; }
    .org-name { font-size: 15px; font-weight: 700; color: #1e3a8a; }
    .doc-title { text-align: right; }
    .doc-type  { font-size: 12px; font-weight: 700; color: #1e3a8a; text-transform: uppercase; }
    .doc-sub   { font-size: 8.5px; color: #374151; margin-top: 3px; }
    .doc-date  { font-size: 7.5px; color: #6b7280; margin-top: 3px; }

    .meta { display: flex; gap: 20px; margin-bottom: 12px; font-size: 8px; color: #374151; flex-wrap: wrap; }
    .meta span { font-weight: 600; color: #111827; }

    table { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
    thead tr { background: #1e40af; }
    thead th { padding: 5px 6px; text-align: left; font-size: 7px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.4px; color: #fff; white-space: nowrap; }
    thead th.right { text-align: right; }
    tbody tr:nth-child(even) { background: #f8fafc; }
    tbody tr { border-bottom: 1px solid #e5e7eb; }
    tbody td { padding: 4px 6px; font-size: 7.5px; vertical-align: top; }
    tbody td.right { text-align: right; font-family: monospace; }

    .badge { display: inline-block; padding: 1px 5px; border-radius: 99px; font-size: 6.5px; font-weight: 700; text-transform: uppercase; }
    .badge-impaye  { background: #fee2e2; color: #b91c1c; }
    .badge-partiel { background: #fef3c7; color: #92400e; }
    .badge-paye    { background: #d1fae5; color: #065f46; }

    .sig { min-width: 70px; border-bottom: 1px solid #9ca3af; height: 16px; }

    .total-row td { font-weight: 700; font-size: 8px; border-top: 2px solid #1d4ed8; padding-top: 5px; background: #eff6ff; }

    .footer { display: flex; justify-content: space-between; font-size: 7px; color: #9ca3af; border-top: 1px solid #e5e7eb; padding-top: 6px; margin-top: 2px; }
</style>
</head>
<body>

@foreach($sites as $siteData)

@if(!$loop->first)
<div class="page-break"></div>
@endif

<div class="site-page">

    <div class="header">
        <div>
            <div class="org-name">{{ $org?->name ?? 'ELM' }}</div>
        </div>
        <div class="doc-title">
            <div class="doc-type">{{ $title }}</div>
            @if($siteData['site_nom'])
            <div class="doc-sub">Agence : {{ $siteData['site_nom'] }}</div>
            @endif
            <div class="doc-date">Imprimé le {{ $generated_at->format('d/m/Y à H:i') }} par {{ $printed_by }}</div>
        </div>
    </div>

    <div class="meta">
        <div>Période : <span>{{ $periode_label }}</span></div>
        @if($siteData['site_nom'])
        <div>Agence : <span>{{ $siteData['site_nom'] }}</span></div>
        @endif
        @if(!empty($filters['statut']))
        <div>Statut : <span>{{ ucfirst($filters['statut']) }}</span></div>
        @endif
        @if(!empty($filters['search']))
        <div>Recherche : <span>{{ $filters['search'] }}</span></div>
        @endif
        <div>Total : <span>{{ count($siteData['rows']) }} bénéficiaire(s)</span></div>
    </div>

    <table>
        <thead>
            <tr>
                <th>Bénéficiaire</th>
                <th>Téléphone</th>
                <th>Véhicule(s)</th>
                <th class="right">Total cumulé (GNF)</th>
                <th class="right">Frais (GNF)</th>
                <th>Motif de frais</th>
                <th class="right">Déjà payé (GNF)</th>
                <th class="right">Reste à payer (GNF)</th>
                <th>Statut</th>
                <th>Signature</th>
            </tr>
        </thead>
        <tbody>
            @forelse($siteData['rows'] as $row)
            <tr>
                <td><strong>{{ $row['beneficiaire_nom'] }}</strong></td>
                <td>{{ $row['telephone'] ?? '—' }}</td>
                <td style="max-width:90px">{{ $row['vehicules'] ?? '—' }}</td>
                <td class="right">{{ number_format((float) $row['total_cumule'], 0, ',', ' ') }}</td>
                <td class="right">{{ $row['frais'] > 0 ? number_format((float) $row['frais'], 0, ',', ' ') : '—' }}</td>
                <td style="max-width:80px">{{ $row['motifs_frais'] ?? '—' }}</td>
                <td class="right">{{ number_format((float) $row['deja_paye'], 0, ',', ' ') }}</td>
                <td class="right">{{ $row['reste'] > 0 ? number_format((float) $row['reste'], 0, ',', ' ') : '—' }}</td>
                <td>
                    @php
                        $badge = match($row['statut']) {
                            'Payé'    => 'paye',
                            'Partiel' => 'partiel',
                            default   => 'impaye',
                        };
                    @endphp
                    <span class="badge badge-{{ $badge }}">{{ $row['statut'] }}</span>
                </td>
                <td class="sig"></td>
            </tr>
            @empty
            <tr>
                <td colspan="10" style="text-align:center; color:#6b7280; padding: 16px;">Aucun résultat pour ces critères.</td>
            </tr>
            @endforelse

            @if(count($siteData['rows']) > 0)
            <tr class="total-row">
                <td colspan="3" style="text-align:right; padding-right:6px; color:#374151;">Totaux :</td>
                <td class="right">{{ number_format((float) $siteData['totaux']['total_cumule'], 0, ',', ' ') }}</td>
                <td class="right">{{ $siteData['totaux']['total_frais'] > 0 ? number_format((float) $siteData['totaux']['total_frais'], 0, ',', ' ') : '—' }}</td>
                <td></td>
                <td class="right">{{ number_format((float) $siteData['totaux']['total_deja_paye'], 0, ',', ' ') }}</td>
                <td class="right">{{ number_format((float) $siteData['totaux']['total_reste'], 0, ',', ' ') }}</td>
                <td colspan="2"></td>
            </tr>
            @endif
        </tbody>
    </table>

    <div class="footer">
        <span>{{ $org?->name ?? 'ELM' }} — Document confidentiel</span>
        <span>{{ $generated_at->format('d/m/Y H:i') }}</span>
    </div>

</div>

@endforeach

</body>
</html>
