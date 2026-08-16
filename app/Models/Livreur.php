<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Livreur extends Model
{
    use HasFactory, HasUlids, SoftDeletes;

    // nom/prenom/telephone (identité civile) portés par Personne — jamais de colonne
    // équivalente ici. nom_complet reste propre à ce rôle : désignation d'affichage
    // (voir EquipeLivraisonController), pas nécessairement l'état civil de la personne.
    protected $fillable = [
        'organization_id',
        'user_id',
        'personne_id',
        'nom_complet',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    // Sans $appends, ces accesseurs (proxy vers Personne) sont silencieusement absents de
    // toute sérialisation JSON/array du modèle brut (cf. incident User — HandleInertiaRequests).
    // nom_complet n'a pas besoin d'être listé : c'est une vraie colonne, pas un accesseur.
    protected $appends = ['nom', 'prenom', 'telephone'];

    // ── Accesseurs — proxy en lecture seule vers Personne ───────────────────────

    public function getNomAttribute(): ?string
    {
        return $this->personne?->nom;
    }

    public function getPrenomAttribute(): ?string
    {
        return $this->personne?->prenom;
    }

    public function getTelephoneAttribute(): ?string
    {
        return $this->personne?->telephone;
    }

    /**
     * Libellé garanti non vide pour les usages internes qui ne tolèrent pas
     * de null (colonnes NOT NULL type beneficiaire_nom, descriptions d'audit,
     * snapshots) : nom_complet en priorité (seul champ saisi/affiché côté
     * Eau La Maman), puis repli sur prenom/nom pour compatibilité avec les
     * anciennes données, puis téléphone, puis un libellé neutre.
     */
    public function libelleAffichage(): string
    {
        return $this->nom_complet
            ?: trim("{$this->prenom} {$this->nom}")
            ?: $this->telephone
            ?: 'Livreur';
    }

    /**
     * Désignation par défaut affectée à la création (import flotte, création
     * d'équipe) quand ni nom_complet ni prenom/nom ne sont saisis — on ne
     * laisse jamais nom_complet vide : "Chauffeur-1 Baba Ousou". $numero est
     * la position du membre parmi les autres du même rôle dans cette équipe
     * (cohérent avec EquipeStepperModal::membreLabel() côté frontend).
     */
    public static function designationParDefaut(string $role, int $numero, string $nomVehicule): string
    {
        $label = $role === 'chauffeur' ? 'Chauffeur' : 'Convoyeur';

        return trim("{$label}-{$numero} {$nomVehicule}");
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

    public function equipes(): BelongsToMany
    {
        return $this->belongsToMany(EquipeLivraison::class, 'equipe_livreurs', 'livreur_id', 'equipe_id')
            ->withPivot(['role', 'montant_par_pack', 'taux_commission', 'ordre'])
            ->withTimestamps();
    }
}
