<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Compte auxiliaire : rattache une entité métier (Proprietaire, Livreur...)
 * à un compte collectif ("Propriétaires à payer", "Livreurs à payer"...).
 * Un tiers comptable n'est JAMAIS un compte comptable à part entière — il
 * partage le compte collectif de son type, cf. règle #10 de la spec.
 */
class TiersComptable extends Model
{
    use HasUlids;

    protected $table = 'compta_tiers';

    protected $fillable = [
        'organization_id',
        'type',
        'tiersable_type',
        'tiersable_id',
        'compte_collectif_id',
        'libelle',
        'actif',
    ];

    protected function casts(): array
    {
        return [
            'actif' => 'boolean',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function tiersable(): MorphTo
    {
        return $this->morphTo();
    }

    public function compteCollectif(): BelongsTo
    {
        return $this->belongsTo(CompteComptable::class, 'compte_collectif_id');
    }

    /**
     * Trouve ou crée le tiers comptable correspondant à une entité métier,
     * rattaché au compte collectif configuré pour ce type. Idempotent.
     */
    public static function resoudrePour(string $organizationId, string $type, Model $tiersable, string $compteCollectifId): self
    {
        return static::query()->firstOrCreate(
            [
                'organization_id' => $organizationId,
                'tiersable_type' => $tiersable->getMorphClass(),
                'tiersable_id' => $tiersable->getKey(),
            ],
            [
                'type' => $type,
                'compte_collectif_id' => $compteCollectifId,
                'libelle' => (string) ($tiersable->nom_complet ?? $tiersable->nom ?? $tiersable->libelle ?? $tiersable->getKey()),
                'actif' => true,
            ]
        );
    }
}
