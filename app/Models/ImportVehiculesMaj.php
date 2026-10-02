<?php

namespace App\Models;

use App\Enums\StatutImportVehiculesMaj;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Import de mise à jour en masse des véhicules (site, capacités par catégorie, usages
 * vente/logistique) à partir d'un export "Exporter pour mise à jour" réimporté — jamais de
 * création de véhicule (cf. ImportVehiculesMajParser/Executor, entièrement séparés de
 * ImportFlotte qui reste le seul chemin de création).
 */
class ImportVehiculesMaj extends Model
{
    use HasFactory, HasUlids;

    protected $table = 'imports_vehicules_maj';

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
        'nb_vehicules_mis_a_jour',
        'rapport',
        'erreur_technique',
        'analyse_le',
        'demarre_le',
        'termine_le',
    ];

    protected function casts(): array
    {
        return [
            'statut' => StatutImportVehiculesMaj::class,
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
        return $this->statut === StatutImportVehiculesMaj::ANALYSE && $this->nb_lignes_erreur === 0;
    }
}
