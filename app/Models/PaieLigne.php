<?php

namespace App\Models;

use App\Enums\StatutLignePaie;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PaieLigne extends Model
{
    use HasUlids;

    protected $fillable = [
        'paie_periode_id',
        'employe_id',
        'contrat_id',
        'salaire_base',
        'jours_travailles',
        'jours_periode',
        'total_primes',
        'total_autres_gains',
        'total_avances',
        'total_retenues',
        'total_absences',
        'total_autres_deductions',
        'brut',
        'deductions',
        'net',
        'deja_paye',
        'reste_a_payer',
        'statut',
    ];

    protected $casts = [
        'salaire_base' => 'decimal:2',
        'jours_travailles' => 'decimal:2',
        'jours_periode' => 'integer',
        'total_primes' => 'decimal:2',
        'total_autres_gains' => 'decimal:2',
        'total_avances' => 'decimal:2',
        'total_retenues' => 'decimal:2',
        'total_absences' => 'decimal:2',
        'total_autres_deductions' => 'decimal:2',
        'brut' => 'decimal:2',
        'deductions' => 'decimal:2',
        'net' => 'decimal:2',
        'deja_paye' => 'decimal:2',
        'reste_a_payer' => 'decimal:2',
        'statut' => StatutLignePaie::class,
    ];

    public function periode(): BelongsTo
    {
        return $this->belongsTo(PaiePeriode::class, 'paie_periode_id');
    }

    public function employe(): BelongsTo
    {
        return $this->belongsTo(Employe::class);
    }

    public function contrat(): BelongsTo
    {
        return $this->belongsTo(Contrat::class);
    }

    public function variables(): HasMany
    {
        return $this->hasMany(PaieVariable::class);
    }

    public function paiements(): HasMany
    {
        return $this->hasMany(PaiePaiement::class);
    }
}
