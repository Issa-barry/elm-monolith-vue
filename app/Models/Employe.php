<?php

namespace App\Models;

use App\Enums\StatutContrat;
use App\Enums\StatutEmploye;
use App\Enums\TypeEmploye;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Employe extends Model
{
    use HasFactory, HasUlids, SoftDeletes;

    // Identité (nom/prenom/email/telephone) portée par Personne — jamais de colonne
    // équivalente ici, cf. accesseurs ci-dessous.
    protected $fillable = [
        'organization_id',
        'personne_id',
        'matricule',
        'type_employe',
        'site_id',
        'fonction_rh_id',
        'service_rh_id',
        'statut',
        'date_embauche',
        'date_sortie',
        'motif_sortie',
        'numero_cnss',
        'contact_urgence_nom',
        'contact_urgence_lien',
        'contact_urgence_telephone',
    ];

    // Sans $appends, ces accesseurs (proxy vers Personne) sont silencieusement absents de
    // toute sérialisation JSON/array du modèle brut (cf. incident User — HandleInertiaRequests).
    protected $appends = ['nom_complet', 'nom', 'prenom', 'email', 'telephone'];

    // Donnée sensible : jamais dans une sérialisation brute, exposée explicitement par
    // EmployeController seulement avec `rh-employes.donnees_sensibles`.
    protected $hidden = ['numero_cnss'];

    protected function casts(): array
    {
        return [
            'type_employe' => TypeEmploye::class,
            'statut' => StatutEmploye::class,
            'date_embauche' => 'date',
            'date_sortie' => 'date',
        ];
    }

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

    public function getEmailAttribute(): ?string
    {
        return $this->personne?->email;
    }

    public function getTelephoneAttribute(): ?string
    {
        return $this->personne?->telephone;
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function personne(): BelongsTo
    {
        return $this->belongsTo(Personne::class);
    }

    /**
     * Cache dénormalisé "site actuel" — maintenu par EmployeAffectationService à chaque
     * affectation. La source de vérité (avec historique) reste employe_affectations, cf.
     * affectationActive()/affectations().
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** Cache dénormalisé "fonction actuelle" — même remarque que site(). */
    public function fonction(): BelongsTo
    {
        return $this->belongsTo(FonctionRh::class, 'fonction_rh_id');
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(ServiceRh::class, 'service_rh_id');
    }

    /** Donnée sensible : à n'exposer qu'avec `rh-employes.donnees_sensibles`. */
    public function coordonneesPaiement(): HasOne
    {
        return $this->hasOne(EmployeCoordonneesPaiement::class);
    }

    public function affectations(): HasMany
    {
        return $this->hasMany(EmployeAffectation::class)->orderByDesc('debut_at');
    }

    public function affectationActive(): HasOne
    {
        return $this->hasOne(EmployeAffectation::class)->whereNull('fin_at');
    }

    public function contrats(): HasMany
    {
        return $this->hasMany(Contrat::class);
    }

    public function contratActif(): HasOne
    {
        return $this->hasOne(Contrat::class)
            ->where('statut_contrat', StatutContrat::ACTIF->value)
            ->latest();
    }
}
