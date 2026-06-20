<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class DroitAjustementStock extends Model
{
    use HasUlids;

    protected $table = 'droit_ajustement_stocks';

    protected $fillable = [
        'organization_id',
        'role_name',
        'perimetre',
        'sites',
        'peut_augmenter',
        'peut_diminuer',
    ];

    protected $casts = [
        'sites' => 'array',
        'peut_augmenter' => 'boolean',
        'peut_diminuer' => 'boolean',
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
