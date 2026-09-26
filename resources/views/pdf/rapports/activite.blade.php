@php
    $gnf = fn ($v) => number_format((float) $v, 0, ',', "\xc2\xa0");
    $v = $rapport['ventes'];
    $e = $rapport['encaissements'];
    $c = $rapport['creances'];
    $m = $rapport['mobile_money'];
    $k = $rapport['caisse'];
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8" />
<title>{{ $title }}</title>
<style>
@page { margin: 16mm 14mm 20mm 14mm; }
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: DejaVu Sans, Arial, Helvetica, sans-serif; font-size: 8pt; color: #000; background: #fff; }

.page-footer { position: fixed; bottom: -8mm; left: 0; right: 0; font-size: 7pt; color: #333; border-top: 0.75pt solid #888; }
.page-footer table { width: 100%; border-collapse: collapse; }
.page-footer td { border: none; padding: 3pt 0 0; }
.footer-center { text-align: center; }
.footer-right { text-align: right; }
.page-num:before   { content: counter(page); }
.page-total:before { content: counter(pages); }

.header { margin-bottom: 8pt; padding-bottom: 6pt; border-bottom: 1.5pt solid #000; }
.header table { width: 100%; border-collapse: collapse; }
.header td { border: none; vertical-align: top; padding: 0; }
.doc-type { font-size: 13pt; font-weight: 700; text-transform: uppercase; letter-spacing: 0.4pt; }
.doc-sub { font-size: 9pt; margin-top: 3pt; color: #333; }
.header-right { width: 190pt; font-size: 7.5pt; text-align: right; line-height: 1.6; }

h2 { font-size: 10pt; margin: 12pt 0 4pt; text-transform: uppercase; letter-spacing: 0.3pt; border-bottom: 0.75pt solid #000; padding-bottom: 2pt; }
.note { font-size: 7pt; color: #444; margin-bottom: 4pt; }

.kpis { width: 100%; border-collapse: collapse; margin-bottom: 4pt; }
.kpis td { border: 0.75pt solid #999; padding: 4pt 6pt; width: 25%; vertical-align: top; }
.kpis .lbl { font-size: 7pt; color: #444; }
.kpis .val { font-size: 10pt; font-weight: 700; margin-top: 2pt; }

table.data { width: 100%; border-collapse: collapse; table-layout: fixed; }
table.data thead { display: table-header-group; }
table.data th { background: #d0d0d0; border: 0.75pt solid #000; padding: 3pt 4pt; font-size: 6.5pt; font-weight: 700; text-transform: uppercase; text-align: left; }
table.data td { border: 0.75pt solid #bbb; padding: 2.5pt 4pt; font-size: 7.5pt; vertical-align: top; word-wrap: break-word; }
table.data tr:nth-child(even) td { background: #f0f0f0; }
.right { text-align: right !important; white-space: nowrap; }
.total td { background: #d0d0d0 !important; font-weight: 700; border-top: 1.25pt solid #000; }
.vide { text-align: center; color: #555; padding: 8pt; }
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
            <td>
                <div class="doc-type">{{ $title }}</div>
                <div class="doc-sub">{{ $entete['Période'] }}</div>
            </td>
            <td class="header-right">
                @foreach ($entete as $libelle => $valeur)
                    @if ($libelle !== 'Période')
                        <strong>{{ $libelle }} :</strong> {{ $valeur }}<br>
                    @endif
                @endforeach
                <strong>Imprimé par :</strong> {{ $printed_by }}
            </td>
        </tr>
    </table>
</div>

<table class="kpis">
    <tr>
        <td><div class="lbl">Ventes de la période</div><div class="val">{{ $v['resume']['nombre'] }} — {{ $gnf($v['resume']['facture']) }} GNF</div></td>
        <td><div class="lbl">Encaissements de la période</div><div class="val">{{ $gnf($e['resume']['montant']) }} GNF</div></td>
        <td><div class="lbl">Créances en cours (toutes dates)</div><div class="val">{{ $gnf($c['resume']['reste']) }} GNF</div></td>
        <td><div class="lbl">Caisse — solde actuel (théorique)</div><div class="val">{{ $gnf($k['resume']['solde_actuel']) }} GNF</div></td>
    </tr>
</table>
<p class="note">Chaque bloc est calculé indépendamment : ventes = factures créées dans la période ; encaissements = paiements reçus dans la période, quelle que soit la date de la vente ; créances = état actuel, toutes dates confondues.</p>

<h2>Ventes</h2>
<p class="note">Encaissé {{ $gnf($v['resume']['encaisse']) }} GNF et reste {{ $gnf($v['resume']['reste']) }} GNF sur ces ventes (état actuel).
    @if ($v['resume']['annulees_nombre'] > 0) {{ $v['resume']['annulees_nombre'] }} vente(s) annulée(s) ou retournée(s) exclue(s) du chiffre d'affaires. @endif</p>
<table class="data">
    <thead><tr><th>Facture</th><th>Date</th><th>Client</th><th>Agent</th><th>Agence</th><th class="right">Montant</th><th class="right">Encaissé</th><th class="right">Reste</th><th>Statut</th></tr></thead>
    <tbody>
    @forelse ($v['lignes'] as $l)
        <tr><td>{{ $l['reference'] }}</td><td>{{ \Carbon\Carbon::parse($l['date'])->format('d/m/Y') }}</td><td>{{ $l['client'] ?? '—' }}</td><td>{{ $l['agent'] ?? '—' }}</td><td>{{ $l['site_nom'] ?? '—' }}</td>
            <td class="right">{{ $gnf($l['montant']) }}</td><td class="right">{{ $gnf($l['encaisse']) }}</td><td class="right">{{ $gnf($l['reste']) }}</td><td>{{ $l['statut_label'] }}</td></tr>
    @empty
        <tr><td colspan="9" class="vide">Aucune vente sur la période.</td></tr>
    @endforelse
    </tbody>
</table>

<h2>Encaissements</h2>
<table class="kpis">
    <tr>
    @foreach ($e['par_moyen'] as $moyen)
        <td><div class="lbl">{{ $moyen['libelle'] }} ({{ $moyen['nombre'] }})</div><div class="val">{{ $gnf($moyen['montant']) }} GNF</div></td>
        @if ($loop->iteration % 4 === 0 && ! $loop->last)</tr><tr>@endif
    @endforeach
    @if (count($e['par_moyen']) === 0)<td>Aucun encaissement.</td>@endif
    </tr>
</table>
<table class="data">
    <thead><tr><th>Date</th><th>Saisi le</th><th>Facture</th><th>Client</th><th>Agent</th><th>Moyen</th><th>Référence</th><th class="right">Montant</th></tr></thead>
    <tbody>
    @forelse ($e['lignes'] as $l)
        <tr><td>{{ \Carbon\Carbon::parse($l['date_encaissement'])->format('d/m/Y') }}</td><td>{{ $l['saisi_le'] ? \Carbon\Carbon::parse($l['saisi_le'])->format('d/m/Y H:i') : '—' }}</td><td>{{ $l['facture_reference'] }}</td>
            <td>{{ $l['client'] ?? '—' }}</td><td>{{ $l['agent'] ?? '—' }}</td><td>{{ $l['moyen_libelle'] }}</td><td>{{ $l['reference_paiement'] ?? '—' }}</td><td class="right">{{ $gnf($l['montant']) }}</td></tr>
    @empty
        <tr><td colspan="8" class="vide">Aucun encaissement sur la période.</td></tr>
    @endforelse
    @if (count($e['lignes']) > 0)
        <tr class="total"><td colspan="7" class="right">TOTAL</td><td class="right">{{ $gnf($e['resume']['montant']) }}</td></tr>
    @endif
    </tbody>
</table>

<h2>Créances en cours</h2>
<p class="note">{{ $c['resume']['impayees'] }} impayée(s), {{ $c['resume']['partielles'] }} partielle(s) — état au moment de l'export, toutes dates confondues.</p>
<table class="data">
    <thead><tr><th>Facture</th><th>Date</th><th class="right">Jours</th><th>Client</th><th>Agent</th><th class="right">Montant</th><th class="right">Encaissé</th><th class="right">Reste</th><th>Statut</th></tr></thead>
    <tbody>
    @forelse ($c['lignes'] as $l)
        <tr><td>{{ $l['reference'] }}</td><td>{{ \Carbon\Carbon::parse($l['date'])->format('d/m/Y') }}</td><td class="right">{{ $l['anciennete_jours'] }}</td><td>{{ $l['client'] ?? '—' }}</td><td>{{ $l['agent'] ?? '—' }}</td>
            <td class="right">{{ $gnf($l['montant']) }}</td><td class="right">{{ $gnf($l['encaisse']) }}</td><td class="right">{{ $gnf($l['reste']) }}</td><td>{{ $l['statut_label'] }}</td></tr>
    @empty
        <tr><td colspan="9" class="vide">Aucune créance en cours.</td></tr>
    @endforelse
    @if (count($c['lignes']) > 0)
        <tr class="total"><td colspan="7" class="right">RESTE DÛ</td><td class="right">{{ $gnf($c['resume']['reste']) }}</td><td></td></tr>
    @endif
    </tbody>
</table>

<h2>Mobile Money</h2>
<p class="note">{{ $m['resume']['nombre'] }} encaissement(s), {{ $gnf($m['resume']['montant']) }} GNF — {{ $m['resume']['reference_absente'] }} référence(s) absente(s), {{ $m['resume']['reference_dupliquee'] }} référence(s) déjà utilisée(s).</p>
<table class="data">
    <thead><tr><th>Date</th><th>Opérateur</th><th>Référence</th><th class="right">Montant</th><th>Facture</th><th>Client</th><th>Agent</th><th>Contrôle</th></tr></thead>
    <tbody>
    @forelse ($m['lignes'] as $l)
        <tr><td>{{ \Carbon\Carbon::parse($l['date_encaissement'])->format('d/m/Y') }}</td><td>{{ $l['moyen_libelle'] }}</td><td>{{ $l['reference_paiement'] ?? '—' }}</td><td class="right">{{ $gnf($l['montant']) }}</td>
            <td>{{ $l['facture_reference'] }}</td><td>{{ $l['client'] ?? '—' }}</td><td>{{ $l['agent'] ?? '—' }}</td><td>{{ \App\Services\Rapports\Export\RapportActiviteExport::libelleAnomalie($l['anomalie']) ?: 'OK' }}</td></tr>
    @empty
        <tr><td colspan="8" class="vide">Aucun encaissement Mobile Money sur la période.</td></tr>
    @endforelse
    </tbody>
</table>

<h2>Caisse</h2>
@if ($k['aucune_caisse'])
    <p class="note">Aucune caisse dédiée dans ce périmètre.</p>
@else
    <p class="note">Tableau de caisse tiré du grand livre : solde de début + mouvements = solde de fin. Solde actuel = montant théorique à remettre (aucun comptage physique n'est enregistré).</p>
    <table class="data">
        <thead><tr><th>Agent</th><th>Agence</th><th class="right">Solde début</th><th class="right">Entrées</th><th class="right">Sorties</th><th class="right">Solde fin</th><th class="right">Solde actuel</th><th class="right">En cours de versement</th><th>Dernier versement</th></tr></thead>
        <tbody>
        @foreach ($k['fiches'] as $f)
            <tr><td>{{ $f['caisse']['agent_nom'] ?? '—' }}</td><td>{{ $f['caisse']['site_nom'] ?? '—' }}</td>
                <td class="right">{{ $gnf($f['solde_debut']) }}</td><td class="right">{{ $gnf($f['total_entrees']) }}</td><td class="right">{{ $gnf($f['total_sorties']) }}</td>
                <td class="right">{{ $gnf($f['solde_fin']) }}</td><td class="right">{{ $gnf($f['solde_actuel']) }}</td><td class="right">{{ $gnf($f['en_cours']['montant']) }}</td>
                <td>{{ $f['dernier_versement'] ? \Carbon\Carbon::parse($f['dernier_versement']['date_envoi'])->format('d/m/Y').' ('.$f['dernier_versement']['anciennete_jours'].' j)' : 'Aucun' }}</td></tr>
        @endforeach
        </tbody>
    </table>
    @foreach ($k['fiches'] as $f)
        @if (! empty($f['ecritures']))
            <p class="note" style="margin-top:6pt"><strong>{{ $f['caisse']['libelle'] }}</strong> — détail des écritures</p>
            <table class="data">
                <thead><tr><th>Date</th><th>Pièce</th><th>Mouvement</th><th>Libellé</th><th class="right">Entrée</th><th class="right">Sortie</th><th class="right">Solde</th></tr></thead>
                <tbody>
                <tr><td colspan="6">Solde au début de la période</td><td class="right">{{ $gnf($f['solde_debut']) }}</td></tr>
                @foreach ($f['ecritures'] as $l)
                    <tr><td>{{ \Carbon\Carbon::parse($l['date'])->format('d/m/Y') }}</td><td>{{ $l['numero'] }}</td><td>{{ $l['categorie_libelle'] }}</td><td>{{ $l['libelle'] }}</td>
                        <td class="right">{{ $l['entree'] > 0 ? $gnf($l['entree']) : '' }}</td><td class="right">{{ $l['sortie'] > 0 ? $gnf($l['sortie']) : '' }}</td><td class="right">{{ $gnf($l['solde']) }}</td></tr>
                @endforeach
                <tr class="total"><td colspan="6" class="right">SOLDE À LA FIN DE LA PÉRIODE</td><td class="right">{{ $gnf($f['solde_fin']) }}</td></tr>
                </tbody>
            </table>
        @endif
    @endforeach
@endif

</body>
</html>
