<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Journal des contrôles exécutés : prouve que le contrôle planifié tourne (l'écran signale un
 * dernier contrôle planifié trop ancien, cf. SurveillanceEncaissements::RETARD_PLANIFICATEUR).
 */
class ControleEncaissements extends Model
{
    use HasUlids;

    public const PLANIFICATEUR = 'planificateur';

    public const MANUEL = 'manuel';

    public const COMMANDE = 'commande';

    protected $table = 'controles_encaissements';

    protected $fillable = [
        'organization_id',
        'declencheur',
        'user_id',
        'debut',
        'fin',
        'nb_constats',
        'nb_nouvelles',
        'nb_disparues',
        'nb_relances',
        'erreur',
    ];

    protected function casts(): array
    {
        return [
            'debut' => 'datetime',
            'fin' => 'datetime',
        ];
    }
}
