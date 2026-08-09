<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class ProduitMedia extends Model
{
    use HasUlids;

    // Explicite : le pluralizer Laravel traite "media" comme un pluriel irrégulier
    // ("medium" -> "media") et inférerait "produit_media" (singulier de fait) sans ceci.
    protected $table = 'produit_medias';

    protected $fillable = [
        'organization_id',
        'produit_id',
        'path',
        'thumb_path',
        'original_name',
        'mime_type',
        'size',
        'width',
        'height',
        'is_primary',
        'position',
    ];

    protected $casts = [
        'size' => 'integer',
        'width' => 'integer',
        'height' => 'integer',
        'is_primary' => 'boolean',
        'position' => 'integer',
    ];

    public function produit(): BelongsTo
    {
        return $this->belongsTo(Produit::class);
    }

    /**
     * Variantes qui utilisent ce média — plusieurs variantes peuvent partager la même photo
     * (ex: toutes les pointures "Blanc"), cf. migration move_media_association_to_variantes_table.
     */
    public function variantes(): HasMany
    {
        return $this->hasMany(ProduitVariante::class, 'media_id');
    }

    public function getUrlAttribute(): ?string
    {
        return $this->path ? Storage::disk('public')->url($this->path) : null;
    }

    public function getThumbUrlAttribute(): ?string
    {
        $path = $this->thumb_path ?? $this->path;

        return $path ? Storage::disk('public')->url($path) : null;
    }
}
