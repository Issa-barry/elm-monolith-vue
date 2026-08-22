<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une ligne = un couple (site, fonction) valable sur une période — début inclus, fin exclue
 * (`fin_at IS NULL` = active). Jamais réécrite après clôture (cf. EmployeAffectationService) :
 * c'est la source de vérité historique pour "qui occupait quelle fonction sur quel site à quelle
 * date", y compris après un changement de fonction ultérieur de l'employé.
 */
class EmployeAffectation extends Model
{
    use HasUlids;

    protected $table = 'employe_affectations';

    protected $fillable = [
        'organization_id',
        'employe_id',
        'site_id',
        'fonction_rh_id',
        'debut_at',
        'fin_at',
        'motif',
        'cree_par',
    ];

    protected function casts(): array
    {
        return [
            'debut_at' => 'datetime',
            'fin_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function employe(): BelongsTo
    {
        return $this->belongsTo(Employe::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function fonction(): BelongsTo
    {
        return $this->belongsTo(FonctionRh::class, 'fonction_rh_id');
    }

    public function creePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cree_par');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('fin_at');
    }

    /** Début inclus, fin exclue — cf. docblock de classe. */
    public function scopeActiveA(Builder $query, \DateTimeInterface $date): Builder
    {
        return $query->where('debut_at', '<=', $date)
            ->where(fn (Builder $q) => $q->whereNull('fin_at')->orWhere('fin_at', '>', $date));
    }
}
