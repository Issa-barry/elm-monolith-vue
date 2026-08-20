<?php

namespace App\Models;

use App\Enums\StatutImportProduits;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportProduits extends Model
{
    use HasFactory, HasUlids;

    protected $table = 'imports_produits';

    protected $fillable = [
        'organization_id',
        'user_id',
        'fichier_original',
        'fichier_path',
        'fichier_hash',
        'statut',
        'nb_lignes_total',
        'nb_lignes_creation',
        'nb_lignes_mise_a_jour',
        'nb_lignes_inchange',
        'nb_lignes_erreur',
        'nb_produits_crees',
        'nb_produits_mis_a_jour',
        'rapport',
        'erreur_technique',
        'analyse_le',
        'demarre_le',
        'termine_le',
    ];

    protected function casts(): array
    {
        return [
            'statut' => StatutImportProduits::class,
            'rapport' => 'array',
            'analyse_le' => 'datetime',
            'demarre_le' => 'datetime',
            'termine_le' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Un fichier détecté comme déjà importé (même hash qu'un import TERMINE antérieur de la
     * même organisation, contenant des créations sans SKU — cf. ImportProduitsParser) ne peut
     * jamais être confirmé, même sans autre erreur : le confirmer recréerait silencieusement
     * les mêmes produits.
     */
    public function estPret(): bool
    {
        return $this->statut === StatutImportProduits::ANALYSE
            && $this->nb_lignes_erreur === 0
            && empty($this->rapport['fichier_deja_importe'] ?? null);
    }
}
