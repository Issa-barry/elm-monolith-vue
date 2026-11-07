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
 *
 * Référentiel Contacts (ADR 0060) : l'interlocuteur porte sa fonction dans l'entreprise et peut être
 * le principal. `est_principal` et la désactivation ne sont pas mass-assignables : seul
 * App\Services\Contacts\InterlocuteurService les pose, pour garantir un seul principal actif.
 */
class EntrepriseInterlocuteur extends Model
{
    use HasUlids;

    protected $table = 'entreprise_interlocuteurs';

    protected $fillable = [
        'organization_id',
        'entreprise_tierce_id',
        'personne_id',
        'fonction',
        'acces_commissions',
        'is_active',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'acces_commissions' => 'boolean',
            'is_active' => 'boolean',
            'est_principal' => 'boolean',
            'desactive_le' => 'datetime',
        ];
    }

    public function desactivePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'desactive_par');
    }

    public function scopeActifs(Builder $q): Builder
    {
        return $q->where('is_active', true);
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
