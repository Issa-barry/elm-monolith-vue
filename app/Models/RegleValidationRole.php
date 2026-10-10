<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/**
 * Règle de validation par plafond d'un rôle pour un domaine métier (ADR 0021) : jusqu'à quel
 * montant, et pour quelles agences, un rôle peut valider, et s'il peut valider ses propres bons
 * (`peut_valider_ses_propres_bons`). La permission du domaine (ex. `achats.valider`) reste
 * nécessaire : la règle ne fait que la borner.
 */
class RegleValidationRole extends Model
{
    use HasUlids;

    public const DOMAINE_ACHATS = 'achats';

    public const PERIMETRES = ['toutes_agences', 'son_agence', 'agences_selectionnees'];

    protected $table = 'regles_validation_roles';

    protected $fillable = [
        'organization_id',
        'domaine',
        'role_name',
        'plafond',
        'plafond_illimite',
        'peut_valider_ses_propres_bons',
        'peut_valider_ses_propres_factures',
        'perimetre',
        'sites',
    ];

    /** Réglages « valider ce qu'on a soi-même créé ou modifié en dernier », par type de document. */
    public const REGLAGES_AUTO_VALIDATION = ['peut_valider_ses_propres_bons', 'peut_valider_ses_propres_factures'];

    /**
     * Rôles qui reçoivent une règle par défaut « toutes agences, sans limite » (décision du
     * 07/10/2026), avec le droit de valider leurs propres bons (09/10/2026) et factures d'achat
     * (10/10/2026) : oui pour le super administrateur, non par défaut pour les autres.
     */
    public const ROLES_PAR_DEFAUT_ACHATS = [
        'admin_entreprise' => ['peut_valider_ses_propres_bons' => false, 'peut_valider_ses_propres_factures' => false],
        'super_admin' => ['peut_valider_ses_propres_bons' => true, 'peut_valider_ses_propres_factures' => true],
    ];

    protected $casts = [
        'plafond' => 'decimal:2',
        'plafond_illimite' => 'boolean',
        'peut_valider_ses_propres_bons' => 'boolean',
        'peut_valider_ses_propres_factures' => 'boolean',
        'sites' => 'array',
    ];

    /**
     * Règles de départ du domaine achats : simples DONNÉES de configuration, modifiables ou
     * supprimables dans Paramètres → Achats. Une règle déjà configurée n'est jamais écrasée.
     */
    public static function provisionnerAchatsParDefaut(string $organizationId): void
    {
        // Appelée aussi par la migration 2026_10_07_200400, qui s'exécute AVANT celles qui ajoutent
        // les colonnes d'auto-validation sur une base non encore déployée : une colonne n'est écrite
        // que si elle existe (les migrations 2026_10_09_200000 et 2026_10_10_100000 l'activent
        // ensuite pour super_admin).
        $colonnes = array_filter(
            self::REGLAGES_AUTO_VALIDATION,
            fn (string $colonne) => Schema::hasColumn((new self)->getTable(), $colonne),
        );

        foreach (self::ROLES_PAR_DEFAUT_ACHATS as $role => $autoValidation) {
            $valeurs = ['plafond' => null, 'plafond_illimite' => true, 'perimetre' => 'toutes_agences', 'sites' => null];
            foreach ($colonnes as $colonne) {
                $valeurs[$colonne] = $autoValidation[$colonne];
            }

            self::firstOrCreate(
                ['organization_id' => $organizationId, 'domaine' => self::DOMAINE_ACHATS, 'role_name' => $role],
                $valeurs,
            );
        }
    }
}
