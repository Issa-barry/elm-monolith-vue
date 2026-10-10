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
        'perimetre',
        'sites',
    ];

    /**
     * Rôles qui reçoivent une règle par défaut « toutes agences, sans limite » (décision du
     * 07/10/2026), avec le droit de valider leurs propres bons (décision du 09/10/2026 : oui pour
     * le super administrateur, non par défaut pour les autres).
     */
    public const ROLES_PAR_DEFAUT_ACHATS = ['admin_entreprise' => false, 'super_admin' => true];

    protected $casts = [
        'plafond' => 'decimal:2',
        'plafond_illimite' => 'boolean',
        'peut_valider_ses_propres_bons' => 'boolean',
        'sites' => 'array',
    ];

    /**
     * Règles de départ du domaine achats : simples DONNÉES de configuration, modifiables ou
     * supprimables dans Paramètres → Achats. Une règle déjà configurée n'est jamais écrasée.
     */
    public static function provisionnerAchatsParDefaut(string $organizationId): void
    {
        // Appelée aussi par la migration 2026_10_07_200400, qui s'exécute AVANT celle qui ajoute
        // `peut_valider_ses_propres_bons` sur une base non encore déployée : la colonne n'est
        // écrite que si elle existe (la migration 2026_10_09_200000 l'active ensuite pour super_admin).
        $avecAutoValidation = Schema::hasColumn((new self)->getTable(), 'peut_valider_ses_propres_bons');

        foreach (self::ROLES_PAR_DEFAUT_ACHATS as $role => $autoValidation) {
            $valeurs = ['plafond' => null, 'plafond_illimite' => true, 'perimetre' => 'toutes_agences', 'sites' => null];
            if ($avecAutoValidation) {
                $valeurs['peut_valider_ses_propres_bons'] = $autoValidation;
            }

            self::firstOrCreate(
                ['organization_id' => $organizationId, 'domaine' => self::DOMAINE_ACHATS, 'role_name' => $role],
                $valeurs,
            );
        }
    }
}
