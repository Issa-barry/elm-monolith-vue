<?php

namespace App\Models;

use App\Enums\StatutVerificationPieceIdentite;
use App\Enums\TypePieceIdentite;
use App\Services\PieceIdentiteStorageService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class PieceIdentite extends Model
{
    use HasFactory, HasUlids, SoftDeletes;

    protected $table = 'pieces_identite';

    protected $fillable = [
        'organization_id',
        'type_piece',
        'numero',
        'pays_delivrance',
        'date_delivrance',
        'date_expiration',
        'recto_path',
        'verso_path',
        'recto_nom_original',
        'verso_nom_original',
        'recto_mime_type',
        'verso_mime_type',
        'recto_taille',
        'verso_taille',
        'statut_verification',
        'motif_rejet',
        'est_active',
        'verifiee_par',
        'verifiee_le',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'type_piece' => TypePieceIdentite::class,
            'statut_verification' => StatutVerificationPieceIdentite::class,
            'date_delivrance' => 'date',
            'date_expiration' => 'date',
            'verifiee_le' => 'datetime',
            'est_active' => 'boolean',
            'recto_taille' => 'integer',
            'verso_taille' => 'integer',
        ];
    }

    // Seul Employe peut être rattaché pour le moment (voir AppServiceProvider::enforceMorphMap).
    // Garde-fou modèle en plus du contrôleur : empêche tout code (seeder, tinker, futur import)
    // de rattacher une pièce à une autre entité tant que le périmètre n'est pas élargi.
    protected static function booted(): void
    {
        static::saving(function (PieceIdentite $piece) {
            if ($piece->identifiable_type && $piece->identifiable_type !== (new Employe)->getMorphClass()) {
                throw new \InvalidArgumentException(
                    "Une pièce d'identité ne peut être rattachée qu'à un Employe pour le moment."
                );
            }
        });

        static::forceDeleted(function (PieceIdentite $piece) {
            $storage = app(PieceIdentiteStorageService::class);
            $storage->delete($piece->recto_path);
            $storage->delete($piece->verso_path);
        });
    }

    public function identifiable(): MorphTo
    {
        return $this->morphTo();
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function verifiePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verifiee_par');
    }

    public function createur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updateur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function scopeActives(Builder $query): Builder
    {
        return $query->where('est_active', true);
    }

    public function isExpiree(): bool
    {
        return $this->date_expiration !== null && $this->date_expiration->isPast();
    }

    public function isValidee(): bool
    {
        return $this->statut_verification === StatutVerificationPieceIdentite::VALIDEE;
    }

    public function getNumeroMasqueAttribute(): ?string
    {
        if (! $this->numero) {
            return null;
        }

        $longueur = mb_strlen($this->numero);

        if ($longueur <= 4) {
            return str_repeat('•', $longueur);
        }

        return str_repeat('•', $longueur - 4).mb_substr($this->numero, -4);
    }
}
