<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Identité civile d'une personne morale externe (fournisseur, prestataire...) au sein d'une
 * organisation — pendant de Personne côté entreprise. Une même société peut jouer plusieurs
 * rôles métier (Fournisseur, Prestataire...) sans jamais dupliquer raison sociale/téléphone/
 * email/adresse : chaque rôle référence son EntrepriseTierce via entreprise_tierce_id plutôt que
 * de recopier son identité.
 */
class EntrepriseTierce extends Model
{
    use HasFactory, HasUlids, SoftDeletes;

    // Accord grammatical français ("entreprises tierces") incompatible avec la pluralisation
    // naïve d'Eloquent (qui donnerait "entreprise_tierces") — nom de table explicite.
    protected $table = 'entreprises_tierces';

    protected $fillable = [
        'organization_id', 'raison_sociale', 'identifiant_fiscal',
        'telephone', 'telephone_normalise', 'email',
        'pays', 'code_pays', 'code_phone_pays', 'ville', 'adresse',
        'contact_personne_id', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    // ── Mutateurs ─────────────────────────────────────────────────────────────

    public function setEmailAttribute(mixed $value): void
    {
        $v = $value !== null ? trim((string) $value) : null;
        $this->attributes['email'] = $v !== null && $v !== '' ? strtolower($v) : null;
    }

    // ── Relations ─────────────────────────────────────────────────────────────

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function contactPersonne(): BelongsTo
    {
        return $this->belongsTo(Personne::class, 'contact_personne_id');
    }

    public function fournisseurs(): HasMany
    {
        return $this->hasMany(Fournisseur::class);
    }

    public function prestataires(): HasMany
    {
        return $this->hasMany(Prestataire::class);
    }

    /**
     * Cherche une EntrepriseTierce existante dans l'organisation par téléphone normalisé, sinon
     * en crée une nouvelle — même principe que Personne::resoudreOuCreer() : le téléphone
     * normalisé est la SEULE clé de rapprochement, volontairement stricte. Permet à une même
     * société d'être à la fois Fournisseur et Prestataire sans dupliquer son identité.
     *
     * @param  array{raison_sociale: string, identifiant_fiscal?: ?string, telephone?: ?string, email?: ?string, pays?: ?string, code_pays?: ?string, code_phone_pays?: ?string, ville?: ?string, adresse?: ?string}  $attributs
     */
    public static function resoudreOuCreer(string $organizationId, array $attributs): self
    {
        $telephoneNormalise = ! empty($attributs['telephone'])
            ? Personne::normaliserTelephone($attributs['telephone'])
            : null;

        if ($telephoneNormalise) {
            $existante = static::where('organization_id', $organizationId)
                ->where('telephone_normalise', $telephoneNormalise)
                ->first();

            if ($existante) {
                return $existante;
            }
        }

        return static::create([
            'organization_id' => $organizationId,
            'telephone_normalise' => $telephoneNormalise,
            ...$attributs,
        ]);
    }
}
