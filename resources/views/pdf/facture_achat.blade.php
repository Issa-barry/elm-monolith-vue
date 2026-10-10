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
    @page { margin: 40px 48px; }
    * { margin: 0; padding: 0; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 10.5px; color: #334155; }
    table { width: 100%; border-collapse: collapse; }
    .muted { color: #64748b; }
    .strong { font-weight: bold; color: #0f172a; }

    .filigrane {
        position: fixed; top: 330px; left: -40px; width: 800px; text-align: center;
        font-size: 92px; font-weight: bold; letter-spacing: 6px;
        color: rgba(220, 38, 38, 0.13); transform: rotate(-30deg);
    }

    .entete td { vertical-align: top; }
    .entete { border-bottom: 1px solid #e2e8f0; }
    .entete .gauche { padding-bottom: 24px; }
    .org-nom { font-size: 26px; font-weight: bold; color: #0f172a; margin: 10px 0 8px; }
    .doc-titre { font-size: 17px; font-weight: bold; color: #0f172a; text-align: right; margin-bottom: 12px; }
    .meta td { padding: 2px 0; }
    .meta .cle { font-weight: bold; color: #0f172a; padding-right: 28px; white-space: nowrap; }
    .meta .val { text-align: right; white-space: nowrap; }

    .parties { margin: 26px 0 40px; }
    .parties td { vertical-align: top; width: 50%; }
    .partie-titre { font-size: 15px; font-weight: bold; color: #0f172a; margin-bottom: 8px; }
    .partie td { padding: 1px 0; }

    .lignes th { text-align: left; font-weight: bold; color: #0f172a; padding: 10px 8px; border-bottom: 1px solid #e2e8f0; white-space: nowrap; }
    .lignes td { padding: 10px 8px; border-bottom: 1px solid #e2e8f0; }
    .lignes .droite { text-align: right; }
    .lignes .ref { display: block; font-size: 9px; color: #64748b; margin-top: 2px; }

    .pied { margin-top: 40px; }
    .pied td { vertical-align: top; }
    .notes-titre { font-weight: bold; color: #0f172a; margin-bottom: 6px; }
    .totaux td { padding: 3px 0; }
    .totaux .cle { font-weight: bold; color: #0f172a; padding-right: 36px; }
    .totaux .val { text-align: right; }
    .totaux .total td { font-size: 13px; padding-top: 6px; }

    .encadre { margin-top: 26px; padding: 10px 12px; border: 1px solid #e2e8f0; border-radius: 6px; }
    .encadre-titre { font-size: 8.5px; font-weight: bold; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 4px; }
    .ok { color: #047857; }
    .attention { color: #b45309; }
    .danger { color: #b91c1c; }
    .mention { margin-top: 18px; font-size: 8.5px; color: #64748b; }

    .bas { position: fixed; bottom: -20px; left: 0; right: 0; font-size: 8.5px; color: #94a3b8; }
</style>
</head>
<body>
@if($filigrane)
    <div class="filigrane">{{ $filigrane }}</div>
@endif
<table class="entete">
    <tr>
        <td class="gauche" style="width: 58%;">
            @if($logo)
                <img src="{{ $logo }}" alt="" style="max-height: 50px; max-width: 160px;" />
            @endif
            <div class="org-nom">{{ strtoupper($organisation->name) }}</div>
            @if($facture->site)
                <div class="muted">Agence : {{ $facture->site->nom }}</div>
            @endif
        </td>
        <td style="width: 42%;">
            <div class="doc-titre">FACTURE D’ACHAT</div>
            <table class="meta">
                <tr><td class="cle">RÉFÉRENCE</td><td class="val">{{ $facture->reference }}</td></tr>
                <tr><td class="cle">JUSTIFICATIF</td><td class="val">{{ ($facture->type_justificatif ?? \App\Enums\TypeJustificatifAchat::FACTURE)->label() }}</td></tr>
                <tr><td class="cle">N° FOURNISSEUR</td><td class="val">{{ $facture->numero_facture_fournisseur ?: '—' }}</td></tr>
                <tr><td class="cle">{{ $facture->estSansJustificatif() ? "DATE D'ACHAT" : 'DATE' }}</td><td class="val">{{ $facture->date_facture?->format('d/m/Y') }}</td></tr>
                <tr><td class="cle">ÉCHÉANCE</td><td class="val">{{ $facture->date_echeance?->format('d/m/Y') ?? '—' }}</td></tr>
                <tr><td class="cle">STATUT</td><td class="val">{{ $facture->statut?->label() }}</td></tr>
            </table>
        </td>
    </tr>
</table>
<table class="parties">
    <tr>
        <td>
            <div class="partie-titre">FOURNISSEUR</div>
            <table class="partie">
                <tr><td class="strong">{{ $facture->fournisseurNom() ?? '—' }}</td></tr>
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
            <th>Désignation</th>
            <th>Réception</th>
            <th class="droite">Quantité</th>
            <th class="droite">Prix unitaire HT</th>
            <th class="droite">Total HT</th>
        </tr>
    </thead>
    <tbody>
        @foreach($facture->lignes as $ligne)
            @php $reception = $ligne->receptionLigne?->reception; @endphp
            <tr>
                <td>
                    {{ $ligne->libelle_snapshot ?? '—' }}
                    @if($ligne->reference_snapshot)
                        <span class="ref">Réf. {{ $ligne->reference_snapshot }}</span>
                    @endif
                </td>
                <td>
                    {{ $reception?->reference ?? '—' }}
                    @if($reception?->date_reception)
                        <span class="ref">{{ $reception->date_reception->format('d/m/Y') }}</span>
                    @endif
                </td>
                <td class="droite">{{ $ligne->qte_facturee }}</td>
                <td class="droite">{{ $montant($ligne->prix_unitaire) }}</td>
                <td class="droite">{{ $montant($ligne->total_ht) }}</td>
            </tr>
        @endforeach
    </tbody>
</table>
<table class="pied">
    <tr>
        <td style="width: 55%; padding-right: 24px;">
            <div class="notes-titre">NOTES</div>
            <div>{{ $facture->note ?: '—' }}</div>
        </td>
        <td style="width: 45%;">
            <table class="totaux">
                <tr><td class="cle">TOTAL HT</td><td class="val">{{ $montant($facture->montant_ht) }}</td></tr>
                <tr><td class="cle">TVA ({{ $taux }} %)</td><td class="val">{{ $montant($facture->montant_tva) }}</td></tr>
                <tr class="total"><td class="cle">TOTAL TTC</td><td class="val strong">{{ $montant($facture->montant_ttc) }}</td></tr>
                @if($facture->isConstatee())
                    <tr><td class="cle">DÉJÀ PAYÉ</td><td class="val">{{ $montant($facture->montant_paye) }}</td></tr>
                    <tr><td class="cle">RESTE DÛ</td><td class="val strong">{{ $montant($facture->resteDu()) }}</td></tr>
                @endif
            </table>
        </td>
    </tr>
</table>

@if($estAnnulee)
    <div class="encadre">
        <div class="encadre-titre danger">Facture annulée</div>
        <div>{{ $facture->motif_annulation }}</div>
        @if($facture->annulee_at)
            <div class="muted">Le {{ $facture->annulee_at->format('d/m/Y à H:i') }}</div>
        @endif
    </div>
@elseif($facture->isConstatee())
    <div class="encadre">
        <div class="encadre-titre ok">Facture validée — dette fournisseur constatée</div>
        <div>
            Le {{ $facture->validee_at?->format('d/m/Y à H:i') }}
            @if($nom($facture->valideePar)) par {{ $nom($facture->valideePar) }} @endif
        </div>
    </div>
@else
    <div class="encadre">
        <div class="encadre-titre attention">Brouillon</div>
        <div>Aucune dette n'est constatée tant que la facture n'est pas validée.</div>
    </div>
@endif

<div class="mention">
    @if($facture->estSansJustificatif())
        Récapitulatif établi par {{ $organisation->name }} : achat enregistré sans justificatif du fournisseur.
    @else
        Récapitulatif établi par {{ $organisation->name }} à partir du document du fournisseur
        ({{ $facture->designationDocument() }}). Ce document n’est pas l’original du fournisseur.
    @endif
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
