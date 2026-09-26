<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Part PRÉPARÉE d'un livreur pour (équipe, catégorie) dans un brouillon de barème — jamais lue par
 * la génération ni par les contrôles de commande : elle ne devient un
 * EquipeLivraisonPartageCategorie qu'à la publication du brouillon.
 */
class CommissionBaremeBrouillonPartage extends Model
{
    use HasUlids;

    protected $table = 'commission_bareme_brouillon_partages';

    protected $fillable = [
        'brouillon_id',
        'equipe_id',
        'categorie_id',
        'livreur_id',
        'montant_unitaire',
        'signature_equipe',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'montant_unitaire' => 'integer',
        ];
    }

    public function brouillon(): BelongsTo
    {
        return $this->belongsTo(CommissionBaremeBrouillon::class, 'brouillon_id');
    }

    public function equipe(): BelongsTo
    {
        return $this->belongsTo(EquipeLivraison::class, 'equipe_id');
    }

    public function livreur(): BelongsTo
    {
        return $this->belongsTo(Livreur::class);
    }
}
