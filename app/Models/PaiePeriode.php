<?php

namespace App\Models;

use App\Enums\StatutPeriodePaie;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PaiePeriode extends Model
{
    use HasUlids, SoftDeletes;

    protected $fillable = [
        'organization_id',
        'mois',
        'annee',
        'statut',
        'notes',
        'transmission_paie_id',
        'reprise_par',
        'reprise_le',
    ];

    protected $casts = [
        'mois' => 'integer',
        'annee' => 'integer',
        'statut' => StatutPeriodePaie::class,
        'reprise_le' => 'datetime',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function lignes(): HasMany
    {
        return $this->hasMany(PaieLigne::class, 'paie_periode_id');
    }

    /** Version de la transmission RH reprise dans la période (ADR 0041). */
    public function transmission(): BelongsTo
    {
        return $this->belongsTo(TransmissionPaie::class, 'transmission_paie_id');
    }

    public function repreneur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reprise_par');
    }

    public function labelPeriode(): string
    {
        $mois = ['', 'Janvier', 'Février', 'Mars', 'Avril', 'Mai', 'Juin',
            'Juillet', 'Août', 'Septembre', 'Octobre', 'Novembre', 'Décembre'];

        return $mois[$this->mois].' '.$this->annee;
    }

    public function peutEtrePayee(): bool
    {
        return in_array($this->statut, [StatutPeriodePaie::VALIDE_RH, StatutPeriodePaie::PAYE], true);
    }
}
