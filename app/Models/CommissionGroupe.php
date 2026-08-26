<?php

namespace App\Models;

use App\Enums\CommissionActivationStatut;
use App\Enums\CommissionAncrageType;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * Groupe bénéficiaire générique — équipe de livraison (ancrage véhicule),
 * équipe de dépôt (ancrage site), équipes transverses futures (ancrage
 * organisation). Un seul moteur de répartition partagé par tous les types
 * (CommissionRepartitionEngine) : jamais de branchement `if type == ...`.
 */
class CommissionGroupe extends Model
{
    use HasUlids;

    public const TYPE_EQUIPE_LIVRAISON = 'equipe_livraison';

    // Réservé — non utilisé avant la Phase 3 (cf. conception cible §F).
    public const TYPE_EQUIPE_DEPOT = 'equipe_depot';

    protected $table = 'commission_groupes';

    protected $fillable = [
        'organization_id',
        'type',
        'ancrage_type',
        'ancrage_id',
        'statut',
    ];

    protected function casts(): array
    {
        return [
            'ancrage_type' => CommissionAncrageType::class,
            'statut' => CommissionActivationStatut::class,
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function membres(): HasMany
    {
        return $this->hasMany(CommissionGroupeMembre::class, 'groupe_id');
    }

    /**
     * Membres dont la période de validité couvre la date donnée — snapshot
     * temporel, jamais une simple lecture de "tous les membres actuels".
     */
    public function membresActifsA(\DateTimeInterface $date): Collection
    {
        return $this->membres()
            ->where('effective_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $date))
            ->get();
    }
}
