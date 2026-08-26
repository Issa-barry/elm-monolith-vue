<?php

namespace App\Models;

use App\Enums\StatutVerificationPieceIdentite;
use App\Enums\TypeProprietaire;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Proprietaire extends Model
{
    use HasFactory, HasUlids, SoftDeletes;

    protected $casts = [
        'is_active' => 'boolean',
        'type' => TypeProprietaire::class,
    ];

    // Identité (nom/prenom/surnom/email/telephone/adresse/ville/pays) portée par Personne —
    // jamais de colonne équivalente ici, cf. accesseurs ci-dessous. `raison_sociale` reste ici
    // (pas sur Personne, partagée avec User/Employe/Livreur) : seul un Proprietaire peut être
    // une entreprise, `personne_id` désigne alors son contact (nom/téléphone), pas l'entreprise.
    protected $fillable = [
        'organization_id',
        'user_id',
        'personne_id',
        'type',
        'raison_sociale',
        'is_active',
    ];

    // Sans $appends, ces accesseurs (proxy vers Personne) sont silencieusement absents de
    // toute sérialisation JSON/array du modèle brut (cf. incident User — HandleInertiaRequests).
    protected $appends = [
        'nom_complet', 'nom', 'prenom', 'surnom', 'email', 'telephone',
        'adresse', 'ville', 'pays', 'code_pays', 'code_phone_pays',
        'nom_affichage', 'est_entreprise',
    ];

    /**
     * Libellé d'affichage unique utilisé partout où un propriétaire est montré (fiche véhicule,
     * étape "Partage" de l'équipe, listings) : la raison sociale pour une entreprise, sinon
     * l'identité civile du contact — jamais les deux mélangés.
     */
    public function getNomAffichageAttribute(): string
    {
        if ($this->type === TypeProprietaire::ENTREPRISE) {
            return $this->raison_sociale ?? $this->nom_complet;
        }

        return $this->nom_complet;
    }

    public function getEstEntrepriseAttribute(): bool
    {
        return $this->type === TypeProprietaire::ENTREPRISE;
    }

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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function vehicules(): HasMany
    {
        return $this->hasMany(Vehicule::class, 'proprietaire_id');
    }

    /** Les pièces d'identité appartiennent à la personne, pas à un rôle particulier. */
    public function piecesIdentite(): MorphMany
    {
        return $this->personne->piecesIdentite();
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
     * Propriétaire par défaut représentant l'organisation elle-même — assigné automatiquement
     * à un véhicule quand aucun propriétaire tiers n'est explicitement choisi. Centralise ce qui
     * était dupliqué entre VehiculeController et EquipeLivraisonController.
     *
     * Source de vérité : Organization::proprietaire_interne_id (relation explicite par
     * organisation, fixée à l'installation — cf. InstallationService::install()). N'infère
     * plus jamais ce propriétaire d'un numéro de téléphone particulier : cette ancienne
     * implémentation ne fonctionnait que pour l'organisation historique de démonstration
     * seedée par ProprietairesSeeder, et renvoyait toujours null pour toute autre organisation.
     */
    public static function interneParDefautId(string $organizationId): ?string
    {
        return Organization::where('id', $organizationId)->value('proprietaire_interne_id');
    }
}
