@php
    /*
     * Facture d'achat — récapitulatif de la facture enregistrée dans l'application, même mise en
     * page du template local « Invoice » Apollo 6.2.0 (tableaux : DomPDF ne gère pas flexbox).
     * Ce n'est PAS la facture originale du fournisseur : la mention figure sur le document.
     * Filigrane « BROUILLON » tant que la facture n'est pas validée, « ANNULÉE » si elle est annulée.
     */
    $montant = fn ($v) => number_format((float) $v, 0, ',', ' ').' GNF';
    $estAnnulee = $facture->statut === \App\Enums\StatutFactureFournisseur::ANNULEE;
    $filigrane = $estAnnulee ? 'ANNULÉE' : ($facture->isBrouillon() ? 'BROUILLON' : null);
    $fournisseur = $facture->fournisseur;
    $taux = rtrim(rtrim(number_format((float) $facture->taux_tva, 2, ',', ' '), '0'), ',');
    $logoSvg = str_replace(['#fafafa', '#1a1a1a'], ['#2563eb', '#ffffff'], file_get_contents(public_path('favicon-dark.svg')));
    $logo = 'data:image/svg+xml;base64,'.base64_encode($logoSvg);
    if ($organisation->logo_path && \Illuminate\Support\Facades\Storage::disk('public')->exists($organisation->logo_path)) {
        $chemin = \Illuminate\Support\Facades\Storage::disk('public')->path($organisation->logo_path);
        $logo = 'data:'.mime_content_type($chemin).';base64,'.base64_encode(file_get_contents($chemin));
    }
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8" />
<title>{{ $facture->reference }}</title>
<style>
    @font-face {
        font-family: PoppinsInvoice;
        font-style: normal;
        font-weight: 400;
        src: url("{{ str_replace('\\', '/', resource_path('fonts/poppins/Poppins-Regular.ttf')) }}") format('truetype');
    }
    @font-face {
        font-family: PoppinsInvoice;
        font-style: normal;
        font-weight: 500;
        src: url("{{ str_replace('\\', '/', resource_path('fonts/poppins/Poppins-Medium.ttf')) }}") format('truetype');
    }
    @font-face {
        font-family: PoppinsInvoice;
        font-style: normal;
        font-weight: 600;
        src: url("{{ str_replace('\\', '/', resource_path('fonts/poppins/Poppins-SemiBold.ttf')) }}") format('truetype');
    }
    @font-face {
        font-family: PoppinsInvoice;
        font-style: normal;
        font-weight: 700;
        src: url("{{ str_replace('\\', '/', resource_path('fonts/poppins/Poppins-Bold.ttf')) }}") format('truetype');
    }

    /* Apollo 6.2.0 : racine 14px, Poppins 400/500/600/700, _main.scss et Invoice.vue.
       .card (chargé après Tailwind) impose 2rem = 28px de padding.
       1px CSS = 0.75pt dans DomPDF ; ne pas réduire les tailles à l'impression. */
    @page { margin: 28px 28px 56px; }
    body { margin: 0; padding: 0; font-family: PoppinsInvoice, sans-serif; font-size: 14px; font-weight: 400; line-height: 1.2; color: #334155; }
    table { border-collapse: collapse; }
    .card { padding: 28px; background: #fff; }
    .entete { width: 100%; border-bottom: 1px solid #e2e8f0; }
    .entete > tbody > tr > td { vertical-align: middle; padding: 0 0 28px; }
    .logo-cadre { width: 48px; height: 50px; }
    .logo { max-width: 48px; max-height: 50px; }
    .org-nom { margin: 14px 0; font-size: 31.5px; line-height: 35px; font-weight: 700; color: #0f172a; }
    .adresse { margin-bottom: 7px; }
    .doc-titre { margin: 0 0 14px; font-size: 21px; line-height: 28px; font-weight: 600; text-align: right; }
    .meta { width: auto; margin-left: auto; }
    .meta td { padding: 0 0 7px; vertical-align: top; }
    .meta tr:last-child td { padding-bottom: 0; }
    .meta .cle { font-weight: 600; padding-right: 42px; white-space: nowrap; }
    .meta .val { text-align: right; overflow-wrap: break-word; }

    .fournisseur { margin: 28px 0 70px; }
    .partie-titre { margin: 0 0 14px; font-size: 21px; line-height: 28px; font-weight: 500; }
    .fournisseur p { margin: 0 0 7px; }
    .fournisseur p:last-child { margin-bottom: 0; }

    .lignes { width: 100%; table-layout: auto; }
    .lignes thead { display: table-header-group; }
    .lignes tr { page-break-inside: avoid; }
    .lignes th, .lignes td { padding: 14px 0; border-bottom: 1px solid #e2e8f0; font-size: 14px; line-height: 1.2; font-weight: 400; }
    .lignes th { font-weight: 600; text-align: left; white-space: nowrap; }
    .lignes .description { overflow-wrap: break-word; }
    .lignes .quantite, .lignes .prix { padding-left: 14px; padding-right: 14px; text-align: right; white-space: nowrap; }
    .lignes .total { text-align: right; white-space: nowrap; }

    .resume { margin-top: 70px; page-break-inside: avoid; }
    .pied { width: 100%; }
    .pied > tbody > tr > td { padding: 0; vertical-align: top; }
    .notes-titre { margin-bottom: 14px; font-weight: 600; }
    .note { overflow-wrap: break-word; }
    .totaux { width: auto; margin-left: auto; }
    .totaux td { padding: 0 0 7px; }
    .totaux tr:last-child td { padding-bottom: 0; }
    .totaux .cle { padding-right: 42px; font-weight: 600; white-space: nowrap; }
    .totaux .val { font-weight: 400; text-align: right; white-space: nowrap; }

    .mention { position: fixed; bottom: -35px; left: 28px; right: 28px; font-size: 10.5px; line-height: 1.2; color: #64748b; }
    .filigrane { position: fixed; top: 330px; left: -28px; width: 760px; text-align: center; font-size: 84px; font-weight: 700; color: rgba(220, 38, 38, 0.10); transform: rotate(-30deg); }
</style>
</head>
<body>
@if($filigrane)
    <div class="filigrane">{{ $filigrane }}</div>
@endif
<div class="mention">Ce document n’est pas l’original du fournisseur.</div>
<div class="card">
    <table class="entete">
        <tbody><tr>
            <td style="width: 48%;">
                @if($logo)
                    <div class="logo-cadre"><img class="logo" src="{{ $logo }}" alt="Logo de {{ $organisation->name }}" /></div>
                @endif
                <div class="org-nom">{{ mb_strtoupper($organisation->name) }}</div>
                @if($facture->site)
                    <div class="adresse">Agence : {{ $facture->site->nom }}</div>
                    @if($facture->site->localisation)
                        <div>{{ $facture->site->localisation }}</div>
                    @elseif($facture->site->ville || $facture->site->quartier)
                        <div>{{ collect([$facture->site->ville, $facture->site->quartier])->filter()->implode(', ') }}</div>
                    @endif
                @endif
            </td>
            <td style="width: 52%;">
                <div class="doc-titre">FACTURE D’ACHAT</div>
                <table class="meta">
                    <tr><td class="cle">DATE</td><td class="val">{{ $facture->date_facture?->format('d/m/Y') }}</td></tr>
                    <tr><td class="cle">FACTURE N°</td><td class="val">{{ $facture->reference }}</td></tr>
                    <tr><td class="cle">N° FOURNISSEUR</td><td class="val">{{ $facture->numero_facture_fournisseur ?: 'Sans numéro' }}</td></tr>
                </table>
            </td>
        </tr></tbody>
    </table>

    <div class="fournisseur">
        <div class="partie-titre">FOURNISSEUR</div>
        <p>{{ $facture->fournisseurNom() ?? '—' }}</p>
        @php $adresseFournisseur = collect([$fournisseur?->adresse, $fournisseur?->ville, $fournisseur?->pays])->filter()->implode(', '); @endphp
        @if($adresseFournisseur)<p>{{ $adresseFournisseur }}</p>@endif
    </div>

    <table class="lignes">
        <thead><tr>
            <th class="description">Description</th>
            <th class="quantite">Quantité</th>
            <th class="prix">Prix unitaire</th>
            <th class="total">Total HT</th>
        </tr></thead>
        <tbody>
            @foreach($facture->lignes as $ligne)
                <tr>
                    <td class="description">{{ $ligne->libelle_snapshot ?? '—' }}</td>
                    <td class="quantite">{{ $ligne->qte_facturee }}</td>
                    <td class="prix">{{ $montant($ligne->prix_unitaire) }}</td>
                    <td class="total">{{ $montant($ligne->total_ht) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="resume">
        <table class="pied"><tbody><tr>
            <td style="width: 50%; padding-right: 28px;">
                <div class="notes-titre">NOTES</div>
                @if($facture->note)<div class="note">{!! nl2br(e($facture->note)) !!}</div>@endif
            </td>
            <td style="width: 50%;">
                <table class="totaux">
                    <tr><td class="cle">TOTAL HT</td><td class="val">{{ $montant($facture->montant_ht) }}</td></tr>
                    <tr><td class="cle">TVA ({{ $taux }} %)</td><td class="val">{{ $montant($facture->montant_tva) }}</td></tr>
                    <tr><td class="cle">TOTAL TTC</td><td class="val">{{ $montant($facture->montant_ttc) }}</td></tr>
                </table>
            </td>
        </tr></tbody></table>
    </div>
</div>

</body>
</html>
