<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>Impression — Liste des dépenses</title>
<style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: Arial, Helvetica, sans-serif; font-size: 10px; color: #1a1a1a; background: #fff; }

    /* Barre d'outils écran uniquement */
    .print-bar {
        display: flex;
        justify-content: flex-end;
        align-items: center;
        gap: 8px;
        padding: 10px 16px;
        background: #f1f5f9;
        border-bottom: 1px solid #cbd5e1;
        font-size: 13px;
    }
    .btn {
        padding: 6px 16px;
        border-radius: 6px;
        font-size: 12px;
        font-weight: 500;
        cursor: pointer;
        border: 1px solid #d1d5db;
        background: #fff;
        color: #374151;
    }
    .btn-primary { background: #1d4ed8; color: #fff; border-color: #1d4ed8; }
    .btn-primary:hover { background: #1e40af; }

    /* Page A4 */
    .page { width: 210mm; margin: 0 auto; padding: 12mm 14mm; }

    /* Section par site */
    .site-section { margin-bottom: 32px; }

    /* Saut de page forcé avant chaque site sauf le premier */
    .site-section.new-page {
        page-break-before: always;
        break-before: page;
    }

    /* En-tête */
    .header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        margin-bottom: 16px;
        border-bottom: 2px solid #1d4ed8;
        padding-bottom: 12px;
    }
    .org-name { font-size: 15px; font-weight: 700; color: #1e3a8a; }
    .doc-title { text-align: right; }
    .doc-type { font-size: 12px; font-weight: 700; color: #1e3a8a; text-transform: uppercase; }
    .doc-date { font-size: 8.5px; color: #6b7280; margin-top: 4px; }

    /* Méta-infos filtres */
    .meta { display: flex; flex-wrap: wrap; gap: 20px; margin-bottom: 12px; font-size: 8.5px; color: #374151; }
    .meta span { font-weight: 600; color: #111827; }

    /* Tableau */
    table { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
    thead tr { background: #1e40af; }
    thead th {
        padding: 6px 7px;
        text-align: left;
        font-size: 7.5px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        color: #fff;
        white-space: nowrap;
    }
    thead th.right { text-align: right; }
    tbody tr:nth-child(even) { background: #f8fafc; }
    tbody tr { border-bottom: 1px solid #e5e7eb; }
    tbody td { padding: 5px 7px; font-size: 8.5px; vertical-align: top; }
    tbody td.right { text-align: right; font-family: 'Courier New', monospace; }
    tbody td.mono { font-family: 'Courier New', monospace; }

    /* Badges statut */
    .badge { display: inline-block; padding: 1px 6px; border-radius: 99px; font-size: 7px; font-weight: 700; text-transform: uppercase; }
    .badge-brouillon { background: #f1f5f9; color: #475569; }
    .badge-soumis    { background: #dbeafe; color: #1d4ed8; }
    .badge-valide    { background: #d1fae5; color: #065f46; }
    .badge-rejete    { background: #ffedd5; color: #c2410c; }
    .badge-annule    { background: #fee2e2; color: #b91c1c; }

    /* Colonne Signature */
    .sig { min-width: 70px; border-bottom: 1px solid #9ca3af; height: 20px; }

    /* Ligne total */
    .total-row td { font-weight: 700; font-size: 9px; border-top: 2px solid #1d4ed8; padding-top: 6px; }

    /* Pied de page */
    .footer {
        display: flex;
        justify-content: space-between;
        font-size: 8px;
        color: #9ca3af;
        border-top: 1px solid #e5e7eb;
        padding-top: 8px;
        margin-top: 4px;
    }

    /* Règles impression */
    @media print {
        @page { size: A4 portrait; margin: 12mm 14mm; }

        body { margin: 0; background: #fff; }
        .print-bar { display: none !important; }
        .page { width: 100%; margin: 0; padding: 0; }

        /* Saut de page obligatoire entre sites :
           - page-break-after sur chaque section (sauf dernière)
           - page-break-before sur les sections non-premières (.new-page)
           Double mécanisme pour fiabilité maximale cross-browser */
        .site-section {
            page-break-after: always;
            break-after: page;
            display: block;
        }
        .site-section:last-child {
            page-break-after: avoid;
            break-after: avoid;
        }
        .site-section.new-page {
            page-break-before: always;
            break-before: page;
        }

        thead { display: table-header-group; }
        tbody tr { page-break-inside: avoid; break-inside: avoid; }
    }
</style>
</head>
<body>

<div class="print-bar">
    <button class="btn" onclick="window.close()">Fermer</button>
    <button class="btn btn-primary" onclick="window.print()">&#128438; Imprimer</button>
</div>

<div class="page">
    @foreach($sites as $siteData)
    <div class="site-section{{ !$loop->first ? ' new-page' : '' }}">

        <div class="header">
            <div>
                <div class="org-name">{{ $org?->name ?? 'ELM' }}</div>
            </div>
            <div class="doc-title">
                <div class="doc-type">Liste des dépenses{{ $siteData['site_nom'] ? ' — '.$siteData['site_nom'] : '' }}</div>
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
            <div>Total : <span>{{ $siteData['rows']->count() }} dépense(s)</span></div>
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
                @forelse($siteData['rows'] as $row)
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
                    <td colspan="8" style="text-align:center; color:#6b7280; padding: 20px;">
                        Aucune dépense pour ces critères.
                    </td>
                </tr>
                @endforelse

                @if($siteData['rows']->count() > 0)
                <tr class="total-row">
                    <td colspan="4" style="text-align:right; padding-right:8px; color:#374151;">Montant total :</td>
                    <td class="right">{{ number_format($siteData['total'], 0, ',', ' ') }} GNF</td>
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
    @endforeach
</div>

<script>
    window.onload = function () { window.print(); };
</script>
</body>
</html>
