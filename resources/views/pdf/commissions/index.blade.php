<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8" />
<title>{{ $title }}</title>
<style>
@page {
    margin: 15mm 15mm 22mm 15mm;
}

* { box-sizing: border-box; margin: 0; padding: 0; }

body {
    font-family: DejaVu Sans, Arial, Helvetica, sans-serif;
    font-size: 9pt;
    color: #000;
    background: #fff;
}

/* ── Pied de page fixe (répété sur toutes les pages) ─────────────── */
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

/* ── Bloc par agence ──────────────────────────────────────────────── */
/* Important : on ajoute la classe "new-page" explicitement en PHP (cf. boucle
   ci-dessous) plutôt que de s'appuyer sur :first-child. Le pied de page fixe
   est techniquement le premier enfant du <body>, donc ":first-child" ne
   matche jamais le premier ".site-page" et provoquait une page blanche en tête
   de document (saut de page forcé avant la toute première section). */
.site-page.new-page { page-break-before: always; }

/* ── Ligne meta (filtres + total) ─────────────────────────────────── */
.meta-row {
    display: flex;
    flex-wrap: wrap;
    gap: 4pt 16pt;
    margin-bottom: 8pt;
    padding-left: 6pt;
    font-size: 8pt;
    color: #333;
}
.meta-row b { color: #000; }

/* ── Véhicule(s) : nom puis immatriculation en dessous ────────────── */
.veh-item { margin-bottom: 3pt; }
.veh-item:last-child { margin-bottom: 0; }
.veh-immat { display: block; font-size: 7pt; color: #555; }

/* ── En-tête ──────────────────────────────────────────────────────── */
.header {
    display: flex;
    align-items: flex-start;
    gap: 8pt;
    margin-bottom: 8pt;
    padding-bottom: 7pt;
    border-bottom: 1.5pt solid #000;
}
.header-left { flex: 0 0 70pt; }
.logo-box {
    border: 0.75pt solid #666;
    padding: 4pt 5pt;
    text-align: center;
    font-size: 7pt;
    color: #444;
    min-height: 28pt;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    line-height: 1.4;
}
.logo-box .logo-label { font-size: 6pt; color: #888; }
.logo-box .logo-name  { font-size: 7.5pt; font-weight: 700; margin-top: 2pt; }

.header-center { flex: 1; text-align: center; padding-top: 2pt; }
.doc-type {
    font-size: 13pt;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.4pt;
}
.doc-sub { font-size: 9pt; margin-top: 3pt; color: #333; }

.header-right {
    flex: 0 0 125pt;
    font-size: 7.5pt;
    text-align: right;
    line-height: 1.7;
    padding-right: 6pt;
}
.header-right strong { font-weight: 700; }

/* ── Tableau ──────────────────────────────────────────────────────── */
table {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 0;
    table-layout: fixed;
}

thead { display: table-header-group; }

thead th {
    background: #d0d0d0;
    border: 0.75pt solid #000;
    padding: 4pt 6pt;
    font-size: 7pt;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.2pt;
    color: #000;
    vertical-align: bottom;
    white-space: normal;
}
thead th.right  { text-align: right; padding-right: 8pt; }
thead th.center { text-align: center; }

tbody td {
    border: 0.75pt solid #bbb;
    padding: 3.5pt 6pt;
    font-size: 8.5pt;
    vertical-align: top;
}
tbody tr:nth-child(even) { background: #f0f0f0; }
tbody td.right  { text-align: right; padding-right: 8pt; }
tbody td.center { text-align: center; }

/* Largeurs de colonnes fixes (A4 paysage ≈ 267mm utilisables avec marges 15mm) */
.col-ben  { width: 17%; }
.col-tel  { width: 9%; }
.col-veh  { width: 13%; }
.col-cum  { width: 10%; }
.col-fra  { width: 7%; }
.col-mot  { width: 10%; }
.col-pay  { width: 10%; }
.col-res  { width: 10%; }
.col-sta  { width: 7%; }
.col-sig  { width: 7%; }

/* ── Ligne de totaux ──────────────────────────────────────────────── */
.total-row td {
    background: #d0d0d0 !important;
    font-weight: 700;
    font-size: 9pt;
    border-top: 1.5pt solid #000;
    border-bottom: 1.5pt solid #000;
    border-left: 0.75pt solid #000;
    border-right: 0.75pt solid #000;
    padding: 4pt 6pt;
}
.total-row td.right { text-align: right; padding-right: 8pt; }
</style>
</head>
<body>

{{-- Pied de page global (position:fixed → présent sur chaque page physique) --}}
<div class="page-footer">
    <span>{{ $title }} – Document confidentiel</span>
    <span class="footer-center">Page <span class="page-num"></span> / <span class="page-total"></span></span>
    <span>{{ count($sites) === 1 && $sites[0]['site_nom'] ? 'Agence : '.$sites[0]['site_nom'] : ($org?->name ?? 'ELM') }}</span>
</div>

@foreach($sites as $siteData)

<div class="site-page{{ !$loop->first ? ' new-page' : '' }}">

    {{-- En-tête 3 zones --}}
    <div class="header">
        <div class="header-left">
            <div class="logo-box">
                <span class="logo-label">LOGO</span>
                <span class="logo-name">{{ strtoupper($org?->name ?? 'ELM') }}</span>
            </div>
        </div>

        <div class="header-center">
            <div class="doc-type">{{ $title }}</div>
            <div class="doc-sub">Rapport de commissions</div>
        </div>

        <div class="header-right">
            @if($siteData['site_nom'])
            <strong>Agence :</strong> {{ $siteData['site_nom'] }}<br>
            @endif
            <strong>Période :</strong> {{ $periode_label }}<br>
            <strong>Imprimé le :</strong> {{ $generated_at->format('d/m/Y H:i') }}<br>
            <strong>Imprimé par :</strong> {{ $printed_by }}
        </div>
    </div>

    {{-- Ligne meta : filtres appliqués + total --}}
    <div class="meta-row">
        @php
            $statutFiltreLabel = match ($filters['statut'] ?? '') {
                'impaye' => 'Impayée',
                'paye' => 'Payée',
                'partiel' => 'Partiellement payée',
                default => null,
            };
        @endphp
        @if($statutFiltreLabel)
        <div>Statut : <b>{{ $statutFiltreLabel }}</b></div>
        @endif
        @if(!empty($filters['search']))
        <div>Recherche : <b>{{ $filters['search'] }}</b></div>
        @endif
        <div>Total : <b>{{ count($siteData['rows']) }} bénéficiaire(s)</b></div>
    </div>

    {{-- Tableau --}}
    <table>
        <thead>
            <tr>
                <th class="col-ben">Bénéficiaire</th>
                <th class="col-tel center">Téléphone</th>
                <th class="col-veh">Véhicule(s)</th>
                <th class="col-cum right">Total cumulé (GNF)</th>
                <th class="col-fra right">Frais (GNF)</th>
                <th class="col-mot">Motif de frais</th>
                <th class="col-pay right">Déjà payé (GNF)</th>
                <th class="col-res right">Reste à payer (GNF)</th>
                <th class="col-sta center">Statut</th>
                <th class="col-sig center">Signature</th>
            </tr>
        </thead>
        <tbody>
            @forelse($siteData['rows'] as $row)
            <tr>
                <td class="col-ben"><strong>{{ $row['beneficiaire_nom'] }}</strong></td>
                <td class="col-tel center">{{ $row['telephone'] ?? '—' }}</td>
                <td class="col-veh">
                    @forelse($row['vehicules'] ?? [] as $vehicule)
                    <div class="veh-item">
                        {{ $vehicule['nom'] }}
                        @if($vehicule['immatriculation'])
                        <span class="veh-immat">{{ $vehicule['immatriculation'] }}</span>
                        @endif
                    </div>
                    @empty
                    —
                    @endforelse
                </td>
                <td class="col-cum right">{{ number_format((float) $row['total_cumule'], 0, ',', "\xc2\xa0") }}</td>
                <td class="col-fra right">{{ $row['frais'] > 0 ? number_format((float) $row['frais'], 0, ',', "\xc2\xa0") : '—' }}</td>
                <td class="col-mot">{{ $row['motifs_frais'] ?? '—' }}</td>
                <td class="col-pay right">{{ number_format((float) $row['deja_paye'], 0, ',', "\xc2\xa0") }}</td>
                <td class="col-res right">{{ $row['reste'] > 0 ? number_format((float) $row['reste'], 0, ',', "\xc2\xa0") : '—' }}</td>
                <td class="col-sta center">{{ $row['statut'] ?? '—' }}</td>
                <td class="col-sig"></td>
            </tr>
            @empty
            <tr>
                <td colspan="10" style="text-align:center; padding:12pt; color:#555;">Aucun résultat pour ces critères.</td>
            </tr>
            @endforelse

            @if(count($siteData['rows']) > 0)
            <tr class="total-row">
                <td colspan="3" style="text-align:right; padding-right:5pt; font-size:8.5pt;">TOTAUX :</td>
                <td class="right">{{ number_format((float) $siteData['totaux']['total_cumule'], 0, ',', "\xc2\xa0") }}</td>
                <td class="right">{{ $siteData['totaux']['total_frais'] > 0 ? number_format((float) $siteData['totaux']['total_frais'], 0, ',', "\xc2\xa0") : '—' }}</td>
                <td></td>
                <td class="right">{{ number_format((float) $siteData['totaux']['total_deja_paye'], 0, ',', "\xc2\xa0") }}</td>
                <td class="right">{{ number_format((float) $siteData['totaux']['total_reste'], 0, ',', "\xc2\xa0") }}</td>
                <td colspan="2"></td>
            </tr>
            @endif
        </tbody>
    </table>

</div>

@endforeach

</body>
</html>
