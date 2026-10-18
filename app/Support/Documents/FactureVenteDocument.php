<?php

namespace App\Support\Documents;

use App\Enums\ModeTarification;
use App\Models\CommandeVente;
use App\Models\CommandeVenteLigne;
use App\Models\FactureVente;
use App\Models\Organization;
use App\Support\PhoneFormatter;

/**
 * Facture de vente téléchargeable depuis /backoffice/factures. Montants et quantités repris tels
 * que tenus par le métier, jamais recalculés : `total_ligne` et le prix du mode de tarification
 * figé (CommandeVenteService, CommandeVenteRetourService, PrecommandeService), `montant_net`,
 * l'encaissé (acomptes compris) et les remboursements de la facture (ADR 0019). Aucune TVA : la
 * vente n'en porte pas. Filigrane « ANNULÉE » pour une facture annulée.
 */
final class FactureVenteDocument
{
    public static function depuis(FactureVente $facture, Organization $organisation): DocumentPdf
    {
        $facture->loadMissing(['site', 'encaissements', 'commande.site', 'commande.client', 'commande.vehicule', 'commande.lignes.variante.produit']);
        $commande = $facture->commande;

        $metadonnees = [
            ['cle' => 'DATE', 'valeur' => (string) $facture->created_at?->format('d/m/Y')],
            ['cle' => 'FACTURE N°', 'valeur' => (string) $facture->reference],
        ];
        if ($commande && $commande->reference !== $facture->reference) {
            $metadonnees[] = ['cle' => 'COMMANDE N°', 'valeur' => (string) $commande->reference];
        }
        $metadonnees[] = ['cle' => 'STATUT', 'valeur' => $facture->statut_label];

        return new DocumentPdf(
            type: 'facture_vente',
            vue: 'pdf.facture_vente',
            titre: 'FACTURE',
            reference: (string) $facture->reference,
            organisation: IdentiteOrganisation::depuis($organisation),
            lignesAgence: IdentiteOrganisation::lignesAgence($facture->site ?? $commande?->site),
            metadonnees: $metadonnees,
            parties: self::parties($commande),
            colonnes: DocumentPdf::colonnesStandard('Désignation', 'Total'),
            lignes: ($commande?->lignes ?? collect())->map(fn (CommandeVenteLigne $ligne) => [
                'cellules' => [
                    $ligne->libelle_snapshot ?? $ligne->variante?->produit?->nom ?? '—',
                    (string) self::quantiteFacturee($ligne),
                    DocumentPdf::montant(self::prixUnitaire($commande, $ligne)),
                    DocumentPdf::montant($ligne->total_ligne),
                ],
                'detail' => null,
            ])->values()->all(),
            totaux: self::totaux($facture),
            filigrane: $facture->isAnnulee() ? 'ANNULÉE' : null,
        );
    }

    /**
     * Quantité qui a servi au dernier calcul de `total_ligne` : livrée (réception ou retour),
     * sinon chargée, sinon préparée (précommande), sinon demandée.
     */
    private static function quantiteFacturee(CommandeVenteLigne $ligne): int
    {
        return (int) ($ligne->quantite_livree ?? $ligne->quantite_chargee ?? $ligne->quantite_preparee ?? $ligne->quantite_demandee ?? 0);
    }

    private static function prixUnitaire(CommandeVente $commande, CommandeVenteLigne $ligne): float
    {
        return $commande->mode_tarification_snapshot === ModeTarification::PRIX_USINE
            ? (float) $ligne->prix_usine_snapshot
            : (float) $ligne->prix_vente_snapshot;
    }

    /** @return list<array{titre: string, lignes: list<string>}> */
    private static function parties(?CommandeVente $commande): array
    {
        $parties = [];

        if ($client = $commande?->client) {
            $parties[] = ['titre' => 'CLIENT', 'lignes' => array_values(array_filter([
                $client->nom_complet ?: '—',
                $client->telephone ? PhoneFormatter::display($client->telephone) : null,
                collect([$client->adresse, $client->ville, $client->pays])->filter()->implode(', ') ?: null,
            ]))];
        }

        if ($vehicule = $commande?->vehicule) {
            $parties[] = ['titre' => 'VÉHICULE', 'lignes' => array_values(array_filter([
                $vehicule->nom_vehicule ?: '—',
                $vehicule->immatriculation ?: null,
            ]))];
        }

        return $parties ?: [['titre' => 'CLIENT', 'lignes' => ['—']]];
    }

    /** @return list<array{cle: string, valeur: string}> */
    private static function totaux(FactureVente $facture): array
    {
        $totaux = [];
        if (round((float) $facture->montant_brut, 2) !== round((float) $facture->montant_net, 2)) {
            $totaux[] = ['cle' => 'MONTANT BRUT', 'valeur' => DocumentPdf::montant($facture->montant_brut)];
        }
        $totaux[] = ['cle' => 'TOTAL', 'valeur' => DocumentPdf::montant($facture->montant_net)];

        $acomptes = (float) $facture->encaissements->where('est_acompte', true)->sum('montant');
        $totaux[] = ['cle' => 'ENCAISSÉ', 'valeur' => DocumentPdf::montant($facture->montant_encaisse)];
        if ($acomptes > 0) {
            $totaux[] = ['cle' => 'DONT ACOMPTES', 'valeur' => DocumentPdf::montant($acomptes)];
        }
        if ($facture->montantRembourse() > 0) {
            $totaux[] = ['cle' => 'REMBOURSÉ', 'valeur' => DocumentPdf::montant($facture->montantRembourse())];
        }
        if (! $facture->isAnnulee()) {
            $totaux[] = ['cle' => 'RESTE À PAYER', 'valeur' => DocumentPdf::montant($facture->montant_restant)];
        }
        if ($facture->tropPercu() > 0) {
            $totaux[] = ['cle' => 'TROP-PERÇU À RENDRE', 'valeur' => DocumentPdf::montant($facture->tropPercu())];
        }

        return $totaux;
    }
}
