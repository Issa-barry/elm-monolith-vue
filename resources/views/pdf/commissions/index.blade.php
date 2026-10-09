<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8" />
<title>{{ $title }}</title>
<style>
/* Police du template Apollo Invoice, embarquee localement pour l'impression. */
@font-face {
    font-family: PoppinsPdf;
    font-style: normal;
    font-weight: 400;
    src: url("{{ str_replace('\\', '/', resource_path('fonts/poppins/Poppins-Regular.ttf')) }}") format('truetype');
}
@font-face {
    font-family: PoppinsPdf;
    font-style: normal;
    font-weight: 700;
    src: url("{{ str_replace('\\', '/', resource_path('fonts/poppins/Poppins-Bold.ttf')) }}") format('truetype');
}
@page {
    size: A4 landscape;
    /* Rapport compact : garder une marge imprimable tout en donnant plus
       de largeur au tableau. La marge basse reserve le pied de page. */
    margin: 10mm 12mm 32mm 12mm;
}

/* Ne pas appliquer margin: 0 au selecteur universel : Dompdf l'applique
   aussi a la page et annule les marges d'impression declarees ci-dessus. */
* { box-sizing: border-box; }

body {
    margin: 0;
    padding: 0;
    font-family: PoppinsPdf, DejaVu Sans, sans-serif;
    font-size: 8.5pt;
    line-height: 1.1;
    color: #334155;
    background: #fff;
    word-wrap: break-word;
    overflow-wrap: break-word;
}

/* ── Pied de page fixe (répété sur toutes les pages) ─────────────── */
/* Tableau plutôt que flexbox : le rendu flex de dompdf peut diverger entre
   l'aperçu écran et l'impression physique (un bloc à largeur fixe et non
   réductible peut déborder hors de la zone imprimable réelle de
   l'imprimante et disparaître à l'impression). Les tableaux sont le mode
   de mise en page le plus robuste et le mieux supporté par dompdf. */
.page-footer {
    position: fixed;
    height: 36pt;
    /* Dans la marge basse, hors de la zone reservee aux lignes du tableau. */
    bottom: -14mm;
    left: 0;
    right: 0;
    font-size: 7pt;
    color: #64748b;
    border-top: 0.5pt solid #e2e8f0;
}
.page-footer table { width: 100%; border-collapse: collapse; }
.page-footer td { border: none; padding: 3pt 0 0; font-size: 7pt; }
.footer-left { width: 40%; }
.footer-center { width: 10%; text-align: center; }
.footer-right { width: 50%; text-align: right; }

/* ── Bloc par agence ──────────────────────────────────────────────── */
/* Important : on ajoute la classe "new-page" explicitement en PHP (cf. boucle
   ci-dessous) plutôt que de s'appuyer sur :first-child. Le pied de page fixe
   est techniquement le premier enfant du <body>, donc ":first-child" ne
   matche jamais le premier ".site-page" et provoquait une page blanche en tête
   de document (saut de page forcé avant la toute première section). */
.site-page.new-page { page-break-before: always; }

