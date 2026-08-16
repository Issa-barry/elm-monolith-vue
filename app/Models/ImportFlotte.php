<?php

namespace App\Models;

use App\Enums\StatutImportFlotte;
use App\Enums\TypeImportFlotte;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportFlotte extends Model
{
    use HasFactory, HasUlids;

    protected $table = 'imports_flotte';

    protected $fillable = [
        'organization_id',
        'user_id',
        'fichier_original',
        'fichier_path',
        'statut',
        'type',
        'nb_lignes_total',
        'nb_groupes_valides',
        'nb_groupes_erreur',
        'nb_proprietaires_crees',
        'nb_vehicules_crees',
        'nb_livreurs_crees',
        'nb_equipes_creees',
        'rapport',
        'analyse_le',
        'demarre_le',
        'termine_le',
    ];

    protected function casts(): array
    {
        return [
            'statut' => StatutImportFlotte::class,
            'type' => TypeImportFlotte::class,
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
        return $this->statut === StatutImportFlotte::ANALYSE && $this->nb_groupes_erreur === 0;
    }
}
