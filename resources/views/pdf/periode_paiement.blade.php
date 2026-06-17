<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8" />
<title>Période {{ $periode->reference }}</title>
<style>
@page {
    size: A4 landscape;
    margin: 14mm 15mm 22mm 15mm;
}

* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: DejaVu Sans, sans-serif; font-size: 9pt; color: #000; background: #fff; }

/* ── Pied de page fixe (toutes les pages) ──────────────────── */
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
    color: #444;
    border-top: 0.75pt solid #888;
    padding-top: 3pt;
}
.footer-center { text-align: center; flex: 1; }
.page-num:before   { content: counter(page); }
.page-total:before { content: counter(pages); }

/* ── En-tête commun ────────────────────────────────────────── */
.header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 10pt;
    border-bottom: 2pt solid #000;
    padding-bottom: 8pt;
}
.org-name { font-size: 15pt; font-weight: 700; }
.org-sub  { font-size: 8pt; color: #444; margin-top: 3pt; }
.doc-title { text-align: right; }
.doc-type  { font-size: 12pt; font-weight: 700; text-transform: uppercase; }
.doc-ref   { font-size: 9pt; font-family: monospace; margin-top: 3pt; }
.doc-date  { font-size: 7.5pt; color: #555; margin-top: 3pt; }

/* ── Méta-infos période ────────────────────────────────────── */
.meta-grid {
    display: flex;
    flex-wrap: wrap;
    gap: 4pt 24pt;
    margin-bottom: 10pt;
    font-size: 8.5pt;
    background: #f4f4f4;
    border: 0.75pt solid #ccc;
    padding: 6pt 8pt;
}
.meta-item b { color: #000; }

/* ── KPIs ──────────────────────────────────────────────────── */
.kpi-table { width: 100%; border-collapse: collapse; margin-bottom: 12pt; }
.kpi-table td {
    width: 16.66%;
    border: 0.75pt solid #bbb;
    padding: 5pt 6pt;
    text-align: center;
    vertical-align: top;
}
.kpi-label { font-size: 7.5pt; color: #555; }
.kpi-value { font-size: 11pt; font-weight: 700; margin-top: 2pt; }

/* ── Tableaux communs ──────────────────────────────────────── */
table.data { width: 100%; border-collapse: collapse; margin-bottom: 12pt; table-layout: fixed; }
thead { display: table-header-group; }
thead th {
    background: #d0d0d0;
    border: 0.75pt solid #000;
    padding: 5pt 4pt;
    font-size: 7.5pt;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.2pt;
    color: #000;
}
thead th.right  { text-align: right; }
thead th.center { text-align: center; }

tbody td {
    border: 0.75pt solid #bbb;
    padding: 4pt 4pt;
    font-size: 8pt;
    vertical-align: middle;
    color: #000;
}
tbody tr:nth-child(even) { background: #f5f5f5; }
tbody td.right  { text-align: right; font-family: monospace; }
tbody td.mono   { font-family: monospace; font-size: 7.5pt; }
tbody td.center { text-align: center; }

/* ── Ligne total ───────────────────────────────────────────── */
.total-row td {
    background: #d0d0d0 !important;
    font-weight: 700;
    font-size: 8.5pt;
    border: 0.75pt solid #000;
    border-top: 1.5pt solid #000;
    border-bottom: 1.5pt solid #000;
    padding: 4pt 4pt;
}
.total-row td.right { text-align: right; }

/* ── Colonnes tableau récapitulatif (paysage ≈ 267mm) ──────── */
.r-num  { width: 3%;  }
.r-ben  { width: 19%; }
.r-ref  { width: 23%; }
.r-age  { width: 12%; }
.r-gain { width: 11%; }
.r-ded  { width: 11%; }
.r-net  { width: 11%; }
.r-sta  { width: 10%; }

/* ── Colonnes tableau émargement (paysage ≈ 267mm) ─────────── */
.e-num  { width: 4%;  }
.e-ben  { width: 20%; }
.e-ref  { width: 23%; }
.e-age  { width: 12%; }
.e-mnt  { width: 13%; }
.e-date { width: 12%; }
/* .e-sig : le reste ≈ 16% */

/* ── Section agences ───────────────────────────────────────── */
.section-titre {
    font-size: 9pt;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5pt;
    margin-bottom: 6pt;
    padding-bottom: 4pt;
    border-bottom: 1pt solid #000;
}

/* ── Page émargement ───────────────────────────────────────── */
.emargement-page { page-break-before: always; }

.emargement-titre {
    font-size: 14pt;
    font-weight: 700;
    text-transform: uppercase;
    text-align: center;
    letter-spacing: 1pt;
    margin-bottom: 8pt;
    border-bottom: 2pt solid #000;
    padding-bottom: 6pt;
}
.emargement-sous-titre {
    font-size: 8.5pt;
    text-align: center;
    color: #444;
    margin-bottom: 12pt;
}
</style>
</head>
<body>

{{-- Pied de page fixe --}}
<div class="page-footer">
    <span>{{ $org?->name ?? 'ELM' }} — Confidentiel</span>
    <span class="footer-center">Page <span class="page-num"></span> / <span class="page-total"></span></span>
    <span>Imprimé le {{ $generated_at->format('d/m/Y à H:i') }} par {{ $printed_by }}</span>
</div>

{{-- ══════════════════════════════════════════════════════════ --}}
{{-- PAGE 1 : RÉCAPITULATIF DE PÉRIODE --}}
{{-- ══════════════════════════════════════════════════════════ --}}
<div class="header">
    <div>
        <div class="org-name">{{ $org?->name ?? 'ELM' }}</div>
        @if ($periode->site)
            <div class="org-sub">Agence : {{ $periode->site->nom }}</div>
        @endif
    </div>
    <div class="doc-title">
        <div class="doc-type">Récapitulatif de période</div>
        <div class="doc-ref">{{ $periode->reference }}</div>
        <div class="doc-date">Imprimé le {{ $generated_at->format('d/m/Y à H:i') }} par {{ $printed_by }}</div>
    </div>
</div>

<div class="meta-grid">
    <div class="meta-item"><b>Type :</b> {{ $periode->type?->label() }}</div>
    <div class="meta-item"><b>Du :</b> {{ $periode->date_debut?->format('d/m/Y') ?? '—' }}</div>
    <div class="meta-item"><b>Au :</b> {{ $periode->date_fin?->format('d/m/Y') ?? '—' }}</div>
    <div class="meta-item"><b>Statut :</b> {{ $periode->statut?->label() }}</div>
    @if ($periode->site)
        <div class="meta-item"><b>Agence :</b> {{ $periode->site->nom }}</div>
    @endif
    <div class="meta-item"><b>Bénéficiaires :</b> {{ $stats['nb_beneficiaires'] }}</div>
</div>

<table class="kpi-table">
    <tr>
        <td>
            <div class="kpi-label">Total brut</div>
            <div class="kpi-value">{{ number_format($stats['total_brut'], 0, ',', ' ') }} GNF</div>
        </td>
        <td>
            <div class="kpi-label">Total déductions</div>
            <div class="kpi-value">{{ number_format($stats['total_deductions'], 0, ',', ' ') }} GNF</div>
        </td>
        <td>
            <div class="kpi-label">Net à payer</div>
            <div class="kpi-value">{{ number_format($stats['total_net'], 0, ',', ' ') }} GNF</div>
        </td>
        <td>
            <div class="kpi-label">Montant payé</div>
            <div class="kpi-value">{{ number_format($stats['total_paye'], 0, ',', ' ') }} GNF</div>
        </td>
        <td>
            <div class="kpi-label">Reste à payer</div>
            <div class="kpi-value">{{ number_format(max(0, $stats['reste']), 0, ',', ' ') }} GNF</div>
        </td>
        <td>
            <div class="kpi-label">Bénéficiaires</div>
            <div class="kpi-value">{{ $stats['nb_beneficiaires'] }}</div>
        </td>
    </tr>
</table>

<table class="data">
    <thead>
        <tr>
            <th class="r-num center">N°</th>
            <th class="r-ben">Bénéficiaire</th>
            <th class="r-ref">Référence fiche</th>
            <th class="r-age">Agence</th>
            <th class="r-gain right">Gains (GNF)</th>
            <th class="r-ded right">Déductions</th>
            <th class="r-net right">Net (GNF)</th>
            <th class="r-sta center">Statut</th>
        </tr>
    </thead>
    <tbody>
        @forelse ($fiches as $i => $fiche)
        <tr>
            <td class="r-num center" style="color:#666;">{{ $i + 1 }}</td>
            <td class="r-ben">{{ $fiche->beneficiaire_nom }}</td>
            <td class="r-ref mono">{{ $fiche->reference }}</td>
            <td class="r-age">{{ $fiche->site?->nom ?? '—' }}</td>
            <td class="r-gain right">{{ number_format((float) $fiche->montant_brut, 0, ',', ' ') }}</td>
            <td class="r-ded right">{{ $fiche->total_deductions > 0 ? number_format((float) $fiche->total_deductions, 0, ',', ' ') : '—' }}</td>
            <td class="r-net right">{{ number_format((float) $fiche->montant_net, 0, ',', ' ') }}</td>
            <td class="r-sta center" style="font-size:7.5pt;">{{ $fiche->statut?->label() }}</td>
        </tr>
        @empty
        <tr>
            <td colspan="8" style="text-align:center; padding:14pt; color:#555;">Aucune fiche générée pour cette période.</td>
        </tr>
        @endforelse

        @if ($fiches->count() > 0)
        <tr class="total-row">
            <td colspan="4" style="text-align:right; padding-right:6pt; font-size:8pt;">TOTAUX :</td>
            <td class="right">{{ number_format($stats['total_brut'], 0, ',', ' ') }} GNF</td>
            <td class="right">{{ number_format($stats['total_deductions'], 0, ',', ' ') }} GNF</td>
            <td class="right">{{ number_format($stats['total_net'], 0, ',', ' ') }} GNF</td>
            <td></td>
        </tr>
        @endif
    </tbody>
</table>

{{-- ══════════════════════════════════════════════════════════ --}}
{{-- SECTION : RÉPARTITION PAR AGENCE --}}
{{-- ══════════════════════════════════════════════════════════ --}}
@if ($repartitionAgences->count() > 0)
<div class="section-titre">Répartition par agence</div>

<table class="data">
    <thead>
        <tr>
            <th style="width:25%;">Agence</th>
            <th class="center" style="width:10%;">Bénéficiaires</th>
            <th class="right" style="width:14%;">Montant brut</th>
            <th class="right" style="width:13%;">Déductions</th>
            <th class="right" style="width:14%;">Net à payer</th>
            <th class="right" style="width:12%;">Déjà payé</th>
            <th class="right" style="width:12%;">Reste à payer</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($repartitionAgences as $agence)
        <tr>
            <td style="font-weight:600;">{{ $agence['site_nom'] }}</td>
            <td class="center">{{ $agence['nb_beneficiaires'] }}</td>
            <td class="right">{{ number_format($agence['montant_brut'], 0, ',', ' ') }} GNF</td>
            <td class="right">{{ $agence['total_deductions'] > 0 ? number_format($agence['total_deductions'], 0, ',', ' ').' GNF' : '—' }}</td>
            <td class="right" style="font-weight:700;">{{ number_format($agence['montant_net'], 0, ',', ' ') }} GNF</td>
            <td class="right">{{ number_format($agence['montant_paye'], 0, ',', ' ') }} GNF</td>
            <td class="right">{{ number_format($agence['reste'], 0, ',', ' ') }} GNF</td>
        </tr>
        @endforeach

        <tr class="total-row">
            <td style="font-weight:700;">TOTAL</td>
            <td class="center">{{ $stats['nb_beneficiaires'] }}</td>
            <td class="right">{{ number_format($stats['total_brut'], 0, ',', ' ') }} GNF</td>
            <td class="right">{{ number_format($stats['total_deductions'], 0, ',', ' ') }} GNF</td>
            <td class="right">{{ number_format($stats['total_net'], 0, ',', ' ') }} GNF</td>
            <td class="right">{{ number_format($stats['total_paye'], 0, ',', ' ') }} GNF</td>
            <td class="right">{{ number_format(max(0, $stats['reste']), 0, ',', ' ') }} GNF</td>
        </tr>
    </tbody>
</table>
@endif

{{-- ══════════════════════════════════════════════════════════ --}}
{{-- PAGE 2+ : FEUILLE D'ÉMARGEMENT --}}
{{-- ══════════════════════════════════════════════════════════ --}}
@if ($fiches->count() > 0)
<div class="emargement-page">

    <div class="emargement-titre">Feuille d'émargement</div>
    <div class="emargement-sous-titre">
        {{ $periode->type?->label() }} — Période {{ $periode->reference }}
        — du {{ $periode->date_debut?->format('d/m/Y') }} au {{ $periode->date_fin?->format('d/m/Y') }}
        @if ($periode->site) — Agence {{ $periode->site->nom }} @endif
    </div>

    <table class="data">
        <thead>
            <tr>
                <th class="e-num center">N°</th>
                <th class="e-ben">Bénéficiaire</th>
                <th class="e-ref">Référence fiche</th>
                <th class="e-age">Agence</th>
                <th class="e-mnt right">Montant à payer</th>
                <th class="e-date center">Date paiement</th>
                <th>Signature</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($fiches as $i => $fiche)
            <tr style="height: 22pt;">
                <td class="e-num center" style="color:#666;">{{ $i + 1 }}</td>
                <td class="e-ben">{{ $fiche->beneficiaire_nom }}</td>
                <td class="e-ref mono">{{ $fiche->reference }}</td>
                <td class="e-age">{{ $fiche->site?->nom ?? '—' }}</td>
                <td class="e-mnt right">{{ number_format((float) $fiche->montant_net, 0, ',', ' ') }} GNF</td>
                <td class="e-date">&nbsp;</td>
                <td>&nbsp;</td>
            </tr>
            @endforeach

            <tr class="total-row">
                <td colspan="4" style="text-align:right; padding-right:6pt; font-size:8pt;">TOTAL GÉNÉRAL :</td>
                <td class="right">{{ number_format($stats['total_net'], 0, ',', ' ') }} GNF</td>
                <td colspan="2"></td>
            </tr>
        </tbody>
    </table>

</div>
@endif

</body>
</html>
