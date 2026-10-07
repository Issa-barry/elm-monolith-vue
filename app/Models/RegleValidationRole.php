<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Règle de validation par plafond d'un rôle pour un domaine métier (ADR 0021) : jusqu'à quel
 * montant, et pour quelles agences, un rôle peut valider. La permission du domaine (ex.
 * `achats.valider`) reste nécessaire : la règle ne fait que la borner.
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
        'perimetre',
        'sites',
    ];

    protected $casts = [
        'plafond' => 'decimal:2',
        'plafond_illimite' => 'boolean',
        'sites' => 'array',
    ];
}
