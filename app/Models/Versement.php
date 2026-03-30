<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

class Versement extends Model
{
    use HasFactory;

    protected $fillable = [
        'packing_id',
        'date',
        'montant',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'date' => 'date:Y-m-d',
        'montant' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (Versement $v) {
            if (Auth::check()) {
                $v->created_by = Auth::id();
            }
        });

        static::created(function (Versement $v) {
            $v->packing->mettreAJourStatut();
        });

        static::deleted(function (Versement $v) {
            $v->packing->mettreAJourStatut();
        });
    }

    public function packing(): BelongsTo
    {
        return $this->belongsTo(Packing::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
