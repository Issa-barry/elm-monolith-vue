<?php

namespace App\Models;

use App\Enums\OtpChannel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

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
        'verified_at', 'verification_channel', 'verification_token', 'verification_expires_at', 'is_primary',
    ];

    // Défense en profondeur (cf. HandleInertiaRequests::authUserPayload()) : ce modèle
    // n'est censé être sérialisé nulle part côté frontend, mais un jeton de vérification
    // (OTP/lien email) reste un secret d'authentification — jamais dans un JSON, même par
    // accident futur, indépendamment de sa valeur actuelle (souvent null, jamais "sûr" pour
    // autant).
    protected $hidden = ['verification_token'];

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

    /**
     * SEUL point qui doit écrire `verified_at`/`verification_channel` suite à
     * une vérification OTP — refuse structurellement de marquer un téléphone
     * vérifié via un canal qui ne prouve pas la possession d'un téléphone (ex:
     * email), cf. `OtpChannel::provesPossessionOf()`. `isVerified()` continue
     * de signifier strictement `verified_at !== null` : aucun état
     * intermédiaire/provisoire n'existe (cf. rapport du 27/08/2026).
     *
     * @throws LogicException si le canal ne prouve pas la possession du type d'identité concerné.
     */
    public function markVerifiedVia(OtpChannel $channel): void
    {
        if (! $channel->provesPossessionOf($this->type)) {
            throw new LogicException(
                "Le canal [{$channel->value}] ne prouve pas la possession d'une identité de type [{$this->type}] — impossible de la marquer vérifiée via ce canal."
            );
        }

        $this->forceFill([
            'verified_at' => now(),
            'verification_channel' => $channel->value,
        ])->save();
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
