<?php

namespace App\Services\Commission;

use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Source unique de validation du partage Livreur (montants GNF entiers fixes
 * par membre d'équipe, par catégorie) — appelée à l'enregistrement de l'équipe
 * (EquipeLivraisonController) ET à chaque génération de commission
 * (CommissionEnveloppeGenerator), jamais dupliquée ailleurs.
 *
 * Remplace CommissionRepartitionEngine pour cette seule cible (équipe
 * livraison, vente). CommissionRepartitionEngine reste inchangé pour tout
 * autre flux (ex: CommissionGroupeMembre / équipe dépôt), hors périmètre.
 *
 * Égalité entière stricte, sans aucune tolérance : contrairement à l'ancien
 * partage en pourcentage (somme flottante devant approcher 100 % à ±0.01
 * près), une somme d'entiers comparée par égalité stricte ne peut jamais
 * diverger selon le point de calcul (JS frontend, contrôleur, moteur de
 * génération) — c'est précisément ce qui a fait échouer le partage en % dans
 * l'incident CMD-230826-004 (100,01 % accepté à la saisie, rejeté à la
 * génération, sans qu'aucune des deux vérifications n'ait été en tort).
 */
class CommissionPartageLivraisonValidator
{
    /**
     * @param  Collection<int, object{beneficiaire_id: mixed, montant_unitaire: int|float|string|null}>  $membres
     *
     * @throws InvalidArgumentException si un montant est invalide, un bénéficiaire apparaît en
     *                                  double, ou si la somme ne correspond pas exactement à l'enveloppe
     */
    public static function valider(Collection $membres, int $enveloppeUnitaire): void
    {
        if ($enveloppeUnitaire < 0) {
            throw new InvalidArgumentException("L'enveloppe Livreur ({$enveloppeUnitaire} GNF) ne peut pas être négative.");
        }

        if ($membres->isEmpty()) {
            // Barème Livreur à 0 : rien à répartir, aucun membre requis (valeur métier
            // valide, jamais une erreur — cf. décision AMOA #4 appliquée ailleurs).
            if ($enveloppeUnitaire === 0) {
                return;
            }

            throw new InvalidArgumentException('Aucun membre pour répartir cette enveloppe Livreur.');
        }

        $vus = [];
        $somme = 0;

        foreach ($membres as $membre) {
            $beneficiaireId = (string) $membre->beneficiaire_id;

            if (isset($vus[$beneficiaireId])) {
                throw new InvalidArgumentException("Le bénéficiaire {$beneficiaireId} apparaît plusieurs fois dans le partage.");
            }
            $vus[$beneficiaireId] = true;

            $montant = $membre->montant_unitaire;

            if ($montant === null || $montant === '') {
                throw new InvalidArgumentException("Le membre {$beneficiaireId} n'a pas de montant fixe défini.");
            }

            if (! is_int($montant) && (float) $montant != (int) $montant) {
                throw new InvalidArgumentException("Le montant du membre {$beneficiaireId} doit être un entier GNF, sans décimales.");
            }

            $montant = (int) $montant;

            if ($montant < 0) {
                throw new InvalidArgumentException("Le montant du membre {$beneficiaireId} ne peut pas être négatif.");
            }

            $somme += $montant;
        }

        if ($somme !== $enveloppeUnitaire) {
            $ecart = $enveloppeUnitaire - $somme;

            throw new InvalidArgumentException($ecart > 0
                ? "Il reste {$ecart} GNF à attribuer sur l'enveloppe Livreur de {$enveloppeUnitaire} GNF (attribué : {$somme} GNF)."
                : sprintf(
                    "Dépassement de %d GNF sur l'enveloppe Livreur de %d GNF (attribué : %d GNF).",
                    abs($ecart), $enveloppeUnitaire, $somme
                ));
        }
    }
}
