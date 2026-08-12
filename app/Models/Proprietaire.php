<?php

namespace App\Models;

use App\Enums\StatutVerificationPieceIdentite;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Proprietaire extends Model
{
    use HasFactory, HasUlids, SoftDeletes;

    protected $casts = [
        'is_active' => 'boolean',
    ];

    protected $fillable = [
        'organization_id',
        'user_id',
        'nom',
        'prenom',
        'surnom',
        'email',
        'telephone',
        'adresse',
        'ville',
        'pays',
        'code_pays',
        'code_phone_pays',
        'is_active',
    ];

    // ── Accessor ──────────────────────────────────────────────────────────────

    public function getNomCompletAttribute(): string
    {
        return trim("{$this->prenom} {$this->nom}");
    }

    // ── Relations ─────────────────────────────────────────────────────────────

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function piecesIdentite(): MorphMany
    {
        return $this->morphMany(PieceIdentite::class, 'identifiable');
    }

    // Pas de pieceIdentiteActive() en MorphOne : plusieurs pièces de types
    // différents (CNI + passeport) peuvent être actives simultanément, une
    // relation "à un seul résultat" masquerait cette réalité métier.

    public function hasValidIdentityDocument(): bool
    {
        return $this->piecesIdentite()
            ->actives()
            ->where('statut_verification', StatutVerificationPieceIdentite::VALIDEE->value)
            ->where(function ($q) {
                $q->whereNull('date_expiration')
                    ->orWhere('date_expiration', '>=', now()->toDateString());
            })
            ->exists();
    }

    /**
     * Propriétaire par défaut représentant l'organisation elle-même (voir
     * database/seeders/ProprietairesSeeder.php) — assigné automatiquement à un véhicule quand
     * aucun propriétaire tiers n'est explicitement choisi. Centralise ce qui était dupliqué
     * entre VehiculeController et EquipeLivraisonController.
     */
    public static function interneParDefautId(string $organizationId): ?string
    {
        return static::where('organization_id', $organizationId)
            ->where('telephone', '+224622602693')
            ->value('id');
    }
}
