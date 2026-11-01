<?php

namespace App\Support\Rh\Contrats;

use App\Enums\TypeContrat;

/**
 * Variables utilisables dans le texte d'un modèle de contrat (ADR 0044), écrites `{{cle}}`.
 *
 * - `dossier` : reprise automatiquement du dossier RH, du contrat (conditions saisies à sa
 *   création : période d'essai, heures par semaine, motif du CDD), du PDG désigné et de l'agence
 *   du salarié (ADR 0048) — plus aucune saisie au moment de la génération ;
 * - `avantages` : toutes les primes et tous les avantages validés du salarié, en cours ou à venir.
 *
 * Une variable inconnue est refusée à l'enregistrement du modèle ; une variable utilisée et vide
 * bloque la génération : le document n'est jamais produit avec un trou ou une valeur inventée.
 */
final class VariablesContrat
{
    /** @var array<string, array{libelle: string, groupe: string, source: string, types?: list<string>}> */
    public const CATALOGUE = [
        'employeur.nom' => ['libelle' => 'Nom de l’employeur (organisation)', 'groupe' => 'Employeur', 'source' => 'dossier'],
        'employeur.representant_nom' => ['libelle' => 'Représentant de l’employeur — nom du PDG', 'groupe' => 'Employeur', 'source' => 'dossier'],
        'employeur.representant_qualite' => ['libelle' => 'Représentant de l’employeur — qualité (« Président-directeur général »)', 'groupe' => 'Employeur', 'source' => 'dossier'],

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
        'poste.lieu_travail' => ['libelle' => 'Lieu de travail (agence du salarié et sa ville)', 'groupe' => 'Poste', 'source' => 'dossier'],

        'contrat.type' => ['libelle' => 'Nature du contrat (« contrat à durée indéterminée »)', 'groupe' => 'Contrat', 'source' => 'dossier'],
        'contrat.date_debut' => ['libelle' => 'Date de début', 'groupe' => 'Contrat', 'source' => 'dossier'],
        'contrat.date_fin' => ['libelle' => 'Date de fin', 'groupe' => 'Contrat', 'source' => 'dossier', 'types' => ['cdd']],
        'contrat.motif_cdd' => ['libelle' => 'Motif du recours au CDD', 'groupe' => 'Contrat', 'source' => 'dossier', 'types' => ['cdd']],
        'contrat.periode_essai' => ['libelle' => 'Période d’essai (« 3 mois », « aucune »)', 'groupe' => 'Contrat', 'source' => 'dossier'],
        'contrat.horaires' => ['libelle' => 'Heures de travail par semaine (« 40 heures par semaine »)', 'groupe' => 'Contrat', 'source' => 'dossier'],
        'contrat.salaire_base' => ['libelle' => 'Salaire de base mensuel', 'groupe' => 'Contrat', 'source' => 'dossier'],
        'contrat.avantages' => ['libelle' => 'Primes et avantages validés du salarié', 'groupe' => 'Contrat', 'source' => 'avantages'],

        'document.lieu_signature' => ['libelle' => 'Lieu de signature (laissé en blanc, écrit à la main à la signature)', 'groupe' => 'Document', 'source' => 'dossier'],
        'document.date' => ['libelle' => 'Date de génération', 'groupe' => 'Document', 'source' => 'dossier'],
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

    /** Libellé sans l'exemple entre parenthèses : « Période d’essai ». */
    public static function libelleCourt(string $cle): string
    {
        return trim((string) preg_replace('/\s*\(.*\)$/u', '', self::libelle($cle)));
    }

    /** Où compléter une information manquante, par groupe de variables. */
    private const OU_CORRIGER = [
        'Employeur' => 'Paramètres → Organisation',
        'Salarié' => 'fiche du salarié : RH → Employés → Modifier',
        'Poste' => 'fiche du salarié : RH → Employés → Modifier',
        'Contrat' => 'contrat : RH → Contrats → Modifier',
        'Document' => 'modèle : Paramètres → RH → Modèles de contrat',
    ];

    /**
     * « Informations manquantes pour le PDF : date de naissance (fiche du salarié : …) ; motif du
     * recours au CDD (contrat : …). » — ce qui manque et l'écran où le compléter.
     *
     * @param  list<string>  $cles
     */
    public static function messageManquantes(array $cles): string
    {
        $parEcran = [];
        foreach ($cles as $cle) {
            $ou = self::OU_CORRIGER[self::CATALOGUE[$cle]['groupe'] ?? ''] ?? 'dossier';
            $libelle = self::libelleCourt($cle);
            $parEcran[$ou][] = mb_strtolower(mb_substr($libelle, 0, 1)).mb_substr($libelle, 1);
        }

        return 'Informations manquantes pour le PDF : '.implode(' ; ', array_map(
            fn (string $ou, array $libelles) => implode(', ', $libelles).' ('.$ou.')',
            array_keys($parEcran),
            $parEcran,
        )).'.';
    }
}
