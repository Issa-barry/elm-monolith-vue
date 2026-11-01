<?php

namespace App\Support\Rh\Contrats;

use App\Enums\TypeContrat;

/**
 * Variables utilisables dans le texte d'un modèle de contrat (ADR 0044), écrites `{{cle}}`.
 *
 * - `dossier` : reprise du dossier RH et du contrat à la génération ;
 * - `saisie` : complétée par l'utilisateur au moment de la génération (absente de l'application) ;
 * - `avantages` : primes et avantages validés du salarié, cochés à la génération.
 *
 * Une variable inconnue est refusée à l'enregistrement du modèle ; une variable utilisée et vide
 * bloque la génération : le document n'est jamais produit avec un trou ou une valeur inventée.
 */
final class VariablesContrat
{
    /** @var array<string, array{libelle: string, groupe: string, source: string, types?: list<string>}> */
    public const CATALOGUE = [
        'employeur.nom' => ['libelle' => 'Nom de l’employeur (organisation)', 'groupe' => 'Employeur', 'source' => 'dossier'],
        'employeur.representant_nom' => ['libelle' => 'Représentant de l’employeur — nom', 'groupe' => 'Employeur', 'source' => 'saisie'],
        'employeur.representant_qualite' => ['libelle' => 'Représentant de l’employeur — qualité', 'groupe' => 'Employeur', 'source' => 'saisie'],

        'salarie.civilite' => ['libelle' => 'Civilité', 'groupe' => 'Salarié', 'source' => 'dossier'],
        'salarie.nom_complet' => ['libelle' => 'Prénom et nom', 'groupe' => 'Salarié', 'source' => 'dossier'],
        'salarie.prenom' => ['libelle' => 'Prénom', 'groupe' => 'Salarié', 'source' => 'dossier'],
        'salarie.nom' => ['libelle' => 'Nom', 'groupe' => 'Salarié', 'source' => 'dossier'],
        'salarie.matricule' => ['libelle' => 'Matricule', 'groupe' => 'Salarié', 'source' => 'dossier'],
        'salarie.date_naissance' => ['libelle' => 'Date de naissance', 'groupe' => 'Salarié', 'source' => 'dossier'],
        'salarie.lieu_naissance' => ['libelle' => 'Lieu de naissance', 'groupe' => 'Salarié', 'source' => 'dossier'],
        'salarie.nationalite' => ['libelle' => 'Nationalité', 'groupe' => 'Salarié', 'source' => 'dossier'],
        'salarie.adresse' => ['libelle' => 'Adresse', 'groupe' => 'Salarié', 'source' => 'dossier'],
        'salarie.date_embauche' => ['libelle' => 'Date d’embauche', 'groupe' => 'Salarié', 'source' => 'dossier'],

        'poste.fonction' => ['libelle' => 'Fonction', 'groupe' => 'Poste', 'source' => 'dossier'],
        'poste.service' => ['libelle' => 'Service', 'groupe' => 'Poste', 'source' => 'dossier'],
        'poste.agence' => ['libelle' => 'Agence', 'groupe' => 'Poste', 'source' => 'dossier'],
        'poste.lieu_travail' => ['libelle' => 'Lieu de travail', 'groupe' => 'Poste', 'source' => 'saisie'],

        'contrat.type' => ['libelle' => 'Nature du contrat (« contrat à durée indéterminée »)', 'groupe' => 'Contrat', 'source' => 'dossier'],
        'contrat.date_debut' => ['libelle' => 'Date de début', 'groupe' => 'Contrat', 'source' => 'dossier'],
        'contrat.date_fin' => ['libelle' => 'Date de fin', 'groupe' => 'Contrat', 'source' => 'dossier', 'types' => ['cdd']],
        'contrat.motif_cdd' => ['libelle' => 'Motif du recours au CDD', 'groupe' => 'Contrat', 'source' => 'saisie', 'types' => ['cdd']],
        'contrat.periode_essai' => ['libelle' => 'Période d’essai', 'groupe' => 'Contrat', 'source' => 'saisie'],
        'contrat.horaires' => ['libelle' => 'Durée et horaires de travail', 'groupe' => 'Contrat', 'source' => 'saisie'],
        'contrat.salaire_base' => ['libelle' => 'Salaire de base mensuel', 'groupe' => 'Contrat', 'source' => 'dossier'],
        'contrat.avantages' => ['libelle' => 'Primes et avantages retenus', 'groupe' => 'Contrat', 'source' => 'avantages'],

        'document.lieu_signature' => ['libelle' => 'Lieu de signature (« Fait à … »)', 'groupe' => 'Document', 'source' => 'saisie'],
        'document.date' => ['libelle' => 'Date de génération', 'groupe' => 'Document', 'source' => 'dossier'],
    ];

    /** Champ du formulaire de génération => variable alimentée. */
    public const CHAMPS_SAISIS = [
        'representant_nom' => 'employeur.representant_nom',
        'representant_qualite' => 'employeur.representant_qualite',
        'lieu_travail' => 'poste.lieu_travail',
        'periode_essai' => 'contrat.periode_essai',
        'horaires' => 'contrat.horaires',
        'motif_cdd' => 'contrat.motif_cdd',
        'lieu_signature' => 'document.lieu_signature',
    ];

    private const MOTIF = '/\{\{\s*([^{}]*?)\s*\}\}/u';

    /**
     * Variables citées dans les textes, dans l'ordre de première apparition.
     *
     * @param  list<string>  $textes
     * @return list<string>
     */
    public static function extraire(array $textes): array
    {
        $cles = [];
        foreach ($textes as $texte) {
            preg_match_all(self::MOTIF, (string) $texte, $trouvees);
            foreach ($trouvees[1] as $cle) {
                $cles[$cle] = true;
            }
        }

        return array_keys($cles);
    }

    /**
     * Variables refusées dans un modèle de ce type : inconnues, ou réservées à un autre type
     * (date de fin et motif du recours dans un modèle CDI).
     *
     * @param  list<string>  $textes
     * @return list<string>
     */
    public static function refusees(array $textes, TypeContrat $type): array
    {
        return array_values(array_filter(self::extraire($textes), function (string $cle) use ($type) {
            $definition = self::CATALOGUE[$cle] ?? null;

            return $definition === null || (isset($definition['types']) && ! in_array($type->value, $definition['types'], true));
        }));
    }

    /** @param  array<string, string|null>  $valeurs */
    public static function remplacer(string $texte, array $valeurs): string
    {
        return (string) preg_replace_callback(self::MOTIF, fn (array $m) => (string) ($valeurs[$m[1]] ?? ''), $texte);
    }

    /**
     * Catalogue affiché dans l'éditeur du modèle, limité aux variables du type.
     *
     * @return list<array{cle: string, libelle: string, groupe: string, source: string}>
     */
    public static function pourType(TypeContrat $type): array
    {
        $liste = [];
        foreach (self::CATALOGUE as $cle => $definition) {
            if (isset($definition['types']) && ! in_array($type->value, $definition['types'], true)) {
                continue;
            }
            $liste[] = ['cle' => $cle, 'libelle' => $definition['libelle'], 'groupe' => $definition['groupe'], 'source' => $definition['source']];
        }

        return $liste;
    }

    public static function libelle(string $cle): string
    {
        return self::CATALOGUE[$cle]['libelle'] ?? $cle;
    }
}
