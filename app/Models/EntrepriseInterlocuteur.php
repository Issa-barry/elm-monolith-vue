<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Personne physique qui représente une entreprise tierce (ADR 0050). Elle consulte les commissions
 * du prestataire de cette entreprise sans en devenir bénéficiaire. `user_id` n'est pas
 * mass-assignable : seul RattachementCompteService le pose.
 */
class EntrepriseInterlocuteur extends Model
{
    use HasUlids;

    protected $table = 'entreprise_interlocuteurs';

    protected $fillable = [
        'organization_id',
        'entreprise_tierce_id',
        'personne_id',
        'acces_commissions',
        'is_active',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'acces_commissions' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function entrepriseTierce(): BelongsTo
    {
        return $this->belongsTo(EntrepriseTierce::class);
    }

    public function personne(): BelongsTo
    {
        return $this->belongsTo(Personne::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Interlocuteur actif, explicitement autorisé à consulter les commissions de l'entreprise. */
    public function scopeAutorises(Builder $q): Builder
    {
        return $q->where('is_active', true)->where('acces_commissions', true);
    }
}
