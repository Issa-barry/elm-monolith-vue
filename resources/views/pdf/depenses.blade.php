<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8" />
<title>Liste des dépenses{{ $site_nom ? ' — '.$site_nom : '' }}</title>
<style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 9px; color: #1a1a1a; background: #fff; }
    .page { padding: 24px 28px; }

    /* Header */
    .header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 18px; border-bottom: 2px solid #1d4ed8; padding-bottom: 14px; }
    .org-name { font-size: 16px; font-weight: 700; color: #1e3a8a; }
    .doc-title { text-align: right; }
    .doc-type  { font-size: 13px; font-weight: 700; color: #1e3a8a; text-transform: uppercase; }
    .doc-date  { font-size: 8px; color: #6b7280; margin-top: 4px; }

    /* Meta row */
    .meta { display: flex; gap: 24px; margin-bottom: 14px; font-size: 8px; color: #374151; }
    .meta span { font-weight: 600; color: #111827; }

    /* Table */
    table { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
    thead tr { background: #1e40af; }
    thead th { padding: 6px 8px; text-align: left; font-size: 7.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: #fff; white-space: nowrap; }
    thead th.right { text-align: right; }
    tbody tr:nth-child(even) { background: #f8fafc; }
    tbody tr { border-bottom: 1px solid #e5e7eb; }
    tbody td { padding: 5px 8px; font-size: 8px; vertical-align: top; }
    tbody td.right { text-align: right; font-family: monospace; }
    tbody td.mono { font-family: monospace; }

    /* Statut badges */
    .badge { display: inline-block; padding: 1px 6px; border-radius: 99px; font-size: 7px; font-weight: 700; text-transform: uppercase; }
    .badge-brouillon { background: #f1f5f9; color: #475569; }
    .badge-soumis    { background: #dbeafe; color: #1d4ed8; }
    .badge-valide    { background: #d1fae5; color: #065f46; }
    .badge-annule    { background: #fee2e2; color: #b91c1c; }

    /* Signature column */
    .sig { min-width: 80px; border-bottom: 1px solid #9ca3af; height: 18px; }

    /* Total row */
    .total-row td { font-weight: 700; font-size: 9px; border-top: 2px solid #1d4ed8; padding-top: 6px; }

    /* Footer */
    .footer { display: flex; justify-content: space-between; font-size: 7.5px; color: #9ca3af; border-top: 1px solid #e5e7eb; padding-top: 8px; margin-top: 4px; }
</style>
</head>
<body>
<div class="page">

    <div class="header">
        <div>
            <div class="org-name">{{ $org?->name ?? 'ELM' }}</div>
        </div>
        <div class="doc-title">
            <div class="doc-type">Liste des dépenses{{ $site_nom ? ' — '.$site_nom : '' }}</div>
            <div class="doc-date">Imprimé le {{ $generated_at->format('d/m/Y à H:i') }} par {{ $printed_by }}</div>
        </div>
    </div>

    <div class="meta">
        @if(!empty($filters['date_debut']) || !empty($filters['date_fin']))
            <div>Période :
                <span>
                    {{ !empty($filters['date_debut']) ? \Carbon\Carbon::parse($filters['date_debut'])->format('d/m/Y') : '—' }}
                    →
                    {{ !empty($filters['date_fin']) ? \Carbon\Carbon::parse($filters['date_fin'])->format('d/m/Y') : '—' }}
                </span>
            </div>
        @endif
        @if(!empty($filters['statut']))
            <div>Statut : <span>{{ $filters['statut'] }}</span></div>
        @endif
        @if(!empty($filters['search']))
            <div>Recherche : <span>{{ $filters['search'] }}</span></div>
        @endif
        <div>Total : <span>{{ number_format($rows->count(), 0, ',', ' ') }} dépense(s)</span></div>
    </div>

    <table>
        <thead>
            <tr>
                <th>Date</th>
                <th>Type de dépense</th>
                <th>Concerné</th>
                <th>Véhicule</th>
                <th class="right">Montant (GNF)</th>
                <th>Statut</th>
                <th>Saisi par</th>
                <th>Signature</th>
            </tr>
        </thead>
        <tbody>
            @forelse($rows as $row)
            <tr>
                <td class="mono">{{ \Carbon\Carbon::parse($row['date_depense'])->format('d/m/Y') }}</td>
                <td>
                    {{ $row['type']['libelle'] ?? '—' }}
                    @if(!empty($row['type']['categorie_label']))
                        <br><small style="color:#6b7280">{{ $row['type']['categorie_label'] }}</small>
                    @endif
                </td>
                <td>{{ $row['beneficiaire_label'] ?? '—' }}</td>
                <td>{{ $row['vehicule_nom'] ?? '' }}</td>
                <td class="right">{{ number_format((float)$row['montant'], 0, ',', ' ') }}</td>
                <td>
                    @php $s = $row['statut'] ?? '' @endphp
                    <span class="badge badge-{{ $s }}">{{ $row['statut_label'] ?? $s }}</span>
                </td>
                <td>{{ $row['user']['name'] ?? '—' }}</td>
                <td class="sig"></td>
            </tr>
            @empty
            <tr>
                <td colspan="8" style="text-align:center; color:#6b7280; padding: 20px;">Aucune dépense pour ces critères.</td>
            </tr>
            @endforelse

            @if($rows->count() > 0)
            <tr class="total-row">
                <td colspan="4" style="text-align:right; padding-right:8px; color:#374151;">Montant total :</td>
                <td class="right">{{ number_format($total, 0, ',', ' ') }} GNF</td>
                <td colspan="3"></td>
            </tr>
            @endif
        </tbody>
    </table>

    <div class="footer">
        <span>{{ $org?->name ?? 'ELM' }} — Document confidentiel</span>
        <span>{{ $generated_at->format('d/m/Y H:i') }}</span>
    </div>

</div>
</body>
</html>
