<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>Impression — Liste des dépenses</title>
<style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: Arial, Helvetica, sans-serif; font-size: 10px; color: #000; background: #fff; }

    /* ── Barre d'outils (écran uniquement) ─────────────────────────── */
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

    /* ── Conteneur page ────────────────────────────────────────────── */
    .page { width: 277mm; margin: 0 auto; padding: 10mm 14mm 18mm; }

    /* ── Section par site ──────────────────────────────────────────── */
    .site-section { margin-bottom: 32px; }
    .site-section.new-page { page-break-before: always; break-before: page; }

    /* ── En-tête ───────────────────────────────────────────────────── */
    .header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        margin-bottom: 10px;
        border-bottom: 2px solid #000;
        padding-bottom: 8px;
    }
    .org-name  { font-size: 14px; font-weight: 700; color: #000; }
    .doc-title { text-align: right; }
    .doc-type  { font-size: 12px; font-weight: 700; color: #000; text-transform: uppercase; }
    .doc-date  { font-size: 8px; color: #444; margin-top: 3px; }

    /* ── Méta-infos ────────────────────────────────────────────────── */
    .meta { display: flex; flex-wrap: wrap; gap: 16px; margin-bottom: 8px; font-size: 8px; color: #333; }
    .meta span { font-weight: 700; color: #000; }

    /* ── Tableau ───────────────────────────────────────────────────── */
    table { width: 100%; border-collapse: collapse; margin-bottom: 10px; table-layout: fixed; }

    thead th {
        background: #d0d0d0;
        border: 1px solid #000;
        padding: 6px 5px;
        text-align: left;
        font-size: 8px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.3px;
        color: #000;
        white-space: nowrap;
    }
    thead th.right  { text-align: right; }
    thead th.center { text-align: center; }

    tbody tr:nth-child(even) { background: #f2f2f2; }
    tbody td {
        border: 1px solid #bbb;
        padding: 5px 5px;
        font-size: 8.5px;
        vertical-align: top;
        color: #000;
    }
    tbody td.right  { text-align: right; font-family: 'Courier New', monospace; }
    tbody td.center { text-align: center; }
    tbody td.mono   { font-family: 'Courier New', monospace; }


    /* ── Ligne total ─────────────────────────────────────────────────*/
    .total-row td {
        background: #d0d0d0 !important;
        font-weight: 700;
        font-size: 9px;
        border-top: 2px solid #000;
        border-bottom: 2px solid #000;
        border-left: 1px solid #000;
        border-right: 1px solid #000;
        padding: 5px;
    }
    .total-row td.right { text-align: right; }

    /* ── Pied de page ────────────────────────────────────────────────*/
    .footer {
        display: flex;
        justify-content: space-between;
        font-size: 7.5px;
        color: #333;
        border-top: 1px solid #888;
        padding-top: 5px;
        margin-top: 6px;
    }

    /* ── Règles impression ───────────────────────────────────────────*/
    @media print {
        @page { size: A4 landscape; margin: 12mm 14mm 18mm; }

        body { margin: 0; background: #fff; }
        .print-bar { display: none !important; }
        .page { width: 100%; margin: 0; padding: 0; }

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

        /* Forcer l'impression des fonds gris ─ */
        thead th { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        tbody tr:nth-child(even) { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        .total-row td { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        .badge { -webkit-print-color-adjust: exact; print-color-adjust: exact; }

        /* Numéros de page via CSS counters */
        .footer-page::after {
            content: "Page " counter(page) " / " counter(pages);
        }
    }
</style>
</head>
<body>

<div class="print-bar">
    <button class="btn" onclick="window.close()">Fermer</button>
    <button class="btn btn-primary" onclick="window.print()">&#128438; Imprimer</button>
</div>

<div class="page">
    @forelse($sites as $siteData)
    <div class="site-section{{ !$loop->first ? ' new-page' : '' }}">

        <div class="header">
            <div>
                <div class="org-name">{!! $org?->name ?? 'ELM' !!}</div>
            </div>
            <div class="doc-title">
                <div class="doc-type">Liste des dépenses{{ $siteData['site_nom'] ? ' — '.$siteData['site_nom'] : '' }}</div>
                <div class="doc-date">Imprimé le {{ $generated_at->format('d/m/Y à H:i') }} par {!! $printed_by !!}</div>
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
                    <th style="width:9%">Date</th>
                    <th style="width:20%">Type de dépense</th>
                    <th style="width:15%">Concerné</th>
                    <th style="width:12%">Véhicule</th>
                    <th class="right" style="width:10%">Montant (GNF)</th>
                    <th style="width:9%">Statut</th>
                    <th style="width:12%">Saisi par</th>
                    <th style="width:13%">Signature</th>
                </tr>
            </thead>
            <tbody>
                @forelse($siteData['rows'] as $row)
                <tr>
                    <td class="mono">{{ \Carbon\Carbon::parse($row['date_depense'])->format('d/m/Y') }}</td>
                    <td>
                        {{ $row['type']['libelle'] ?? '—' }}
                        @if(!empty($row['type']['categorie_label']))
                        <br><small style="color:#555; font-size:7px;">{{ $row['type']['categorie_label'] }}</small>
                        @endif
                    </td>
                    <td>{{ $row['beneficiaire_label'] ?? '—' }}</td>
                    <td>{{ $row['vehicule_nom'] ?? '—' }}</td>
                    <td class="right">{{ number_format((float)$row['montant'], 0, ',', ' ') }}</td>
                    <td>{{ $row['statut_label'] ?? ($row['statut'] ?? '—') }}</td>
                    <td>{!! $row['user']['name'] ?? '—' !!}</td>
                    <td></td>
                </tr>
                @empty
                <tr>
                    <td colspan="8" style="text-align:center; color:#444; padding: 16px;">
                        Aucune dépense pour ces critères.
                    </td>
                </tr>
                @endforelse

                @if($siteData['rows']->count() > 0)
                <tr class="total-row">
                    <td colspan="4" style="text-align:right; padding-right:6px; font-size:8.5px;">MONTANT TOTAL :</td>
                    <td class="right">{{ number_format($siteData['total'], 0, ',', ' ') }} GNF</td>
                    <td colspan="3"></td>
                </tr>
                @endif
            </tbody>
        </table>

        <div class="footer">
            <span>{!! $org?->name ?? 'ELM' !!} — Document confidentiel</span>
            <span class="footer-page"></span>
            <span>{{ $generated_at->format('d/m/Y H:i') }}{{ $siteData['site_nom'] ? ' — '.$siteData['site_nom'] : '' }}</span>
        </div>

    </div>
    @empty
    <table>
        <thead>
            <tr>
                <th style="width:9%">Date</th>
                <th style="width:20%">Type de dépense</th>
                <th style="width:15%">Concerné</th>
                <th style="width:12%">Véhicule</th>
                <th class="right" style="width:10%">Montant (GNF)</th>
                <th style="width:9%">Statut</th>
                <th style="width:12%">Saisi par</th>
                <th style="width:13%">Signature</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td colspan="8" style="text-align:center; color:#444; padding:20px;">
                    Aucune dépense pour ces critères.
                </td>
            </tr>
        </tbody>
    </table>
    @endforelse
</div>

<script>
    window.onload = function () { window.print(); };
</script>
</body>
</html>
