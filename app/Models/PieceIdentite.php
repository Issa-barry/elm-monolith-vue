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

    // Entités pouvant porter une pièce d'identité — extensible : ajouter une classe
    // ici + son alias dans AppServiceProvider::morphMap() suffit pour ouvrir le
    // périmètre à une nouvelle personne physique (Client, Employe...), sans toucher
    // à la table, au modèle ou au reste du pipeline.
    private const ALLOWED_IDENTIFIABLE_TYPES = [
        Proprietaire::class,
    ];

    // Garde-fou modèle en plus du contrôleur/policy : empêche tout code (seeder,
    // tinker, futur import) de rattacher une pièce à une entité hors périmètre.
    protected static function booted(): void
    {
        static::saving(function (PieceIdentite $piece) {
            if ($piece->identifiable_type && ! in_array($piece->identifiable_type, self::allowedMorphAliases(), true)) {
                throw new \InvalidArgumentException(
                    "Une pièce d'identité ne peut être rattachée qu'aux entités autorisées (".implode(', ', self::ALLOWED_IDENTIFIABLE_TYPES).')'
                );
            }
        });

        static::forceDeleted(function (PieceIdentite $piece) {
            $storage = app(PieceIdentiteStorageService::class);
            $directory = null;
            if ($piece->recto_path) {
                $directory = dirname($piece->recto_path);
                $storage->delete($piece->recto_path);
            }
            if ($piece->verso_path) {
                $directory ??= dirname($piece->verso_path);
                $storage->delete($piece->verso_path);
            }
            $storage->deleteDirectoryIfEmpty($directory);
        });
    }

    private static function allowedMorphAliases(): array
    {
        return array_map(fn (string $class) => (new $class)->getMorphClass(), self::ALLOWED_IDENTIFIABLE_TYPES);
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

    public function isExpireBientot(int $joursSeuil = 30): bool
    {
        return $this->date_expiration !== null
            && ! $this->isExpiree()
            && $this->date_expiration->lte(now()->addDays($joursSeuil));
    }

    public function isValidee(): bool
    {
        return $this->statut_verification === StatutVerificationPieceIdentite::VALIDEE;
    }

    /**
     * Statut à afficher côté UI : surclasse le statut de vérification brut par
     * "expiree"/"expire_bientot" quand pertinent (une pièce validée mais expirée
     * ne doit jamais s'afficher comme valide).
     */
    public function getStatutAffichageAttribute(): string
    {
        if ($this->statut_verification === StatutVerificationPieceIdentite::REJETEE) {
            return 'rejetee';
        }

        if ($this->isExpiree()) {
            return 'expiree';
        }

        if ($this->isValidee() && $this->isExpireBientot()) {
            return 'expire_bientot';
        }

        return $this->statut_verification->value;
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
