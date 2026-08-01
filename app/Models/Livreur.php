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

    protected $fillable = [
        'organization_id',
        'user_id',
        // Identité civile : conservée pour d'autres projets/usages éventuels,
        // jamais affichée ni demandée dans l'interface Eau La Maman (voir
        // EquipeLivraisonController). L'UI n'utilise que nom_complet.
        'nom',
        'prenom',
        'nom_complet',
        'telephone',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    // ── Accessor ──────────────────────────────────────────────────────────────

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

    // ── Relations ─────────────────────────────────────────────────────────────

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
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
