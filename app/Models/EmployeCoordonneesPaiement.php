<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Coordonnées de paiement d'un salarié (une par salarié) — donnée sensible : visible et modifiable
 * seulement avec `rh-employes.donnees_sensibles`, numéro de compte chiffré en base.
 */
class EmployeCoordonneesPaiement extends Model
{
    use HasUlids;

    public const MODES = [
        'especes' => 'Espèces',
        'mobile_money' => 'Mobile Money',
        'virement' => 'Virement bancaire',
    ];

    protected $table = 'employe_coordonnees_paiement';

    protected $fillable = [
        'organization_id',
        'employe_id',
        'mode',
        'etablissement',
        'numero_compte',
        'titulaire',
    ];

    protected $hidden = ['numero_compte'];

    protected function casts(): array
    {
        return [
            'numero_compte' => 'encrypted',
        ];
    }

    public function employe(): BelongsTo
    {
        return $this->belongsTo(Employe::class);
    }
}
