<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\User;

class VehiculeFrais extends Model
{
    protected $table = 'vehicule_frais';

    protected $fillable = ['vehicule_id', 'montant', 'type', 'commentaire', 'created_by'];

    protected function casts(): array
    {
        return [
            'montant' => 'decimal:2',
            'created_at' => 'datetime',
        ];
    }

    public function vehicule(): BelongsTo
    {
        return $this->belongsTo(Vehicule::class);
    }

    public function createur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
