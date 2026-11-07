<?php

namespace App\Services\Client\Operations;

use App\Enums\StatutCommandeVente;
use App\Enums\StatutFactureVente;
use App\Enums\StatutTransfert;
use App\Models\Client;
use App\Models\CommandeVente;
use App\Models\TransfertLogistique;

/**
 * Traduction des opérations pour l'espace client (ADR 0058), sans toucher aux statuts ni aux règles
 * comptables du back-office. L'avancement et le paiement restent deux informations indépendantes :
 * une vente standard passe `livree` au premier encaissement, sans aucune preuve de remise physique,
 * donc seule une réception validée s'affiche « Livrée ».
 */
final class PresentationOperation
{
    /** @return array{code: string, label: string} */
    public static function natureVente(CommandeVente $vente): array
    {
        if ($vente->est_precommande) {
            return ['code' => 'precommande', 'label' => 'Précommande'];
        }

        return [
            'code' => $vente->nature_operation?->value ?? 'vente_standard',
            'label' => $vente->nature_operation?->label() ?? 'Vente',
        ];
    }

    /** @return array{code: string, label: string} */
    public static function natureTransfert(): array
    {
        return ['code' => 'transfert', 'label' => 'Transfert logistique'];
    }

    /** @return array{code: string, label: string} */
    public static function avancementVente(CommandeVente $vente): array
    {
        return match ($vente->statut) {
            StatutCommandeVente::BROUILLON => ['code' => 'brouillon', 'label' => 'Brouillon'],
            StatutCommandeVente::RESERVEE => ['code' => 'creee', 'label' => 'Créée'],
            StatutCommandeVente::A_PREPARER => ['code' => 'preparation_en_cours', 'label' => 'Préparation en cours'],
            StatutCommandeVente::PREPAREE => ['code' => 'prete_au_retrait', 'label' => 'Prête au retrait'],
            StatutCommandeVente::A_CHARGER,
            StatutCommandeVente::CHARGEMENT_EN_COURS => ['code' => 'en_preparation', 'label' => 'En préparation'],
            StatutCommandeVente::LIVRAISON_EN_COURS => ['code' => 'en_cours_de_livraison', 'label' => 'En cours de livraison'],
            StatutCommandeVente::LIVREE => $vente->requiertReceptionExplicite()
                ? ['code' => 'livree', 'label' => 'Livrée']
                : ['code' => 'en_cours_de_cloture', 'label' => 'En cours de clôture'],
            StatutCommandeVente::FACTURATION => $vente->estPrecommandeRetiree()
                ? ['code' => 'retiree', 'label' => CommandeVente::LIBELLE_RETIREE]
                : ['code' => 'en_cours_de_cloture', 'label' => 'En cours de clôture'],
            StatutCommandeVente::CLOTUREE => ['code' => 'terminee', 'label' => 'Terminée'],
            StatutCommandeVente::ANNULEE,
            StatutCommandeVente::ANNULEE_ERREUR_SAISIE => ['code' => 'annulee', 'label' => 'Annulée'],
            StatutCommandeVente::RETOURNEE => ['code' => 'retournee', 'label' => 'Retournée'],
        };
    }

    /** @return array{code: string, label: string} */
    public static function avancementTransfert(TransfertLogistique $transfert): array
    {
        return match ($transfert->statut) {
            StatutTransfert::BROUILLON,
            StatutTransfert::CHARGEMENT => ['code' => 'en_preparation', 'label' => 'En préparation'],
            StatutTransfert::TRANSIT => ['code' => 'en_cours_de_livraison', 'label' => 'En cours de livraison'],
            StatutTransfert::RECEPTION => ['code' => 'receptionne', 'label' => 'Réceptionné'],
            StatutTransfert::CLOTURE => ['code' => 'termine', 'label' => 'Terminé'],
            StatutTransfert::ANNULE => ['code' => 'annule', 'label' => 'Annulé'],
            StatutTransfert::RETOURNE => ['code' => 'retourne', 'label' => 'Retourné'],
        };
    }

    /**
     * Réservé au client et au propriétaire : un livreur seul ne voit jamais le paiement. La facture
     * d'une précommande reste « Créée » malgré un acompte, d'où le libellé dédié.
     *
     * @param  list<string>  $liens
     * @return array{statut: string|null, label: string|null}
     */
    public static function paiementVente(CommandeVente $vente, array $liens): array
    {
        $facture = $vente->facture;

        if ($facture === null || ! self::voitLesMontants($liens)) {
            return ['statut' => null, 'label' => null];
        }

        if ($vente->est_precommande && $facture->statut_facture === StatutFactureVente::CREEE && $facture->encaisseNet() > 0) {
            return ['statut' => 'acompte_recu', 'label' => 'Acompte reçu'];
        }

        return ['statut' => $facture->statut_facture?->value, 'label' => $facture->statut_facture?->label()];
    }

    /**
     * Remise physique, seulement quand le système la connaît : réception validée ou retrait validé.
     * `remise_at` d'une précommande livrée date le chargement, jamais la remise au client.
     *
     * @return array{date: string|null, source: string}|null
     */
    public static function remise(CommandeVente $vente): ?array
    {
        if ($vente->requiertReceptionExplicite() && $vente->livree_at !== null) {
            return ['date' => $vente->livree_at->toDateString(), 'source' => 'reception'];
        }

        if ($vente->est_precommande && $vente->vehicule_id === null && $vente->remise_at !== null) {
            return ['date' => $vente->remise_at->toDateString(), 'source' => 'retrait'];
        }

        return null;
    }

    /** @param  list<string>  $liens */
    public static function voitLesMontants(array $liens): bool
    {
        return in_array(OperationsCompte::LIEN_CLIENT, $liens, true)
            || in_array(OperationsCompte::LIEN_PROPRIETAIRE, $liens, true);
    }

    /** @param  list<string>  $liens */
    public static function voitLesPrix(array $liens): bool
    {
        return in_array(OperationsCompte::LIEN_CLIENT, $liens, true);
    }

    /**
     * Le client garde ses propres coordonnées ; le livreur ne les voit que pendant la livraison.
     *
     * @param  list<string>  $liens
     */
    public static function voitLeContact(CommandeVente $vente, array $liens): bool
    {
        return in_array(OperationsCompte::LIEN_CLIENT, $liens, true)
            || (in_array(OperationsCompte::LIEN_LIVREUR, $liens, true) && $vente->isLivraisonEnCours());
    }

    public static function adresse(?Client $client): ?string
    {
        return implode(', ', array_filter([$client?->adresse, $client?->ville])) ?: null;
    }
}
