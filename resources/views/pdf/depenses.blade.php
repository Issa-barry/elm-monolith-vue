<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8" />
<title>Liste des dépenses{{ $site_nom ? ' — '.$site_nom : '' }}</title>
<style>
@page {
    size: A4 landscape;
    margin: 14mm 15mm 22mm 15mm;
}

* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: DejaVu Sans, sans-serif; font-size: 9pt; color: #000; background: #fff; }

/* ── Pied de page fixe (répété sur toutes les pages) ──────────────── */
.page-footer {
    position: fixed;
    bottom: 0;
    left: 0;
    right: 0;
    height: 16pt;
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 7pt;
    color: #333;
    border-top: 0.75pt solid #888;
    padding-top: 3pt;
}
.footer-center { text-align: center; flex: 1; }
.page-num:before   { content: counter(page); }
.page-total:before { content: counter(pages); }

/* ── Page ─────────────────────────────────────────────────────────── */
.page { padding: 0; }

/* ── En-tête ──────────────────────────────────────────────────────── */
.header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 10pt;
    border-bottom: 2pt solid #000;
    padding-bottom: 8pt;
}
.org-name  { font-size: 14pt; font-weight: 700; color: #000; }
.doc-title { text-align: right; }
.doc-type  { font-size: 11pt; font-weight: 700; color: #000; text-transform: uppercase; }
.doc-date  { font-size: 7.5pt; color: #444; margin-top: 3pt; }

/* ── Méta-infos ───────────────────────────────────────────────────── */
.meta { display: flex; flex-wrap: wrap; gap: 16pt; margin-bottom: 8pt; font-size: 8pt; color: #333; }
.meta span { font-weight: 700; color: #000; }

/* ── Tableau ──────────────────────────────────────────────────────── */
table { width: 100%; border-collapse: collapse; margin-bottom: 10pt; table-layout: fixed; }
thead { display: table-header-group; }

thead th {
    background: #d0d0d0;
    border: 0.75pt solid #000;
    padding: 5pt 4pt;
    font-size: 8pt;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.2pt;
    color: #000;
    white-space: nowrap;
}
thead th.right  { text-align: right; }
thead th.center { text-align: center; }

tbody td {
    border: 0.75pt solid #bbb;
    padding: 4pt 4pt;
    font-size: 8.5pt;
    vertical-align: top;
    color: #000;
}
tbody tr:nth-child(even) { background: #f2f2f2; }
tbody td.right  { text-align: right; font-family: monospace; }
tbody td.center { text-align: center; }
tbody td.mono   { font-family: monospace; }

/* Largeurs colonnes (A4 paysage ≈ 267mm utilisables) */
.col-date  { width: 9%;  }
.col-type  { width: 20%; }
.col-conc  { width: 15%; }
.col-veh   { width: 12%; }
.col-mnt   { width: 10%; }
.col-sta   { width: 9%;  }
.col-saisi { width: 12%; }
.col-valid { width: 13%; }


/* ── Ligne total ──────────────────────────────────────────────────── */
.total-row td {
    background: #d0d0d0 !important;
    font-weight: 700;
    font-size: 9pt;
    border-top: 1.5pt solid #000;
    border-bottom: 1.5pt solid #000;
    border-left: 0.75pt solid #000;
    border-right: 0.75pt solid #000;
    padding: 4pt 4pt;
}
.total-row td.right { text-align: right; }
</style>
</head>
<body>

{{-- Pied de page global fixe --}}
<div class="page-footer">
    <span>{!! $org?->name ?? 'ELM' !!} — Document confidentiel</span>
    <span class="footer-center">Page <span class="page-num"></span> / <span class="page-total"></span></span>
    <span>{!! $site_nom ?? ($org?->name ?? 'ELM') !!}</span>
</div>

<div class="page">

    <div class="header">
        <div>
            <div class="org-name">{!! $org?->name ?? 'ELM' !!}</div>
        </div>
        <div class="doc-title">
            <div class="doc-type">Liste des dépenses{{ $site_nom ? ' — '.$site_nom : '' }}</div>
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
        <div>Total : <span>{{ number_format($rows->count(), 0, ',', ' ') }} dépense(s)</span></div>
    </div>

    <table>
        <thead>
            <tr>
                <th class="col-date">Date</th>
                <th class="col-type">Type de dépense</th>
                <th class="col-conc">Concerné</th>
                <th class="col-veh">Véhicule</th>
                <th class="col-mnt right">Montant (GNF)</th>
                <th class="col-sta">Statut</th>
                <th class="col-saisi">Saisi par</th>
                <th class="col-valid">Signature</th>
            </tr>
        </thead>
        <tbody>
            @forelse($rows as $row)
            <tr>
                <td class="col-date mono">{{ \Carbon\Carbon::parse($row['date_depense'])->format('d/m/Y') }}</td>
                <td class="col-type">
                    {{ $row['type']['libelle'] ?? '—' }}
                    @if(!empty($row['type']['categorie_label']))
                        <br><small style="color:#555; font-size:7pt;">{{ $row['type']['categorie_label'] }}</small>
                    @endif
                </td>
                <td class="col-conc">{{ $row['beneficiaire_label'] ?? '—' }}</td>
                <td class="col-veh">{{ $row['vehicule_nom'] ?? '—' }}</td>
                <td class="col-mnt right">{{ number_format((float)$row['montant'], 0, ',', ' ') }}</td>
                <td class="col-sta">{{ $row['statut_label'] ?? ($row['statut'] ?? '—') }}</td>
                <td class="col-saisi">{!! $row['user']['name'] ?? '—' !!}</td>
                <td class="col-valid"></td>
            </tr>
            @empty
            <tr>
                <td colspan="8" style="text-align:center; color:#444; padding: 14pt;">Aucune dépense pour ces critères.</td>
            </tr>
            @endforelse

            @if($rows->count() > 0)
            <tr class="total-row">
                <td colspan="4" style="text-align:right; padding-right:5pt; font-size:8.5pt;">MONTANT TOTAL :</td>
                <td class="right">{{ number_format($total, 0, ',', ' ') }} GNF</td>
                <td colspan="3"></td>
            </tr>
            @endif
        </tbody>
    </table>

</div>
</body>
</html>
