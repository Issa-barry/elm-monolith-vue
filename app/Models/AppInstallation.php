<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Singleton — une seule ligne, jamais plus. Voir InstallationService::install() pour l'unique
 * point d'écriture de installed_at, et la migration pour pourquoi ce n'est pas juste un champ
 * dérivé de "une organisation existe".
 */
class AppInstallation extends Model
{
    protected $fillable = ['organization_id', 'installed_at'];

    protected function casts(): array
    {
        return [
            'installed_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public static function isInstalled(): bool
    {
        return static::whereNotNull('installed_at')->exists();
    }
}
