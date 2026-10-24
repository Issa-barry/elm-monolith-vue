<?php

namespace App\Support\Documents;

use App\Enums\StatutCommandeVente;
use App\Models\CommandeVente;
use App\Models\CommandeVenteLigne;
use App\Models\Organization;

/**
 * Bon de commande de vente, téléchargeable depuis /backoffice/ventes (liste et fiche). Mêmes
 * blocs CLIENT / VÉHICULE et même lecture des lignes que la facture de vente : quantité et
 * `total_ligne` tenus par le métier, prix du mode de tarification figé, total = `total_commande`.
 * La quantité commandée est rappelée sous la désignation quand elle diffère de la quantité
 * retenue (chargement, livraison, retour). Aucune TVA. Filigrane selon le statut réel.
 */
final class BonCommandeVenteDocument
{
    public static function depuis(CommandeVente $commande, Organization $organisation): DocumentPdf
    {
        $commande->loadMissing(['site', 'client', 'vehicule', 'facture', 'lignes.variante.produit']);

        $metadonnees = [
            ['cle' => 'DATE', 'valeur' => (string) $commande->created_at?->format('d/m/Y')],
            ['cle' => 'BON N°', 'valeur' => (string) $commande->reference],
            ['cle' => 'STATUT', 'valeur' => (string) $commande->statutAffichage()['label']],
        ];
        if ($commande->date_remise_prevue && $commande->estPrecommandeAvantRemise()) {
            $metadonnees[] = ['cle' => 'REMISE PRÉVUE', 'valeur' => $commande->date_remise_prevue->format('d/m/Y')];
        }

        return new DocumentPdf(
            type: 'bon_commande_vente',
            vue: 'pdf.bon_commande_vente',
            titre: 'BON DE COMMANDE DE VENTE',
            reference: (string) $commande->reference,
            organisation: IdentiteOrganisation::depuis($organisation),
            lignesAgence: IdentiteOrganisation::lignesAgence($commande->site),
            metadonnees: $metadonnees,
            parties: CommandeVenteDonneesDocument::parties($commande),
            colonnes: DocumentPdf::colonnesStandard('Désignation', 'Total'),
            lignes: $commande->lignes->map(fn (CommandeVenteLigne $ligne) => [
                'cellules' => [
                    CommandeVenteDonneesDocument::designation($ligne),
                    (string) CommandeVenteDonneesDocument::quantiteRetenue($ligne),
                    DocumentPdf::montant(CommandeVenteDonneesDocument::prixUnitaire($commande, $ligne)),
                    DocumentPdf::montant($ligne->total_ligne),
                ],
                'detail' => self::detail($ligne),
            ])->values()->all(),
            totaux: [['cle' => 'TOTAL', 'valeur' => DocumentPdf::montant($commande->total_commande)]],
            filigrane: self::filigrane($commande),
            infoPied: 'Généré le '.now()->format('d/m/Y à H:i'),
        );
    }

    private static function detail(CommandeVenteLigne $ligne): ?string
    {
        $commandee = $ligne->quantite_demandee;

        return $commandee !== null && (int) $commandee !== CommandeVenteDonneesDocument::quantiteRetenue($ligne)
            ? 'Quantité commandée : '.$commandee
            : null;
    }

    private static function filigrane(CommandeVente $commande): ?string
    {
        return match ($commande->statut) {
            StatutCommandeVente::BROUILLON => 'BROUILLON',
            StatutCommandeVente::ANNULEE, StatutCommandeVente::ANNULEE_ERREUR_SAISIE => 'ANNULÉE',
            StatutCommandeVente::RETOURNEE => 'RETOURNÉE',
            default => null,
        };
    }
}
