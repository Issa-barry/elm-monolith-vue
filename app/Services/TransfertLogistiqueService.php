<?php

namespace App\Services;

use App\Enums\StatutTransfert;
use App\Models\ProduitVariante;
use App\Models\TransfertLogistique;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TransfertLogistiqueService
{
    /**
     * Workflow : BROUILLON → CHARGEMENT → TRANSIT → RECEPTION → CLOTURE
     *
     * @throws ValidationException si les pré-conditions ne sont pas satisfaites
     */
    public static function avancerStatut(TransfertLogistique $transfert): TransfertLogistique
    {
        $suivant = match ($transfert->statut) {
            StatutTransfert::BROUILLON => StatutTransfert::CHARGEMENT,
            StatutTransfert::CHARGEMENT => StatutTransfert::TRANSIT,
            StatutTransfert::TRANSIT => StatutTransfert::RECEPTION,
            default => null,
        };

        if ($suivant === null) {
            return $transfert;
        }

        self::validerPreconditions($transfert, $suivant);

        DB::transaction(function () use ($transfert, $suivant) {
            $updates = ['statut' => $suivant->value];

            // Départ réel enregistré quand le camion part (TRANSIT)
            if ($suivant === StatutTransfert::TRANSIT) {
                $updates['date_depart_reelle'] = now()->toDateString();
            }

            // Arrivée réelle enregistrée à la RECEPTION
            if ($suivant === StatutTransfert::RECEPTION) {
                $updates['date_arrivee_reelle'] = now()->toDateString();
            }

            $transfert->update($updates);

            // Sortie stock source : marchandises physiquement parties (TRANSIT)
            if ($suivant === StatutTransfert::TRANSIT) {
                MouvementStockService::enregistrerSortieSource($transfert);

                // Chargement validé — génère la commission logistique si le paramètre
                // organisation est CHARGEMENT_VALIDE (cf. CommissionTriggerService).
                // Sous RECEPTION_EFFECTUEE (défaut), n'a aucun effet : la génération
                // reste portée par la validation admin de la réception.
                CommissionTriggerService::onTransfertChargementValide($transfert);
            }

            // Entrée stock destination : marchandises reçues (RECEPTION)
            if ($suivant === StatutTransfert::RECEPTION) {
                MouvementStockService::enregistrerEntreeDestination($transfert);
            }
        });

        return $transfert->fresh();
    }

    /**
     * Clôturer automatiquement un transfert en RECEPTION une fois toutes les commissions versées.
     * Ne pas appeler manuellement : déclenché uniquement par VersementCommissionLogistiqueController.
     *
     * @throws \LogicException si le transfert n'est pas en RECEPTION ou si la commission est incomplète
     */
    public static function cloturerAutomatiquement(TransfertLogistique $transfert): TransfertLogistique
    {
        if ($transfert->statut !== StatutTransfert::RECEPTION) {
            throw new \LogicException('Seul un transfert en RECEPTION peut être clôturé automatiquement.');
        }

        $transfert->loadMissing('commission');

        if ($transfert->commission && ! $transfert->commission->isVersee()) {
            throw new \LogicException('La commission logistique n\'est pas encore entièrement versée.');
        }

        $transfert->update(['statut' => StatutTransfert::CLOTURE->value]);

        return $transfert->fresh();
    }

    /**
     * Annuler un transfert — autorisé uniquement en BROUILLON ou CHARGEMENT.
     * Silently no-op si le statut n'est pas éligible (la policy devrait déjà avoir bloqué).
     */
    public static function annuler(TransfertLogistique $transfert): TransfertLogistique
    {
        if (! in_array($transfert->statut, [StatutTransfert::BROUILLON, StatutTransfert::CHARGEMENT])) {
            return $transfert;
        }

        $transfert->update(['statut' => StatutTransfert::ANNULE->value]);

        return $transfert->fresh();
    }

    // ── Pré-conditions ────────────────────────────────────────────────────────

    public static function validerPreconditions(TransfertLogistique $transfert, StatutTransfert $cible): void
    {
        $errors = [];

        match ($cible) {
            StatutTransfert::CHARGEMENT => self::checkChargement($transfert, $errors),
            StatutTransfert::TRANSIT => self::checkTransit($transfert, $errors),
            StatutTransfert::RECEPTION => self::checkReception($transfert, $errors),
            default => null,
        };

        if (! empty($errors)) {
            throw ValidationException::withMessages(['statut' => $errors]);
        }
    }

    /**
     * BROUILLON → CHARGEMENT : véhicule + au moins une ligne requise.
     */
    private static function checkChargement(TransfertLogistique $t, array &$errors): void
    {
        $t->loadMissing('lignes');

        if ($t->lignes->isEmpty()) {
            $errors[] = 'Le transfert doit contenir au moins une ligne produit.';
        }

        if (! $t->vehicule_id) {
            $errors[] = 'Un véhicule doit être assigné avant de démarrer le chargement.';
        }
    }

    /**
     * CHARGEMENT → TRANSIT : toutes les quantités chargées doivent être renseignées, puis
     * (uniquement si c'est le cas) chaque ligne vérifiée contre le stock disponible du site
     * source — cf. checkDisponibiliteStockSource().
     */
    private static function checkTransit(TransfertLogistique $t, array &$errors): void
    {
        $t->loadMissing('lignes');

        $manquantes = $t->lignes->filter(fn ($l) => $l->quantite_chargee === null);

        if ($manquantes->isNotEmpty()) {
            $errors[] = 'Toutes les lignes doivent avoir une quantité chargée renseignée.';

            return;
        }

        self::checkDisponibiliteStockSource($t, $errors);
    }

    /**
     * Vérifie, ligne par ligne, que la quantité chargée ne dépasse pas le stock disponible du
     * site source — cf. verifierDisponibiliteLignes() ci-dessous, point d'entrée unique
     * réutilisé par TransfertLogistiqueController::store()/update() (création/modification,
     * 04/09/2026 — avant ce correctif, un transfert pouvait être créé avec une quantité
     * demandée supérieure au stock, le seul contrôle existant intervenait au chargement) ET ce
     * contrôle au chargement. Le stock a pu changer entre les deux étapes (autre transfert/vente
     * entre-temps) : chaque contrôle reste indispensable même si le précédent a déjà validé le
     * transfert à son étape.
     */
    private static function checkDisponibiliteStockSource(TransfertLogistique $t, array &$errors): void
    {
        $t->loadMissing('lignes');

        $lignes = $t->lignes->map(fn ($l) => [
            'variante_id' => $l->variante_id,
            'quantite' => $l->quantite_chargee,
        ])->all();

        self::verifierDisponibiliteLignes($t->site_source_id, $lignes, $errors);
    }

    /**
     * Cœur RÉUTILISABLE du contrôle de disponibilité — jamais dupliqué en logique dans les
     * contrôleurs. Vérifie que chaque ligne (variante_id => quantité) ne dépasse pas le stock
     * disponible du site source donné. Contrairement à l'équivalent côté vente
     * (CommandeVenteService::verifierDisponibiliteLignes()), AUCUN court-circuit
     * Parametre::isVentesAutoriseesSansStock() ici : ce paramètre est réservé au PDV et aux
     * commandes vente (décision produit du 23/08/2026), jamais aux transferts — un transfert
     * déplace un stock qui doit déjà exister physiquement ailleurs, contrairement à une vente
     * client. Avant le correctif du 23/08/2026, aucun contrôle n'existait au chargement :
     * MouvementStockService::appliquer() clampait silencieusement la sortie source à 0 (cf.
     * audit stock du 23/08/2026). Ignore les lignes dont le produit ne gère pas de stock (type
     * service). Appelée par :
     *  - TransfertLogistiqueController::store()/update() (création/modification, 04/09/2026) ;
     *  - checkDisponibiliteStockSource() ci-dessus (chargement).
     *
     * @param  array<int, array{variante_id: string, quantite: int}>  $lignes
     * @param  array<int, string>  $errors  Passé par référence, une entrée par ligne en anomalie.
     */
    public static function verifierDisponibiliteLignes(string $siteSourceId, array $lignes, array &$errors): void
    {
        if (empty($lignes)) {
            return;
        }

        $varianteIds = array_column($lignes, 'variante_id');
        $variantes = ProduitVariante::with('produit.produitType')
            ->whereIn('id', $varianteIds)
            ->get()
            ->keyBy('id');

        foreach ($lignes as $ligne) {
            $variante = $variantes->get($ligne['variante_id']);
            $produit = $variante?->produit;
            if (! $produit?->produitType?->gere_stock) {
                continue;
            }

            $disponible = MouvementStockService::quantiteDisponible($ligne['variante_id'], $siteSourceId);

            if ($ligne['quantite'] > $disponible) {
                $errors[] = "Stock insuffisant pour « {$produit->nom} » sur le site source : {$ligne['quantite']} demandés, {$disponible} disponibles.";
            }
        }
    }

    /**
     * TRANSIT → RECEPTION : toutes les quantités reçues et types d'écart requis.
     */
    private static function checkReception(TransfertLogistique $t, array &$errors): void
    {
        $t->loadMissing('lignes');

        $incompletes = $t->lignes->filter(
            fn ($l) => $l->quantite_recue === null || $l->ecart_type === null
        );

        if ($incompletes->isNotEmpty()) {
            $errors[] = 'Toutes les lignes doivent avoir une quantité reçue et un type d\'écart renseigné.';
        }
    }

    /**
     * RECEPTION → CLOTURE : commissions entièrement versées (si existantes).
     */
    private static function checkCloture(TransfertLogistique $t, array &$errors): void
    {
        $t->loadMissing(['lignes', 'commission']);

        if ($t->commission && ! $t->commission->isVersee()) {
            $errors[] = 'Les commissions logistiques doivent être entièrement versées avant la clôture.';
        }
    }
}
