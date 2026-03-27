<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;

class Parametre extends Model
{
    // ── Groupes ───────────────────────────────────────────────────────────────
    public const GROUPE_GENERAL = 'general';
    public const GROUPE_PACKING = 'packing';

    // ── Types ─────────────────────────────────────────────────────────────────
    public const TYPE_STRING  = 'string';
    public const TYPE_INTEGER = 'integer';
    public const TYPE_BOOLEAN = 'boolean';
    public const TYPE_JSON    = 'json';

    // ── Clés ──────────────────────────────────────────────────────────────────
    public const CLE_SEUIL_STOCK_FAIBLE           = 'seuil_stock_faible';
    public const CLE_NOTIFICATIONS_STOCK_ACTIVES  = 'notifications_stock_actives';
    public const CLE_PRIX_ROULEAU_DEFAUT          = 'prix_rouleau_defaut';
    public const CLE_PRODUIT_ROULEAU_ID           = 'produit_rouleau_id';

    protected $fillable = [
        'organization_id',
        'cle',
        'valeur',
        'type',
        'groupe',
        'description',
    ];

    // ── Cache ─────────────────────────────────────────────────────────────────

    private static function cacheKey(int $orgId, string $cle): string
    {
        return "parametre_{$orgId}_{$cle}";
    }

    // ── Lecture / écriture ────────────────────────────────────────────────────

    public static function get(int $orgId, string $cle, mixed $default = null): mixed
    {
        return Cache::remember(self::cacheKey($orgId, $cle), 3600, function () use ($orgId, $cle, $default) {
            $param = static::where('organization_id', $orgId)->where('cle', $cle)->first();
            if (! $param) {
                return $default;
            }
            return self::castValue($param->valeur, $param->type);
        });
    }

    public static function set(int $orgId, string $cle, mixed $valeur): void
    {
        static::where('organization_id', $orgId)->where('cle', $cle)->update(['valeur' => (string) $valeur]);
        Cache::forget(self::cacheKey($orgId, $cle));
    }

    public static function castValue(?string $valeur, string $type): mixed
    {
        if ($valeur === null) {
            return null;
        }

        return match ($type) {
            self::TYPE_INTEGER => (int) $valeur,
            self::TYPE_BOOLEAN => in_array($valeur, ['1', 'true', 'yes'], true),
            self::TYPE_JSON    => json_decode($valeur, true),
            default            => $valeur,
        };
    }

    public static function clearCache(int $orgId): void
    {
        foreach ([
            self::CLE_SEUIL_STOCK_FAIBLE,
            self::CLE_NOTIFICATIONS_STOCK_ACTIVES,
            self::CLE_PRIX_ROULEAU_DEFAUT,
            self::CLE_PRODUIT_ROULEAU_ID,
        ] as $cle) {
            Cache::forget(self::cacheKey($orgId, $cle));
        }
    }

    // ── Accesseurs nommés ─────────────────────────────────────────────────────

    public static function getSeuilStockFaible(int $orgId): int
    {
        return (int) self::get($orgId, self::CLE_SEUIL_STOCK_FAIBLE, 10);
    }

    public static function isNotificationsStockActives(int $orgId): bool
    {
        return (bool) self::get($orgId, self::CLE_NOTIFICATIONS_STOCK_ACTIVES, true);
    }

    public static function getPrixRouleauDefaut(int $orgId): int
    {
        return (int) self::get($orgId, self::CLE_PRIX_ROULEAU_DEFAUT, 500);
    }

    public static function getProduitRouleauId(int $orgId): ?int
    {
        $val = self::get($orgId, self::CLE_PRODUIT_ROULEAU_ID, null);
        return $val !== null ? (int) $val : null;
    }

    // ── Relations ─────────────────────────────────────────────────────────────

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
