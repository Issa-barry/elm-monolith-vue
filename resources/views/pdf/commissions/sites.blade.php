<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8" />
<title>{{ $title }}</title>
<style>
@page { margin: 18mm 17mm 24mm 17mm; }
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: DejaVu Sans, Arial, Helvetica, sans-serif; font-size: 8pt; color: #000; background: #fff; }

.page-footer { position: fixed; bottom: 0; left: 0; right: 0; font-size: 7pt; color: #333; border-top: 0.75pt solid #888; }
.page-footer table { width: 100%; border-collapse: collapse; }
.page-footer td { border: none; padding: 3pt 0 0; }
.footer-center { text-align: center; }
.footer-right { text-align: right; }
.page-num:before   { content: counter(page); }
.page-total:before { content: counter(pages); }

.header { margin-bottom: 8pt; padding-bottom: 7pt; border-bottom: 1.5pt solid #000; }
.header table { width: 100%; border-collapse: collapse; }
.header td { border: none; vertical-align: top; padding: 0; }
.header-left { width: 70pt; }
.logo-box { border: 0.75pt solid #666; padding: 4pt 5pt; text-align: center; font-size: 7pt; color: #444; line-height: 1.4; }
.logo-box .logo-label { display: block; font-size: 6pt; color: #888; }
.logo-box .logo-name  { display: block; font-size: 7.5pt; font-weight: 700; margin-top: 2pt; }
.header-center { text-align: center; padding-top: 2pt; }
.doc-type { font-size: 13pt; font-weight: 700; text-transform: uppercase; letter-spacing: 0.4pt; }
.doc-sub { font-size: 9pt; margin-top: 3pt; color: #333; }
.header td.header-right { width: 150pt; font-size: 7.5pt; text-align: right; line-height: 1.7; padding: 0 24pt 0 6pt; }
.header-right strong { font-weight: 700; }

.meta-row { margin-bottom: 8pt; padding-left: 16pt; font-size: 8pt; color: #333; }
.meta-row > div { display: inline-block; margin-right: 16pt; }
.meta-row b { color: #000; }

table { width: 100%; border-collapse: collapse; table-layout: fixed; }
thead { display: table-header-group; }
thead th { background: #d0d0d0; border: 0.75pt solid #000; padding: 4pt 6pt; font-size: 7pt; font-weight: 700; text-transform: uppercase; letter-spacing: 0.2pt; color: #000; vertical-align: bottom; white-space: normal; }
thead th.right { text-align: right; padding-right: 8pt; }
thead th.center { text-align: center; }
tbody td { border: 0.75pt solid #bbb; padding: 3.5pt 6pt; font-size: 8.5pt; vertical-align: top; }
tbody tr:nth-child(even) { background: #f0f0f0; }
tbody td.right { text-align: right; padding-right: 8pt; white-space: nowrap; }
tbody td.center { text-align: center; }

.col-ben { width: 18%; text-align: left; }
.col-cod { width: 10%; }
.col-typ { width: 12%; }
.col-cat { width: 14%; }
.col-gen { width: 8%; }
.col-cum { width: 8%; }
.col-fra { width: 7%; }
.col-net { width: 8%; }
.col-pay { width: 8%; }
.col-res { width: 8%; }
.col-sta { width: 9%; }

.total-row td { background: #d0d0d0 !important; font-weight: 700; font-size: 9pt; border-top: 1.5pt solid #000; border-bottom: 1.5pt solid #000; border-left: 0.75pt solid #000; border-right: 0.75pt solid #000; padding: 4pt 6pt; }
.total-row td.right { text-align: right; padding-right: 8pt; }
</style>
</head>
<body>

<div class="page-footer">
    <table>
        <tr>
            <td>{{ $title }} – Document confidentiel</td>
            <td class="footer-center">Page <span class="page-num"></span> / <span class="page-total"></span></td>
            <td class="footer-right">{{ $org?->name ?? 'ELM' }}</td>
        </tr>
    </table>
</div>

<div class="header">
    <table>
        <tr>
            <td class="header-left">
                <div class="logo-box">
                    <span class="logo-label">LOGO</span>
                    <span class="logo-name">{{ strtoupper($org?->name ?? 'ELM') }}</span>
                </div>
            </td>
            <td class="header-center">
                <div class="doc-type">{{ $title }}</div>
                <div class="doc-sub">Rapport de commissions</div>
            </td>
            <td class="header-right">
                <strong>Période :</strong> {{ $periode_label }}<br>
                <strong>Imprimé le :</strong> {{ $generated_at->format('d/m/Y H:i') }}<br>
                <strong>Imprimé par :</strong> {{ $printed_by }}
            </td>
        </tr>
    </table>
</div>

<div class="meta-row">
    <div>Total : <b>{{ count($rows) }} site(s)</b></div>
</div>

<table>
    <thead>
        <tr>
            <th class="col-ben">Site</th>
            <th class="col-cod">Code</th>
            <th class="col-typ">Type</th>
            <th class="col-cat">Catégories</th>
            <th class="col-gen right">Généré (GNF)</th>
            <th class="col-cum right">Brut validé (GNF)</th>
            <th class="col-fra right">Dépenses (GNF)</th>
            <th class="col-net right">Net validé (GNF)</th>
            <th class="col-pay right">Déjà payé (GNF)</th>
            <th class="col-res right">Reste à payer (GNF)</th>
            <th class="col-sta center">Statut</th>
        </tr>
    </thead>
    <tbody>
        @forelse($rows as $row)
        <tr>
            <td class="col-ben"><strong>{{ $row['beneficiaire_nom'] }}</strong></td>
            <td class="col-cod">{{ $row['site_code'] ?? '—' }}</td>
            <td class="col-typ">{{ $row['site_type_label'] ?? '—' }}</td>
            <td class="col-cat">{{ implode(' / ', $row['categories'] ?? []) ?: '—' }}</td>
            <td class="col-gen right">{{ number_format((float) $row['total_genere'], 0, ',', "\xc2\xa0") }}</td>
            <td class="col-cum right">{{ number_format((float) $row['total_brut_cumule'], 0, ',', "\xc2\xa0") }}</td>
            <td class="col-fra right">{{ $row['total_frais'] > 0 ? number_format((float) $row['total_frais'], 0, ',', "\xc2\xa0") : '—' }}</td>
            <td class="col-net right">{{ number_format((float) $row['total_net_cumule'], 0, ',', "\xc2\xa0") }}</td>
            <td class="col-pay right">{{ number_format((float) $row['total_verse'], 0, ',', "\xc2\xa0") }}</td>
            <td class="col-res right">{{ $row['solde_restant'] > 0 ? number_format((float) $row['solde_restant'], 0, ',', "\xc2\xa0") : '—' }}</td>
            <td class="col-sta center">{{ $row['statut_label'] ?? '—' }}</td>
        </tr>
        @empty
        <tr>
            <td colspan="11" style="text-align:center; padding:12pt; color:#555;">Aucun résultat pour ces critères.</td>
        </tr>
        @endforelse

        @if(count($rows) > 0)
        <tr class="total-row">
            <td colspan="4" style="text-align:right; padding-right:5pt; font-size:8.5pt;">TOTAUX :</td>
            <td class="right">{{ number_format((float) collect($rows)->sum('total_genere'), 0, ',', "\xc2\xa0") }}</td>
            <td class="right">{{ number_format((float) collect($rows)->sum('total_brut_cumule'), 0, ',', "\xc2\xa0") }}</td>
            <td class="right">{{ number_format((float) collect($rows)->sum('total_frais'), 0, ',', "\xc2\xa0") }}</td>
            <td class="right">{{ number_format((float) collect($rows)->sum('total_net_cumule'), 0, ',', "\xc2\xa0") }}</td>
            <td class="right">{{ number_format((float) collect($rows)->sum('total_verse'), 0, ',', "\xc2\xa0") }}</td>
            <td class="right">{{ number_format((float) collect($rows)->sum('solde_restant'), 0, ',', "\xc2\xa0") }}</td>
            <td></td>
        </tr>
        @endif
    </tbody>
</table>

</body>
</html>
