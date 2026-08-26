<?php

namespace App\Models;

use App\Enums\CommissionMode;
use App\Enums\CommissionRegleStatut;
use App\Enums\CommissionScopeType;
use App\Enums\CommissionUniteCalcul;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Définit COMBIEN est dû — jamais QUI le reçoit (CommissionGroupe) ni QUAND
 * (CommissionProcessus::declencheur). Versionnée : jamais modifiée en place,
 * cf. remplace_regle_id et CommissionRegleResolver.
 */
class CommissionRegle extends Model
{
    use HasUlids;

    protected $table = 'commission_regles';

    protected $fillable = [
        'organization_id',
        'processus_id',
        'libelle',
        'scope_type',
        'scope_id',
        'type_vehicule_id',
        'cible_type',
        'mode',
        'unite_calcul',
        'montant',
        'consultant_id',
        'effective_from',
        'effective_to',
        'remplace_regle_id',
        'statut',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'scope_type' => CommissionScopeType::class,
            'mode' => CommissionMode::class,
            'unite_calcul' => CommissionUniteCalcul::class,
            'montant' => 'decimal:2',
            'effective_from' => 'date',
            'effective_to' => 'date',
            'statut' => CommissionRegleStatut::class,
        ];
    }

    public function estActiveA(\DateTimeInterface $date): bool
    {
        if ($this->statut !== CommissionRegleStatut::ACTIVE) {
            return false;
        }

        $d = $date instanceof CarbonInterface ? $date : Carbon::instance(\DateTime::createFromInterface($date));

        if ($this->effective_from && $d->lt($this->effective_from)) {
            return false;
        }

        if ($this->effective_to && $d->gt($this->effective_to)) {
            return false;
        }

        return true;
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function processus(): BelongsTo
    {
        return $this->belongsTo(CommissionProcessus::class, 'processus_id');
    }

    public function consultant(): BelongsTo
    {
        return $this->belongsTo(Prestataire::class, 'consultant_id');
    }

    public function typeVehicule(): BelongsTo
    {
        return $this->belongsTo(TypeVehicule::class, 'type_vehicule_id');
    }

    public function remplaceePar(): BelongsTo
    {
        return $this->belongsTo(CommissionRegle::class, 'remplace_regle_id');
    }

    public function createur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
