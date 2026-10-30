<?php

namespace App\Models;

use App\Enums\StatutModeleContrat;
use App\Enums\TypeContrat;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Modèle de texte d'un contrat de travail, un par type (CDI, CDD) et par organisation
 * (docs/rh-contrat-pdf.md, ADR 0044). Les articles sont du texte brut avec des variables
 * `{{…}}` (App\Support\Rh\Contrats\VariablesContrat), jamais du HTML.
 */
class ModeleContrat extends Model
{
    use HasUlids;

    protected $table = 'modeles_contrat';

    protected $fillable = [
        'organization_id',
        'type_contrat',
        'titre',
        'articles',
        'statut',
        'revision',
        'modifie_par',
        'active_par',
        'active_le',
    ];

    protected function casts(): array
    {
        return [
            'type_contrat' => TypeContrat::class,
            'statut' => StatutModeleContrat::class,
            'articles' => 'array',
            'revision' => 'integer',
            'active_le' => 'datetime',
        ];
    }

    public function modificateur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'modifie_par');
    }

    public function estActif(): bool
    {
        return $this->statut === StatutModeleContrat::ACTIF;
    }
}
