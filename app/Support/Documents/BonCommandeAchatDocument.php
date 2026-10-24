<?php

namespace App\Support\Documents;

use App\Models\CommandeAchat;
use App\Models\Organization;
use App\Support\PhoneFormatter;

/**
 * Bon de commande fournisseur (ADR 0021). Filigrane « NON VALIDÉ » tant que le bon n'a pas été
 * validé, « ANNULÉ » s'il est annulé. Une fois validé, fournisseur, agence et libellés viennent du
 * snapshot figé à la validation ; l'encadré reprend la règle de validation appliquée.
 */
final class BonCommandeAchatDocument
{
    private const DATE_HEURE = 'd/m/Y à H:i';

    public static function depuis(CommandeAchat $commande, Organization $organisation): DocumentPdf
    {
        $commande->loadMissing(['fournisseur', 'lignes.variante.produit', 'createdBy', 'valideePar', 'site', 'sitePayeur']);
        $fournisseur = $commande->fournisseur;
        $emetteur = $commande->createdBy ? trim($commande->createdBy->prenom.' '.$commande->createdBy->nom) : '—';

        $lignesAgence = [];
        if ($commande->siteNom()) {
            $lignesAgence[] = 'Agence de livraison : '.$commande->siteNom();
            if ($commande->estPayeParUneAutreAgence()) {
                $lignesAgence[] = 'Payé par : '.$commande->sitePayeurNom();
            }
        }

        // Un numéro déjà international (+224…) ne reçoit pas une seconde fois l'indicatif.
        $telephone = match (true) {
            ! $fournisseur?->phone => null,
            str_starts_with($fournisseur->phone, '+') => PhoneFormatter::display($fournisseur->phone),
            default => trim(($fournisseur->code_phone_pays ? $fournisseur->code_phone_pays.' ' : '').$fournisseur->phone),
        };

        return new DocumentPdf(
            type: 'bon_commande_achat',
            vue: 'pdf.bon_commande_achat',
            titre: 'BON DE COMMANDE D’ACHAT',
            reference: (string) $commande->reference,
            organisation: IdentiteOrganisation::depuis($organisation),
            lignesAgence: $lignesAgence,
            metadonnees: [
                ['cle' => 'DATE', 'valeur' => (string) $commande->dateAchat()?->format('d/m/Y')],
                ['cle' => 'BON N°', 'valeur' => (string) $commande->reference],
                ['cle' => 'STATUT', 'valeur' => (string) $commande->statut_label],
            ],
            parties: [
                [
                    'titre' => 'FOURNISSEUR',
                    'lignes' => array_values(array_filter([$commande->fournisseurNom() ?? '—', $telephone, $fournisseur?->email ?: null])),
                ],
                [
                    'titre' => 'LIVRER À',
                    'lignes' => [$commande->siteNom() ?? '—', (string) $organisation->name, 'Émis par '.$emetteur],
                ],
            ],
            colonnes: DocumentPdf::colonnesStandard('Désignation', 'Total ligne'),
            lignes: $commande->lignes->map(fn ($ligne) => [
                'cellules' => [
                    $ligne->libelle_snapshot ?? $ligne->variante?->produit?->nom ?? '—',
                    (string) $ligne->qte,
                    DocumentPdf::montant($ligne->prix_achat_snapshot),
                    DocumentPdf::montant($ligne->total_ligne),
                ],
                'detail' => $ligne->reference_snapshot ? 'Réf. '.$ligne->reference_snapshot : null,
            ])->values()->all(),
            totaux: [
                ['cle' => 'SOUS-TOTAL', 'valeur' => DocumentPdf::montant($commande->total_commande)],
                ['cle' => 'TVA', 'valeur' => DocumentPdf::montant(0)],
                ['cle' => 'TOTAL', 'valeur' => DocumentPdf::montant($commande->total_commande)],
            ],
            notes: $commande->note ?: null,
            encadre: self::encadre($commande),
            filigrane: match (true) {
                $commande->isAnnulee() => 'ANNULÉ',
                $commande->isValidee() => null,
                default => 'NON VALIDÉ',
            },
            infoPied: 'Généré le '.now()->format(self::DATE_HEURE),
        );
    }

    /** @return array{ton: 'ok'|'attention'|'danger', titre: string, lignes: list<string>} */
    private static function encadre(CommandeAchat $commande): array
    {
        if ($commande->isAnnulee()) {
            return [
                'ton' => 'danger',
                'titre' => 'Bon de commande annulé',
                'lignes' => array_values(array_filter([
                    $commande->motif_annulation ?: null,
                    $commande->annulee_at ? 'Le '.$commande->annulee_at->format(self::DATE_HEURE) : null,
                ])),
            ];
        }

        if ($commande->isValidee()) {
            $regle = $commande->validation_regle_snapshot;
            $texte = 'Le '.$commande->validee_at?->format(self::DATE_HEURE);
            if ($commande->valideePar) {
                $texte .= ' par '.trim($commande->valideePar->prenom.' '.$commande->valideePar->nom);
            }
            if ($regle) {
                $texte .= ' — rôle '.($regle['role_label'] ?? $regle['role'] ?? '').', '
                    .(($regle['plafond_illimite'] ?? false) ? 'sans limite' : 'plafond '.DocumentPdf::montant($regle['plafond'] ?? 0));
            }

            return ['ton' => 'ok', 'titre' => 'Bon de commande validé', 'lignes' => [$texte]];
        }

        return [
            'ton' => 'attention',
            'titre' => 'En attente de validation',
            'lignes' => ['Ce document n\'engage pas l\'entreprise tant qu\'il n\'a pas été validé.'],
        ];
    }
}
