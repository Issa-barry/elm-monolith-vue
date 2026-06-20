<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class DroitCreationDepense extends Model
{
    use HasUlids;

    protected $table = 'droit_creation_depenses';

    protected $fillable = [
        'organization_id',
        'role_name',
        'perimetre',
        'sites',
        'is_actif',
        'peut_valider',
    ];

    protected $casts = [
        'sites' => 'array',
        'is_actif' => 'boolean',
        'peut_valider' => 'boolean',
    ];

    public function isToutesAgences(): bool
    {
        return $this->perimetre === 'toutes_agences';
    }

    public function isAgencesSelectionnees(): bool
    {
        return $this->perimetre === 'agences_selectionnees';
    }
}
