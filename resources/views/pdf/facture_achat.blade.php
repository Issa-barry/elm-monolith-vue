@php
    /*
     * Facture d'achat — récapitulatif de la facture enregistrée dans l'application, même mise en
     * page « Invoice » Apollo que le bon de commande (tableaux : DomPDF ne gère pas flexbox).
     * Ce n'est PAS la facture originale du fournisseur : la mention figure sur le document.
     * Filigrane « BROUILLON » tant que la facture n'est pas validée, « ANNULÉE » si elle est annulée.
     */
    $montant = fn ($v) => number_format((float) $v, 0, ',', ' ').' GNF';
    $estAnnulee = $facture->statut === \App\Enums\StatutFactureFournisseur::ANNULEE;
    $filigrane = $estAnnulee ? 'ANNULÉE' : ($facture->isBrouillon() ? 'BROUILLON' : null);
    $fournisseur = $facture->fournisseur;
    $taux = rtrim(rtrim(number_format((float) $facture->taux_tva, 2, ',', ' '), '0'), ',');
    $nom = fn ($u) => $u ? trim($u->prenom.' '.$u->nom) : null;
    $logo = null;
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
    body, div, table, th, td, span { margin: 0; padding: 0; }
    @page { margin: 48px 52px 60px; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 12px; line-height: 1.5; color: #334155; }
    table { width: 100%; border-collapse: collapse; }
    .muted { color: #64748b; }
    .strong { font-weight: bold; color: #0f172a; }

    .filigrane {
        position: fixed; top: 330px; left: -40px; width: 760px; text-align: center;
        font-size: 84px; font-weight: bold; letter-spacing: 6px;
        color: rgba(220, 38, 38, 0.10); transform: rotate(-30deg);
    }

    .entete { table-layout: fixed; border-bottom: 1px solid #e2e8f0; }
    .entete td { vertical-align: top; padding-bottom: 28px; }
    .entete .gauche { padding-right: 24px; }
    .org-nom { font-size: 28px; line-height: 1.2; font-weight: bold; color: #0f172a; margin: 12px 0 8px; overflow-wrap: break-word; }
    .doc-titre { font-size: 11px; font-weight: bold; letter-spacing: 1.5px; color: #64748b; text-align: right; }
    .doc-reference { font-family: DejaVu Sans Mono, monospace; font-size: 19px; line-height: 1.3; font-weight: bold; color: #0f172a; text-align: right; margin: 6px 0 16px; overflow-wrap: break-word; }
    .meta { table-layout: fixed; font-size: 11px; }
    .entete .meta td { padding: 3px 0; }
    .meta .cle { width: 44%; color: #64748b; padding-right: 8px; }
    .meta .val { text-align: right; overflow-wrap: break-word; }

    .parties { table-layout: fixed; margin: 28px 0 32px; }
    .parties td { vertical-align: top; width: 50%; }
    .parties .fournisseur { padding-right: 28px; }
    .partie-titre { font-size: 10px; font-weight: bold; letter-spacing: 1px; color: #64748b; margin-bottom: 10px; }
    .fournisseur-nom { font-size: 19px; line-height: 1.3; margin-bottom: 6px; }
    .partie td { padding: 2px 0; overflow-wrap: break-word; }

    .lignes { table-layout: fixed; }
    .lignes thead { display: table-header-group; }
    .lignes tr { page-break-inside: avoid; }
    .lignes th { text-align: left; font-weight: bold; color: #0f172a; padding: 12px 8px; border-bottom: 1px solid #e2e8f0; }
    .lignes td { vertical-align: top; padding: 14px 8px; border-bottom: 1px solid #e2e8f0; }
    .lignes th:first-child, .lignes td:first-child { padding-left: 0; }
    .lignes th:last-child, .lignes td:last-child { padding-right: 0; }
    .lignes .droite { text-align: right; white-space: nowrap; }
    .lignes .description { overflow-wrap: break-word; }
    .lignes .ref { display: block; font-size: 10px; line-height: 1.5; color: #64748b; margin-top: 3px; }

    .resume { page-break-inside: avoid; }
    .pied { table-layout: fixed; margin-top: 30px; }
    .pied td { vertical-align: top; }
    .notes-titre { font-size: 10px; font-weight: bold; letter-spacing: 1px; color: #64748b; margin-bottom: 8px; }
    .note { overflow-wrap: break-word; }
    .totaux { table-layout: fixed; page-break-inside: avoid; }
    .totaux td { padding: 5px 0; }
    .totaux .cle { width: 42%; color: #64748b; padding-right: 12px; }
    .totaux .val { text-align: right; white-space: nowrap; }
    .totaux .total td { padding-top: 8px; font-weight: bold; color: #0f172a; }
    .totaux .paiement td { border-top: 1px solid #e2e8f0; padding-top: 10px; }
    .totaux .solde td { font-size: 15px; font-weight: bold; color: #0f172a; padding-top: 6px; }

    .suivi { margin-top: 30px; padding-top: 16px; border-top: 1px solid #e2e8f0; page-break-inside: avoid; }
    .suivi-titre { font-size: 10px; font-weight: bold; color: #0f172a; margin-bottom: 5px; }
    .mention { margin-top: 18px; font-size: 9px; line-height: 1.5; color: #64748b; }
    .bas { position: fixed; bottom: -34px; left: 0; right: 0; font-size: 9px; color: #94a3b8; }
</style>
</head>
<body>
@if($filigrane)
    <div class="filigrane">{{ $filigrane }}</div>
@endif
<table class="entete">
    <tr>
        <td class="gauche" style="width: 52%;">
            @if($logo)
                <img src="{{ $logo }}" alt="" style="max-height: 50px; max-width: 160px;" />
            @endif
            <div class="org-nom">{{ strtoupper($organisation->name) }}</div>
            @if($facture->site)
                <div class="muted">Agence : {{ $facture->site->nom }}</div>
            @endif
        </td>
        <td style="width: 48%;">
            <div class="doc-titre">FACTURE D’ACHAT</div>
            <div class="doc-reference">{{ $facture->reference }}</div>
            <table class="meta">
                <tr><td class="cle">N° FOURNISSEUR</td><td class="val">{{ $facture->numero_facture_fournisseur ?: 'Sans numéro' }}</td></tr>
                <tr><td class="cle">DATE</td><td class="val">{{ $facture->date_facture?->format('d/m/Y') }}</td></tr>
                <tr><td class="cle">ÉCHÉANCE</td><td class="val">{{ $facture->date_echeance?->format('d/m/Y') ?? '—' }}</td></tr>
                <tr><td class="cle">STATUT</td><td class="val">{{ $facture->statut?->label() }}</td></tr>
            </table>
        </td>
    </tr>
</table>
<table class="parties">
    <tr>
        <td class="fournisseur">
            <div class="partie-titre">FOURNISSEUR</div>
            <table class="partie">
                <tr><td class="fournisseur-nom strong">{{ $facture->fournisseurNom() ?? '—' }}</td></tr>
                @if($fournisseur?->phone)
                    <tr><td>{{ trim(($fournisseur->code_phone_pays ? $fournisseur->code_phone_pays.' ' : '').$fournisseur->phone) }}</td></tr>
                @endif
                @if($fournisseur?->email)
                    <tr><td>{{ $fournisseur->email }}</td></tr>
                @endif
            </table>
        </td>
        <td>
            <div class="partie-titre">BON DE COMMANDE</div>
            <table class="partie">
                <tr><td class="strong">{{ $facture->commande?->reference ?? '—' }}</td></tr>
                <tr><td>Livré à {{ $facture->site?->nom ?? '—' }}</td></tr>
                @if($nom($facture->createdBy))
                    <tr><td class="muted">Saisie par {{ $nom($facture->createdBy) }}</td></tr>
                @endif
            </table>
        </td>
    </tr>
</table>
<table class="lignes">
    <thead>
        <tr>
            <th style="width: 42%;">Description</th>
            <th class="droite" style="width: 10%;">Quantité</th>
            <th class="droite" style="width: 24%;">Prix unitaire HT</th>
            <th class="droite" style="width: 24%;">Total HT</th>
        </tr>
    </thead>
    <tbody>
        @foreach($facture->lignes as $ligne)
            @php $reception = $ligne->receptionLigne?->reception; @endphp
            <tr>
                <td class="description">
                    <span class="strong">{{ $ligne->libelle_snapshot ?? '—' }}</span>
                    @if($ligne->reference_snapshot)
                        <span class="ref">Réf. {{ $ligne->reference_snapshot }}</span>
                    @endif
                    @if($reception)
                        <span class="ref">
                            Réception {{ $reception->reference }}
                            @if($reception->date_reception) · {{ $reception->date_reception->format('d/m/Y') }} @endif
                        </span>
                    @endif
                </td>
                <td class="droite">{{ $ligne->qte_facturee }}</td>
                <td class="droite">{{ $montant($ligne->prix_unitaire) }}</td>
                <td class="droite strong">{{ $montant($ligne->total_ht) }}</td>
            </tr>
        @endforeach
    </tbody>
</table>
<div class="resume">
<table class="pied">
    <tr>
        <td style="width: 54%; padding-right: 32px;">
            <div class="notes-titre">NOTES</div>
            <div class="note">{!! nl2br(e($facture->note ?: '—')) !!}</div>
        </td>
        <td style="width: 46%;">
            <table class="totaux">
                <tr><td class="cle">TOTAL HT</td><td class="val">{{ $montant($facture->montant_ht) }}</td></tr>
                <tr><td class="cle">TVA ({{ $taux }} %)</td><td class="val">{{ $montant($facture->montant_tva) }}</td></tr>
                <tr class="total"><td class="cle">TOTAL TTC</td><td class="val strong">{{ $montant($facture->montant_ttc) }}</td></tr>
                @if($facture->isConstatee())
                    <tr class="paiement"><td class="cle">DÉJÀ PAYÉ</td><td class="val">{{ $montant($facture->montant_paye) }}</td></tr>
                    <tr class="solde"><td class="cle">RESTE DÛ</td><td class="val strong">{{ $montant($facture->resteDu()) }}</td></tr>
                @endif
            </table>
        </td>
    </tr>
</table>

@if($estAnnulee)
    <div class="suivi">
        <div class="suivi-titre">Facture annulée</div>
        <div>{{ $facture->motif_annulation }}</div>
        @if($facture->annulee_at)
            <div class="muted">Le {{ $facture->annulee_at->format('d/m/Y à H:i') }}</div>
        @endif
    </div>
@elseif($facture->isConstatee())
    <div class="suivi">
        <div class="suivi-titre">Facture validée — dette fournisseur constatée</div>
        <div>
            Le {{ $facture->validee_at?->format('d/m/Y à H:i') }}
            @if($nom($facture->valideePar)) par {{ $nom($facture->valideePar) }} @endif
        </div>
    </div>
@else
    <div class="suivi">
        <div class="suivi-titre">Brouillon</div>
        <div>Aucune dette n'est constatée tant que la facture n'est pas validée.</div>
    </div>
@endif

<div class="mention">
    Récapitulatif établi par {{ $organisation->name }} de l’achat enregistré dans l’application
    (document du fournisseur : {{ $facture->numero_facture_fournisseur ? 'n° '.$facture->numero_facture_fournisseur : 'sans numéro' }}).
    Ce document n’est pas l’original du fournisseur.
</div>

</div>

<div class="bas">
    <table>
        <tr>
            <td>{{ $organisation->name }} — {{ $facture->reference }}</td>
            <td style="text-align: right;">Généré le {{ now()->format('d/m/Y à H:i') }}</td>
        </tr>
    </table>
</div>

</body>
</html>
