<?php

namespace App\Support\Achats;

use App\Models\CommandeAchat;
use App\Models\FactureFournisseur;
use App\Models\FactureFournisseurLigne;
use App\Services\Achats\FactureFournisseurService;

/**
 * Données affichées par le formulaire et la fiche d'une facture fournisseur : réceptions du bon
 * et, par ligne reçue, quantités commandée / reçue / déjà facturée / encore facturable.
 */
class FactureFournisseurPresenter
{
    public function __construct(private readonly FactureFournisseurService $service) {}

    public function commande(CommandeAchat $commande): array
    {
        return [
            'id' => $commande->id,
            'reference' => $commande->reference,
            'fournisseur_id' => $commande->fournisseur_id,
            'fournisseur_nom' => $commande->fournisseurNom(),
            'site_nom' => $commande->siteNom(),
        ];
    }

    /** Réceptions du bon, avec les quantités facturables de chaque ligne reçue. */
    public function receptions(CommandeAchat $commande, ?string $exclureFactureId = null): array
    {
        return $this->service->lignesFacturables($commande, $exclureFactureId)
            ->groupBy(fn (array $i) => $i['ligne']->reception_achat_id)
            ->map(function ($infos) {
                $reception = $infos->first()['ligne']->reception;

                return [
                    'id' => $reception->id,
                    'reference' => $reception->reference,
                    'date_reception' => $reception->date_reception?->format('d/m/Y'),
                    'lignes' => $infos->map(fn (array $i) => [
                        'id' => $i['ligne']->id,
                        'produit_nom' => $i['ligne']->commandeLigne?->libelle_snapshot ?? '—',
                        'reference' => $i['ligne']->commandeLigne?->reference_snapshot,
                        'qte_commandee' => (int) $i['ligne']->commandeLigne?->qte,
                        'qte_recue' => (int) $i['ligne']->qte_recue,
                        'deja_facture' => $i['deja_facture'],
                        'facturable' => $i['facturable'],
                        'cout_unitaire' => (float) $i['ligne']->cout_unitaire,
                    ])->values(),
                ];
            })
            ->sortBy('date_reception')
            ->values()
            ->all();
    }

    public function facture(FactureFournisseur $facture): array
    {
        $facture->loadMissing(['lignes.receptionLigne.reception', 'lignes.commandeLigne', 'createdBy', 'valideePar', 'annuleePar']);
        $facturables = $this->service->lignesFacturables($facture->commande, $facture->id);

        return [
            'id' => $facture->id,
            'reference' => $facture->reference,
            'numero_facture_fournisseur' => $facture->numero_facture_fournisseur,
            'date_facture' => $facture->date_facture?->format('Y-m-d'),
            'date_echeance' => $facture->date_echeance?->format('Y-m-d'),
            'taux_tva' => (float) $facture->taux_tva,
            'montant_ht' => (float) $facture->montant_ht,
            'montant_tva' => (float) $facture->montant_tva,
            'montant_ttc' => (float) $facture->montant_ttc,
            'montant_paye' => (float) $facture->montant_paye,
            'reste_du' => $facture->resteDu(),
            'statut' => $facture->statut?->value,
            'statut_label' => $facture->statut?->label(),
            'note' => $facture->note,
            'fournisseur_nom' => $facture->fournisseurNom(),
            'created_by' => $this->nom($facture->createdBy),
            'created_at' => $facture->created_at?->format('d/m/Y H:i'),
            'validee_par' => $this->nom($facture->valideePar),
            'validee_at' => $facture->validee_at?->format('d/m/Y H:i'),
            'annulee_par' => $this->nom($facture->annuleePar),
            'annulee_at' => $facture->annulee_at?->format('d/m/Y H:i'),
            'motif_annulation' => $facture->motif_annulation,
            'receptions' => $facture->lignes
                ->map(fn (FactureFournisseurLigne $l) => $l->receptionLigne?->reception)
                ->filter()
                ->unique('id')
                ->map(fn ($r) => ['id' => $r->id, 'reference' => $r->reference, 'date_reception' => $r->date_reception?->format('d/m/Y')])
                ->values(),
            'lignes' => $facture->lignes->map(function (FactureFournisseurLigne $l) use ($facturables) {
                $info = $facturables->get($l->reception_achat_ligne_id);

                return [
                    'reception_ligne_id' => $l->reception_achat_ligne_id,
                    'reception_reference' => $l->receptionLigne?->reception?->reference,
                    'produit_nom' => $l->libelle_snapshot ?? '—',
                    'reference' => $l->reference_snapshot,
                    'qte_commandee' => (int) $l->commandeLigne?->qte,
                    'qte_recue' => (int) $l->receptionLigne?->qte_recue,
                    'deja_facture_ailleurs' => (int) ($info['deja_facture'] ?? 0),
                    'qte' => (int) $l->qte_facturee,
                    'prix_unitaire' => (float) $l->prix_unitaire,
                    'cout_reception' => (float) $l->receptionLigne?->cout_unitaire,
                    'total_ht' => (float) $l->total_ht,
                ];
            })->values(),
        ];
    }

    private function nom($user): ?string
    {
        return $user ? trim($user->prenom.' '.$user->nom) : null;
    }
}
