<?php

namespace App\Support\Documents;

use App\Enums\StatutFactureFournisseur;
use App\Models\FactureFournisseur;
use App\Models\Organization;

/**
 * Facture d'achat : récapitulatif de la facture enregistrée dans l'application, pas l'original du
 * fournisseur — la mention figure en pied de chaque page. Filigrane « BROUILLON » tant que la
 * facture n'est pas validée, « ANNULÉE » si elle est annulée.
 */
final class FactureAchatDocument
{
    public const MENTION = 'Ce document n’est pas l’original du fournisseur.';

    public static function depuis(FactureFournisseur $facture, Organization $organisation): DocumentPdf
    {
        $facture->loadMissing(['site', 'fournisseur', 'lignes']);
        $fournisseur = $facture->fournisseur;
        $taux = rtrim(rtrim(number_format((float) $facture->taux_tva, 2, ',', ' '), '0'), ',');
        $adresseFournisseur = collect([$fournisseur?->adresse, $fournisseur?->ville, $fournisseur?->pays])->filter()->implode(', ');

        return new DocumentPdf(
            type: 'facture_achat',
            vue: 'pdf.facture_achat',
            titre: 'FACTURE D’ACHAT',
            reference: (string) $facture->reference,
            organisation: IdentiteOrganisation::depuis($organisation),
            lignesAgence: IdentiteOrganisation::lignesAgence($facture->site),
            metadonnees: [
                ['cle' => 'DATE', 'valeur' => (string) $facture->date_facture?->format('d/m/Y')],
                ['cle' => 'FACTURE N°', 'valeur' => (string) $facture->reference],
                ['cle' => 'N° FOURNISSEUR', 'valeur' => $facture->numero_facture_fournisseur ?: 'Sans numéro'],
            ],
            parties: [[
                'titre' => 'FOURNISSEUR',
                'lignes' => array_values(array_filter([$facture->fournisseurNom() ?? '—', $adresseFournisseur ?: null])),
            ]],
            colonnes: DocumentPdf::colonnesStandard('Description', 'Total HT'),
            lignes: $facture->lignes->map(fn ($ligne) => [
                'cellules' => [
                    $ligne->libelle_snapshot ?? '—',
                    (string) $ligne->qte_facturee,
                    DocumentPdf::montant($ligne->prix_unitaire),
                    DocumentPdf::montant($ligne->total_ht),
                ],
                'detail' => null,
            ])->values()->all(),
            totaux: [
                ['cle' => 'TOTAL HT', 'valeur' => DocumentPdf::montant($facture->montant_ht)],
                ['cle' => 'TVA ('.$taux.' %)', 'valeur' => DocumentPdf::montant($facture->montant_tva)],
                ['cle' => 'TOTAL TTC', 'valeur' => DocumentPdf::montant($facture->montant_ttc)],
            ],
            notes: $facture->note ?: null,
            filigrane: match (true) {
                $facture->statut === StatutFactureFournisseur::ANNULEE => 'ANNULÉE',
                $facture->isBrouillon() => 'BROUILLON',
                default => null,
            },
            mention: self::MENTION,
        );
    }
}
