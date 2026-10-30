<?php

namespace App\Support\Documents;

use App\Models\Organization;

/**
 * Contrat de travail généré (docs/rh-contrat-pdf.md, ADR 0044), dans le modèle officiel partagé.
 * Construit UNIQUEMENT depuis l'instantané figé à la génération (App\Services\Rh\
 * DocumentContratService::instantane()) : une modification ultérieure du dossier RH, du contrat
 * ou du modèle ne change jamais un contrat déjà généré.
 */
final class ContratDocument
{
    /**
     * @param  array{reference: string, version: int, type_libelle: string, intitule: string, genere_le: string,
     *     employeur: array{nom: string, representant_nom: ?string, representant_qualite: ?string},
     *     salarie: array{nom: string, lignes: list<string>, matricule: ?string},
     *     lignes_agence: list<string>, articles: list<array{titre: string, paragraphes: list<string>}>,
     *     modele: array{titre: string, revision: int}}  $instantane
     */
    public static function depuis(array $instantane, Organization $organisation): DocumentPdf
    {
        $employeur = $instantane['employeur'];
        $representant = trim(implode(', ', array_filter([$employeur['representant_nom'] ?? null, $employeur['representant_qualite'] ?? null])));

        return new DocumentPdf(
            type: 'contrat_travail',
            vue: 'pdf.contrat_travail',
            titre: 'CONTRAT DE TRAVAIL',
            reference: $instantane['reference'],
            organisation: IdentiteOrganisation::depuis($organisation),
            lignesAgence: $instantane['lignes_agence'],
            metadonnees: [
                ['cle' => 'TYPE', 'valeur' => $instantane['type_libelle']],
                ['cle' => 'MATRICULE', 'valeur' => $instantane['salarie']['matricule'] ?: '—'],
                ['cle' => 'VERSION', 'valeur' => 'V'.$instantane['version']],
            ],
            parties: [
                ['titre' => 'EMPLOYEUR', 'lignes' => array_values(array_filter([
                    $employeur['nom'],
                    $representant !== '' ? 'Représenté par '.$representant : null,
                ]))],
                ['titre' => 'SALARIÉ', 'lignes' => $instantane['salarie']['lignes']],
            ],
            colonnes: [],
            lignes: [],
            totaux: [],
            mention: $instantane['reference'].' — modèle révision '.$instantane['modele']['revision'],
            infoPied: 'Généré le '.$instantane['genere_le'],
            intitule: $instantane['intitule'],
            articles: $instantane['articles'],
            signatures: [
                ['titre' => 'L’employeur', 'lignes' => array_values(array_filter([
                    $representant !== '' ? $representant : null,
                    'Date, signature et cachet',
                ]))],
                ['titre' => 'Le salarié', 'lignes' => [
                    $instantane['salarie']['nom'],
                    'Date et signature, précédées de « Lu et approuvé »',
                ]],
            ],
        );
    }
}
