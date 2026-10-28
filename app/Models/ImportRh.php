<?php

namespace App\Models;

use App\Enums\StatutImportRh;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Import de mise à jour des dossiers et contrats RH (docs/import-dossiers-rh.md). Ne crée jamais de
 * salarié et ne modifie jamais un contrat actif existant.
 */
class ImportRh extends Model
{
    use HasUlids;

    protected $table = 'imports_rh';

    protected $fillable = [
        'organization_id',
        'user_id',
        'fichier_original',
        'fichier_path',
        'statut',
        'nb_lignes_total',
        'nb_lignes_maj',
        'nb_lignes_inchange',
        'nb_lignes_erreur',
        'nb_employes_mis_a_jour',
        'nb_contrats_crees',
        'rapport',
        'erreur_technique',
        'analyse_le',
        'demarre_le',
        'termine_le',
    ];

    protected function casts(): array
    {
        return [
            'statut' => StatutImportRh::class,
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

    public function estPret(): bool
    {
        return $this->statut === StatutImportRh::ANALYSE && $this->nb_lignes_erreur === 0 && $this->fichier_path !== null;
    }
}
