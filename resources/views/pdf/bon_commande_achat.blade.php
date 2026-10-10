@php
    /*
     * Bon de commande fournisseur — mise en page du modèle « Invoice » Apollo (ADR 0021), traduite
     * en tableaux : DomPDF ne gère pas flexbox. Filigrane « NON VALIDÉ » tant que le bon n'a pas
     * été validé, « ANNULÉ » s'il est annulé. Une fois validé, fournisseur, agence et libellés
     * viennent du snapshot figé à la validation.
     */
    $montant = fn ($v) => number_format((float) $v, 0, ',', ' ').' GNF';
    $filigrane = $commande->isAnnulee() ? 'ANNULÉ' : ($commande->isValidee() ? null : 'NON VALIDÉ');
    $fournisseur = $commande->fournisseur;
    $regle = $commande->validation_regle_snapshot;
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
<title>{{ $commande->reference }}</title>
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
            @if($commande->siteNom())
                <div class="muted">Agence de livraison : {{ $commande->siteNom() }}</div>
                @if($commande->estPayeParUneAutreAgence())
                    <div class="muted">Payé par : {{ $commande->sitePayeurNom() }}</div>
                @endif
            @endif
        </td>
        <td style="width: 42%;">
            <div class="doc-titre">BON DE COMMANDE</div>
            <table class="meta">
                <tr><td class="cle">DATE</td><td class="val">{{ $commande->dateAchat()?->format('d/m/Y') }}</td></tr>
                <tr><td class="cle">BON N°</td><td class="val">{{ $commande->reference }}</td></tr>
                <tr><td class="cle">STATUT</td><td class="val">{{ $commande->statut_label }}</td></tr>
            </table>
        </td>
    </tr>
</table>

<table class="parties">
    <tr>
        <td>
            <div class="partie-titre">FOURNISSEUR</div>
            <table class="partie">
                <tr><td class="strong">{{ $commande->fournisseurNom() ?? '—' }}</td></tr>
                @if($fournisseur?->phone)
                    <tr><td>{{ trim(($fournisseur->code_phone_pays ? $fournisseur->code_phone_pays.' ' : '').$fournisseur->phone) }}</td></tr>
                @endif
                @if($fournisseur?->email)
                    <tr><td>{{ $fournisseur->email }}</td></tr>
                @endif
            </table>
        </td>
        <td>
            <div class="partie-titre">LIVRER À</div>
            <table class="partie">
                <tr><td class="strong">{{ $commande->siteNom() ?? '—' }}</td></tr>
                <tr><td>{{ $organisation->name }}</td></tr>
                <tr><td class="muted">Émis par {{ $createdBy }}</td></tr>
            </table>
        </td>
    </tr>
</table>

<table class="lignes">
    <thead>
        <tr>
            <th>Désignation</th>
            <th class="droite">Quantité</th>
            <th class="droite">Prix unitaire</th>
            <th class="droite">Total ligne</th>
        </tr>
    </thead>
    <tbody>
        @foreach($commande->lignes as $ligne)
            <tr>
                <td>
                    {{ $ligne->libelle_snapshot ?? $ligne->variante?->produit?->nom ?? '—' }}
                    @if($ligne->reference_snapshot)
                        <span class="ref">Réf. {{ $ligne->reference_snapshot }}</span>
                    @endif
                </td>
                <td class="droite">{{ $ligne->qte }}</td>
                <td class="droite">{{ $montant($ligne->prix_achat_snapshot) }}</td>
                <td class="droite">{{ $montant($ligne->total_ligne) }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

<table class="pied">
    <tr>
        <td style="width: 55%; padding-right: 24px;">
            <div class="notes-titre">NOTES</div>
            <div>{{ $commande->note ?: '—' }}</div>
        </td>
        <td style="width: 45%;">
            <table class="totaux">
                <tr><td class="cle">SOUS-TOTAL</td><td class="val">{{ $montant($commande->total_commande) }}</td></tr>
                <tr><td class="cle">TVA</td><td class="val">0</td></tr>
                <tr class="total"><td class="cle">TOTAL</td><td class="val strong">{{ $montant($commande->total_commande) }}</td></tr>
            </table>
        </td>
    </tr>
</table>

@if($commande->isAnnulee())
    <div class="encadre">
        <div class="encadre-titre danger">Bon de commande annulé</div>
        <div>{{ $commande->motif_annulation }}</div>
        @if($commande->annulee_at)
            <div class="muted">Le {{ $commande->annulee_at->format('d/m/Y à H:i') }}</div>
        @endif
    </div>
@elseif($commande->isValidee())
    <div class="encadre">
        <div class="encadre-titre ok">Bon de commande validé</div>
        <div>
            Le {{ $commande->validee_at->format('d/m/Y à H:i') }}
            @if($commande->valideePar) par {{ trim($commande->valideePar->prenom.' '.$commande->valideePar->nom) }} @endif
            @if($regle)
                — rôle {{ $regle['role_label'] ?? $regle['role'] ?? '' }},
                {{ ($regle['plafond_illimite'] ?? false) ? 'sans limite' : 'plafond '.$montant($regle['plafond'] ?? 0) }}
            @endif
        </div>
    </div>
@else
    <div class="encadre">
        <div class="encadre-titre attention">En attente de validation</div>
        <div>Ce document n'engage pas l'entreprise tant qu'il n'a pas été validé.</div>
    </div>
@endif

<div class="bas">
    <table>
        <tr>
            <td>{{ $organisation->name }} — {{ $commande->reference }}</td>
            <td style="text-align: right;">Généré le {{ now()->format('d/m/Y à H:i') }}</td>
        </tr>
    </table>
</div>

</body>
</html>
