<?php

namespace App\Models;

use App\Enums\CommissionRegleStatut;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * QUI est actuellement le bénéficiaire de la cible de commission "consultant", pour une
 * organisation donnée — jamais un prestataire codé en dur. Le prestataire lui-même vit dans le
 * module Prestataires ; cette table ne fait que pointer vers celui actuellement désigné, avec
 * historique (cf. remplace_affectation_id) pour que CommissionEnveloppeGenerator résolve
 * toujours "le consultant à cette date-là", jamais "le consultant actuel" rétroactivement.
 */
class CommissionConsultantAffectation extends Model
{
    use HasUlids;

    protected $table = 'commission_consultant_affectations';

    protected $fillable = [
        'organization_id',
        'prestataire_id',
        'effective_from',
        'effective_to',
        'remplace_affectation_id',
        'statut',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'effective_to' => 'date',
            'statut' => CommissionRegleStatut::class,
        ];
    }

    // ── Relations ─────────────────────────────────────────────────────────────

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function prestataire(): BelongsTo
    {
        return $this->belongsTo(Prestataire::class);
    }

    public function remplaceePar(): BelongsTo
    {
        return $this->belongsTo(self::class, 'remplace_affectation_id');
    }

    public function createur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ── Résolution ────────────────────────────────────────────────────────────

    /**
     * Le consultant actuellement désigné pour l'organisation, ou null si aucun ne l'est —
     * absence de désignation, jamais une erreur (c'est CommissionEnveloppeGenerator qui décide
     * si l'absence de consultant doit bloquer la génération, cf. décision AMOA #4). Un
     * prestataire désactivé après coup (is_active=false) ne compte plus comme désigné, sans
     * qu'il faille clôturer explicitement l'affectation : désactiver un prestataire suffit à
     * arrêter les commissions futures, jamais besoin d'un second geste.
     */
    public static function actifPour(string $organizationId): ?self
    {
        return static::where('organization_id', $organizationId)
            ->where('statut', CommissionRegleStatut::ACTIVE->value)
            ->whereHas('prestataire', fn ($q) => $q->where('is_active', true))
            ->first();
    }
}
