<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * "Comment je me connecte" — séparé de Personne ("qui je suis") et de User ("à quoi j'ai
 * accès"). normalized_value porte l'unicité GLOBALE (toute la plateforme, jamais scopée par
 * organisation) : une valeur donnée n'authentifie jamais plus d'un seul User, contrairement à
 * Personne::telephone_normalise qui n'est unique que par organisation — ce sont deux garanties
 * différentes, ne jamais les confondre.
 */
class UserAuthIdentity extends Model
{
    use HasUlids;

    public const TYPE_TELEPHONE = 'telephone';

    public const TYPE_EMAIL = 'email';

    protected $fillable = [
        'user_id', 'type', 'value', 'normalized_value',
        'verified_at', 'verification_token', 'verification_expires_at', 'is_primary',
    ];

    protected function casts(): array
    {
        return [
            'verified_at' => 'datetime',
            'verification_expires_at' => 'datetime',
            'is_primary' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }

    /** Forme canonique de comparaison — chiffres seuls pour un téléphone, minuscules pour un email. */
    public static function normaliser(string $type, string $value): string
    {
        return $type === self::TYPE_EMAIL
            ? mb_strtolower(trim($value), 'UTF-8')
            : preg_replace('/\D+/', '', $value) ?? '';
    }

    public static function resoudre(string $type, string $normalizedValue): ?User
    {
        return static::where('type', $type)->where('normalized_value', $normalizedValue)->first()?->user;
    }
}
