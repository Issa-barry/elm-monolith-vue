<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Rôle "parrain" porté par une Personne — un véhicule peut être parrainé par une personne déjà
 * connue de l'organisation (client, propriétaire...) sans jamais dupliquer son identité, cf.
 * Personne::resoudreOuCreer(). Calqué sur Proprietaire : l'identité civile reste entièrement sur
 * Personne, ce modèle ne porte que le rôle. Phase 1 : pas de commission, pas d'historique — cf.
 * docs/parrainage-vehicule.md.
 *
 * Une même Personne n'a qu'un seul Parrain (wrapper), réutilisé par plusieurs véhicules
 * (Vehicule::parrain_id -> parrains.id) : un parrain peut donc légitimement parrainer plusieurs
 * véhicules. Volontairement aucune contrainte unique en base sur personne_id — la déduplication
 * est faite applicativement par ParrainController::store() (Parrain::firstOrCreate()).
 */
class Parrain extends Model
{
    use HasFactory, HasUlids, SoftDeletes;

    protected $casts = [
        'is_active' => 'boolean',
    ];

    protected $fillable = [
        'organization_id',
        'personne_id',
        'is_active',
    ];

    // Sans $appends, ces accesseurs (proxy vers Personne) sont silencieusement absents de toute
    // sérialisation JSON/array du modèle brut (cf. incident User — HandleInertiaRequests, même
    // remarque sur Proprietaire).
    protected $appends = [
        'nom_complet', 'nom', 'prenom', 'surnom', 'email', 'telephone',
        'adresse', 'ville', 'pays', 'code_pays', 'code_phone_pays',
    ];

    // ── Accesseurs — proxy en lecture seule vers Personne ───────────────────────

    public function getNomCompletAttribute(): string
    {
        return $this->personne?->nom_complet ?? '';
    }

    public function getNomAttribute(): ?string
    {
        return $this->personne?->nom;
    }

    public function getPrenomAttribute(): ?string
    {
        return $this->personne?->prenom;
    }

    public function getSurnomAttribute(): ?string
    {
        return $this->personne?->surnom;
    }

    public function getEmailAttribute(): ?string
    {
        return $this->personne?->email;
    }

    public function getTelephoneAttribute(): ?string
    {
        return $this->personne?->telephone;
    }

    public function getAdresseAttribute(): ?string
    {
        return $this->personne?->adresse;
    }

    public function getVilleAttribute(): ?string
    {
        return $this->personne?->ville;
    }

    public function getPaysAttribute(): ?string
    {
        return $this->personne?->pays;
    }

    public function getCodePaysAttribute(): ?string
    {
        return $this->personne?->code_pays;
    }

    public function getCodePhonePaysAttribute(): ?string
    {
        return $this->personne?->code_phone_pays;
    }

    // ── Relations ─────────────────────────────────────────────────────────────

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function personne(): BelongsTo
    {
        return $this->belongsTo(Personne::class);
    }

    public function vehicules(): HasMany
    {
        return $this->hasMany(Vehicule::class, 'parrain_id');
    }
}
