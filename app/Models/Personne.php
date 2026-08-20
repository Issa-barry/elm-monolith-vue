<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Identité civile centrale d'une personne physique au sein d'une organisation. Une même
 * personne peut jouer plusieurs rôles métier (User, Proprietaire, Employe, Livreur...) sans
 * jamais dupliquer nom/prénom/téléphone/email : chaque rôle référence sa Personne via
 * personne_id plutôt que de recopier son identité. Voir CLAUDE de session — refonte identité
 * User/Proprietaire/Employe/Livreur.
 */
class Personne extends Model
{
    use HasFactory, HasUlids, SoftDeletes;

    protected $fillable = [
        'organization_id', 'nom', 'prenom', 'nom_complet', 'surnom',
        'telephone', 'telephone_normalise', 'email',
        'pays', 'code_pays', 'code_phone_pays', 'ville', 'adresse',
    ];

    public function setEmailAttribute(mixed $value): void
    {
        $v = $value !== null ? trim((string) $value) : null;
        $this->attributes['email'] = $v !== null && $v !== '' ? strtolower($v) : null;
    }

    /**
     * Une personne n'a pas toujours nom+prénom connus séparément (ex: livreur identifié
     * seulement par "Baba Ousou") — priorité d'affichage : nom_complet saisi tel quel, sinon
     * prenom+nom concaténés, sinon surnom, jamais une chaîne vide silencieuse si l'un des
     * trois est renseigné.
     */
    public function getNomCompletAttribute(): string
    {
        if (! empty($this->attributes['nom_complet'])) {
            return $this->attributes['nom_complet'];
        }

        $prenomNom = trim("{$this->prenom} {$this->nom}");
        if ($prenomNom !== '') {
            return $prenomNom;
        }

        return $this->surnom ?? '';
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function user(): HasOne
    {
        return $this->hasOne(User::class);
    }

    public function proprietaire(): HasOne
    {
        return $this->hasOne(Proprietaire::class);
    }

    public function employe(): HasOne
    {
        return $this->hasOne(Employe::class);
    }

    public function livreur(): HasOne
    {
        return $this->hasOne(Livreur::class);
    }

    public function piecesIdentite(): MorphMany
    {
        return $this->morphMany(PieceIdentite::class, 'identifiable');
    }

    /**
     * Cherche une Personne existante dans l'organisation par téléphone normalisé, sinon en crée
     * une nouvelle. Jamais de fusion automatique en cas d'ambiguïté (nom différent, etc.) : le
     * téléphone normalisé est la SEULE clé de rapprochement, volontairement stricte — un appelant
     * qui a un doute doit résoudre l'ambiguïté avant d'appeler cette méthode, jamais après.
     *
     * $organizationId nullable : couvre le cas d'un compte auto-inscrit pas encore rattaché à une
     * organisation (cf. RegistrationService) — dans ce cas, aucune recherche de doublon n'est
     * possible (rien à scoper), une nouvelle Personne est toujours créée.
     *
     * @param  array{nom?: ?string, prenom?: ?string, telephone?: ?string, email?: ?string, pays?: ?string, code_pays?: ?string, code_phone_pays?: ?string, ville?: ?string, adresse?: ?string}  $attributs
     */
    public static function resoudreOuCreer(?string $organizationId, array $attributs): self
    {
        $telephoneNormalise = ! empty($attributs['telephone'])
            ? static::normaliserTelephone($attributs['telephone'])
            : null;

        if ($telephoneNormalise && $organizationId !== null) {
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

    /**
     * Forme canonique utilisée uniquement pour la comparaison/l'unicité (chiffres seuls,
     * indépendante de la présence d'un "+" ou d'espaces) — le téléphone affiché reste
     * `telephone`, jamais cette valeur.
     */
    public static function normaliserTelephone(string $telephone): string
    {
        return preg_replace('/\D+/', '', $telephone) ?? '';
    }
}