/* ── Ligne meta (filtres + total) ─────────────────────────────────── */
.meta-row {
    margin-bottom: 14pt;
    font-size: 8.5pt;
    color: #64748b;
}
.meta-row > div { display: block; margin-bottom: 3pt; }
.meta-row b { color: #334155; }

/* ── Véhicule(s) : nom puis immatriculation en dessous ────────────── */
.veh-item { margin-bottom: 3pt; }
.veh-item:last-child { margin-bottom: 0; }
.veh-immat { display: block; font-size: 7pt; color: #555; }

/* ── En-tête ──────────────────────────────────────────────────────── */
.header {
    margin-bottom: 16pt;
    padding-top: 4pt;
    padding-bottom: 18pt;
    border-bottom: 0.5pt solid #e2e8f0;
    page-break-inside: avoid;
}
.header table { width: 100%; border-collapse: collapse; }
.header td { border: none; vertical-align: top; padding: 0; }
.header td.header-left { width: 44%; padding-right: 26pt; }
.company-name { font-size: 18pt; font-weight: 700; color: #0f172a; line-height: 1.05; }
.doc-type {
    font-size: 13pt;
    font-weight: 700;
    text-transform: uppercase;
    color: #334155;
    line-height: 1.1;
    margin-bottom: 12pt;
}
.doc-sub { font-size: 9pt; margin-top: 9pt; color: #64748b; }

.header td.header-right {
    width: 56%;
    font-size: 8.5pt;
    text-align: right;
    padding: 0;
}
.header table.header-details { width: 70%; margin-left: 30%; }
.header-details th, .header-details td {
    border: none;
    padding: 3pt 0;
    vertical-align: top;
    font-size: 8.5pt;
    line-height: 1.1;
}
.header-details th { width: 30%; text-align: right; font-weight: 700; }
.header-details td { width: 70%; padding-left: 12pt; text-align: left; }
.header .header-details tr { background: #fff; }

/* ── Tableau ──────────────────────────────────────────────────────── */
table {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 0;
    table-layout: fixed;
}

thead { display: table-header-group; page-break-after: avoid; }
thead tr { page-break-after: avoid; }

thead th {
    background: #f8fafc;
    border: 0.5pt solid #cbd5e1;
    padding: 7pt 6pt;
    font-size: 7.5pt;
    font-weight: 700;
    color: #334155;
    vertical-align: middle;
    white-space: normal;
}
thead th.right  { text-align: right; padding-right: 8pt; }
thead th.center { text-align: center; }
thead th.col-sig { padding-left: 2pt; padding-right: 2pt; }
/* Meme ligne pour les libelles, puis une ligne dediee a la devise. */
.column-label { display: block; white-space: nowrap; }
.column-unit { display: block; margin-top: 3pt; white-space: nowrap; }

tbody td {
    border: 0.5pt solid #cbd5e1;
    padding: 6pt 6pt;
    font-size: 8.5pt;
    vertical-align: top;
}
tbody tr:nth-child(even) { background: #f8fafc; }
/* Les groupes de milliers peuvent revenir a la ligne : un grand montant
   ne doit pas agrandir le tableau ni deborder dans la colonne voisine. */
tbody td.right  { text-align: right; padding-right: 8pt; white-space: normal; }
tbody td.center { text-align: center; }
tbody td.col-sta { padding-left: 2pt; padding-right: 2pt; }
tbody td.col-veh { padding-left: 4pt; padding-right: 4pt; }

.ben-phone {
    display: block;
    margin-top: 2pt;
    color: #555;
    font-size: 7pt;
    font-weight: 400;
    white-space: normal;
}

/* Largeurs de colonnes fixes (A4 paysage : 273mm avec marges de 12mm). */
.col-ben  { width: 21%; text-align: left; }
thead th.col-ben, tbody td.col-ben { padding-left: 12pt; padding-right: 10pt; }
.col-tel  { width: 9%; }
.col-veh  { width: 19%; }
.col-gen  { width: 8%; }
.col-cum  { width: 10%; }
.col-fra  { width: 7%; }
.col-net  { width: 8%; }
.col-pay  { width: 10%; }
.col-res  { width: 10%; }
.col-sta  { width: 7%; }
.col-sig  { width: 7%; }

/* Colonnes supplémentaires propres à l'export Commission vente. Les largeurs
   historiques des exports Logistique/Propriétaire restent inchangées. */
.validation-columns .col-ben { width: 23%; }
.validation-columns .col-veh { width: 11%; }
.validation-columns .col-cum { width: 11%; }
.validation-columns .col-fra { width: 8%; }
.validation-columns .col-net { width: 11%; }
.validation-columns .col-pay { width: 8%; }
.validation-columns .col-res { width: 11%; }
.validation-columns .col-sta { width: 9%; }
.validation-columns .col-sig { width: 8%; }

/* ── Ligne de totaux ──────────────────────────────────────────────── */
.total-row td {
    background: #f1f5f9 !important;
    font-weight: 700;
    font-size: 8pt;
    color: #0f172a;
    border: 0.75pt solid #94a3b8;
    padding: 7pt 6pt;
}
.total-row td.right { text-align: right; padding-right: 8pt; }
</style>
</head>
<body>

{{-- Pied de page global (position:fixed → présent sur chaque page physique) --}}
<div class="page-footer">
    <table>
        <tr>
            <td class="footer-left">{{ $title }} – Document confidentiel</td>
            {{-- Remplis apres le rendu : nombre total de pages et agence de chaque section. --}}
            <td class="footer-center">&#160;</td>
            <td class="footer-right">&#160;</td>
        </tr>
    </table>
</div>

@foreach($sites as $siteData)

<div class="site-page{{ !$loop->first ? ' new-page' : '' }}">

    {{-- Apollo Invoice : entreprise a gauche, titre et informations a droite. --}}
    <div class="header" data-footer-agency="{{ $siteData['site_nom'] ?: '—' }}">
        <table>
            <tr>
                <td class="header-left">
                    <div class="company-name">{{ strtoupper($org?->name ?? 'ELM') }}</div>
                    <div class="doc-sub">Rapport de commissions</div>
                </td>
                <td class="header-right">
                    <div class="doc-type">{{ $title }}</div>
                    <table class="header-details">
                        @if($siteData['site_nom'])
                        <tr><th>Agence</th><td>{{ $siteData['site_nom'] }}</td></tr>
                        @endif
                        <tr><th>Période</th><td>{{ $periode_label }}</td></tr>
                        <tr><th>Imprimé le</th><td>{{ $generated_at->format('d/m/Y H:i') }}</td></tr>
                        <tr><th>Imprimé par</th><td>{{ $printed_by }}</td></tr>
                    </table>
                </td>
            </tr>
        </table>
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
    <table class="{{ ($show_validation_columns ?? false) ? 'validation-columns' : '' }}">
        <thead>
            <tr>
                <th class="col-ben">Bénéficiaire</th>
                @unless($show_validation_columns ?? false)
                <th class="col-tel center">Téléphone</th>
                @endunless
                <th class="col-veh">Véhicule(s)</th>
                @if($show_validation_columns ?? false)
                <th class="col-cum right"><span class="column-label">Brut validé</span><span class="column-unit">(GNF)</span></th>
                @else
                <th class="col-cum right"><span class="column-label">Total cumulé</span><span class="column-unit">(GNF)</span></th>
                @endif
                <th class="col-fra right"><span class="column-label">Dépenses</span><span class="column-unit">(GNF)</span></th>
                @if($show_validation_columns ?? false)
                <th class="col-net right"><span class="column-label">Net validé</span><span class="column-unit">(GNF)</span></th>
                @endif
                <th class="col-pay right"><span class="column-label">Déjà payé</span><span class="column-unit">(GNF)</span></th>
                <th class="col-res right"><span class="column-label">Reste à payer</span><span class="column-unit">(GNF)</span></th>
                <th class="col-sta center">Statut</th>
                <th class="col-sig center">Signature</th>
            </tr>
        </thead>
        <tbody>
            @forelse($siteData['rows'] as $row)
            @php
                $telephone = $row['telephone'] ?? null;
                // Conserver les annotations eventuelles (ex. poste), sans supprimer de texte.
                $telephoneAffiche = preg_match('/^[+\d\s().-]+$/u', (string) $telephone)
                    ? \App\Support\PhoneFormatter::display($telephone)
                    : ($telephone ?: '—');
            @endphp
            <tr>
                <td class="col-ben">
                    <strong>{{ $row['beneficiaire_nom'] }}</strong>
                    @if(($show_validation_columns ?? false) && !empty($row['telephone']))
                    <span class="ben-phone">{{ $telephoneAffiche }}</span>
                    @endif
                </td>
                @unless($show_validation_columns ?? false)
                <td class="col-tel center">{{ $telephoneAffiche }}</td>
                @endunless
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
                <td class="col-cum right">{{ number_format((float) $row['total_cumule'], 0, ',', " ") }}</td>
                <td class="col-fra right">{{ $row['frais'] > 0 ? number_format((float) $row['frais'], 0, ',', " ") : '—' }}</td>
                @if($show_validation_columns ?? false)
                <td class="col-net right">{{ number_format((float) $row['net_valide'], 0, ',', " ") }}</td>
                @endif
                <td class="col-pay right">{{ number_format((float) $row['deja_paye'], 0, ',', " ") }}</td>
                <td class="col-res right">{{ $row['reste'] > 0 ? number_format((float) $row['reste'], 0, ',', " ") : '—' }}</td>
                <td class="col-sta center">{{ $row['statut'] ?? '—' }}</td>
                <td class="col-sig"></td>
            </tr>
            @empty
            <tr>
                <td colspan="9" style="text-align:center; padding:12pt; color:#555;">Aucun résultat pour ces critères.</td>
            </tr>
            @endforelse

            @if(count($siteData['rows']) > 0)
            <tr class="total-row">
                <td colspan="{{ ($show_validation_columns ?? false) ? 2 : 3 }}" style="text-align:right; padding-right:5pt; font-size:8.5pt;">TOTAUX :</td>
                <td class="right">{{ number_format((float) $siteData['totaux']['total_cumule'], 0, ',', " ") }}</td>
                <td class="right">{{ $siteData['totaux']['total_frais'] > 0 ? number_format((float) $siteData['totaux']['total_frais'], 0, ',', " ") : '—' }}</td>
                @if($show_validation_columns ?? false)
                <td class="right">{{ number_format((float) $siteData['totaux']['total_net_valide'], 0, ',', " ") }}</td>
                @endif
                <td class="right">{{ number_format((float) $siteData['totaux']['total_deja_paye'], 0, ',', " ") }}</td>
                <td class="right">{{ number_format((float) $siteData['totaux']['total_reste'], 0, ',', " ") }}</td>
                <td colspan="2"></td>
            </tr>
            @endif
        </tbody>
    </table>

</div>

@endforeach

</body>
</html>
