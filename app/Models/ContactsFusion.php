<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Fusion manuelle de deux fiches du référentiel Contacts (ADR 0060, lot 9b) : la fiche absorbée est
 * archivée avec un lien vers la fiche conservée. Le journal (operations) garde chaque valeur écrite,
 * avant et après, pour l'annulation sûre.
 */
class ContactsFusion extends Model
{
    use HasUlids;

    public const TYPE_PERSONNE = 'personne';

    public const TYPE_ENTREPRISE = 'entreprise';

    public const EXECUTEE = 'executee';

    public const ANNULEE = 'fusion_annulee';

    /** Annulation faite pour ce qui était sûr ; le reste demande une intervention. */
    public const ANNULEE_PARTIELLEMENT = 'fusion_annulee_partiellement';

    public const LIBELLES_STATUT = [
        self::EXECUTEE => 'Exécutée',
        self::ANNULEE => 'Annulée',
        self::ANNULEE_PARTIELLEMENT => 'Annulée partiellement',
    ];

    protected $fillable = [
        'organization_id', 'type', 'fiche_conservee_id', 'fiche_absorbee_id', 'statut', 'jeton',
        'acteur_id', 'simulation', 'choix', 'confirmations', 'annulee_le', 'annulee_par', 'annulation',
    ];

    protected function casts(): array
    {
        return [
            'simulation' => 'array',
            'choix' => 'array',
            'confirmations' => 'array',
            'annulation' => 'array',
            'annulee_le' => 'datetime',
        ];
    }

    public function operations(): HasMany
    {
        return $this->hasMany(ContactsFusionOperation::class)->orderBy('ordre');
    }

    public function acteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acteur_id');
    }

    public function annuleePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'annulee_par');
    }

    /** @return class-string<Personne|EntrepriseTierce> */
    public function classeFiche(): string
    {
        return $this->type === self::TYPE_PERSONNE ? Personne::class : EntrepriseTierce::class;
    }
}
