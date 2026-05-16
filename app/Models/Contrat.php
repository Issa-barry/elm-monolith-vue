<?php

namespace App\Models;

use App\Enums\StatutContrat;
use App\Enums\TypeContrat;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Contrat extends Model
{
    use HasFactory, HasUlids, SoftDeletes;

    protected $fillable = [
        'organization_id',
        'employe_id',
        'type_contrat',
        'date_debut',
        'date_fin',
        'salaire_base',
        'statut_contrat',
    ];

    protected function casts(): array
    {
        return [
            'type_contrat'   => TypeContrat::class,
            'statut_contrat' => StatutContrat::class,
            'date_debut'     => 'date',
            'date_fin'       => 'date',
            'salaire_base'   => 'decimal:2',
        ];
    }

    public function employe(): BelongsTo
    {
        return $this->belongsTo(Employe::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
