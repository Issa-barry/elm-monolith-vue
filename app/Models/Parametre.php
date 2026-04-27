<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;

class Parametre extends Model
{
    use HasUlids;

    // ── Groupes ───────────────────────────────────────────────────────────────
    public const GROUPE_GENERAL = 'general';

    public const GROUPE_PACKING = 'packing';

    // ── Types ─────────────────────────────────────────────────────────────────
    public const TYPE_STRING = 'string';

    public const TYPE_INTEGER = 'integer';

    public const TYPE_BOOLEAN = 'boolean';

    public const TYPE_JSON = 'json';

    public const TYPE_DECIMAL = 'decimal';

    // ── Groupes ───────────────────────────────────────────────────────────────
    public const GROUPE_VEHICULES = 'vehicules';

    public const GROUPE_CASHBACK = 'cashback';

    public const GROUPE_VENTES = 'ventes';

    // ── Clés ──────────────────────────────────────────────────────────────────
    public const CLE_SEUIL_STOCK_FAIBLE = 'seuil_stock_faible';

    public const CLE_NOTIFICATIONS_STOCK_ACTIVES = 'notifications_stock_actives';

    public const CLE_PRIX_ROULEAU_DEFAUT = 'prix_rouleau_defaut';

    public const CLE_PRODUIT_ROULEAU_ID = 'produit_rouleau_id';

    public const CLE_TAUX_PROPRIETAIRE_DEFAUT = 'taux_proprietaire_defaut';

    public const CLE_CASHBACK_SEUIL_ACHAT = 'cashback_seuil_achat';

    public const CLE_CASHBACK_MONTANT_GAIN = 'cashback_montant_gain';

    public const CLE_VENTES_COMMISSION_MODE = 'ventes_commission_mode';

    public const COMMISSION_MODE_COMMANDE_VALIDEE = 'commande_validee';

    public const COMMISSION_MODE_FACTURE_PAYEE = 'facture_payee';

    protected $fillable = [
        'organization_id',
        'cle',
        'valeur',
        'type',
        'groupe',
        'description',
    ];

    // ── Cache ─────────────────────────────────────────────────────────────────

    private static function cacheKey(string $orgId, string $cle): string
    {
        return "parametre_{$orgId}_{$cle}";
    }

    // ── Lecture / écriture ────────────────────────────────────────────────────

    public static function get(string $orgId, string $cle, mixed $default = null): mixed
    {
        return Cache::remember(self::cacheKey($orgId, $cle), 3600, function () use ($orgId, $cle, $default) {
            $param = static::where('organization_id', $orgId)->where('cle', $cle)->first();
            if (! $param) {
                return $default;
            }

            return self::castValue($param->valeur, $param->type);
        });
    }

    public static function set(string $orgId, string $cle, mixed $valeur): void
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
            self::TYPE_DECIMAL => round((float) $valeur, 2),
            self::TYPE_BOOLEAN => in_array($valeur, ['1', 'true', 'yes'], true),
            self::TYPE_JSON => json_decode($valeur, true),
            default => $valeur,
        };
    }

    public static function clearCache(string $orgId): void
    {
        foreach ([
            self::CLE_SEUIL_STOCK_FAIBLE,
            self::CLE_NOTIFICATIONS_STOCK_ACTIVES,
            self::CLE_PRIX_ROULEAU_DEFAUT,
            self::CLE_PRODUIT_ROULEAU_ID,
            self::CLE_TAUX_PROPRIETAIRE_DEFAUT,
            self::CLE_CASHBACK_SEUIL_ACHAT,
            self::CLE_CASHBACK_MONTANT_GAIN,
            self::CLE_VENTES_COMMISSION_MODE,
        ] as $cle) {
            Cache::forget(self::cacheKey($orgId, $cle));
        }
    }

    // ── Accesseurs nommés ─────────────────────────────────────────────────────

    public static function getSeuilStockFaible(string $orgId): int
    {
        return (int) self::get($orgId, self::CLE_SEUIL_STOCK_FAIBLE, 10);
    }

    public static function isNotificationsStockActives(string $orgId): bool
    {
        return (bool) self::get($orgId, self::CLE_NOTIFICATIONS_STOCK_ACTIVES, true);
    }

    public static function getPrixRouleauDefaut(string $orgId): int
    {
        return (int) self::get($orgId, self::CLE_PRIX_ROULEAU_DEFAUT, 500);
    }

    public static function getProduitRouleauId(string $orgId): ?string
    {
        $val = self::get($orgId, self::CLE_PRODUIT_ROULEAU_ID, null);

        return $val !== null ? (int) $val : null;
    }

    public static function getTauxProprietaireDefaut(string $orgId): float
    {
        return (float) self::get($orgId, self::CLE_TAUX_PROPRIETAIRE_DEFAUT, 60);
    }

    public static function getCashbackSeuilAchat(string $orgId): int
    {
        return (int) self::get($orgId, self::CLE_CASHBACK_SEUIL_ACHAT, 500000);
    }

    public static function getCashbackMontantGain(string $orgId): int
    {
        return (int) self::get($orgId, self::CLE_CASHBACK_MONTANT_GAIN, 25000);
    }

    public static function getVentesCommissionMode(string $orgId): string
    {
        $default = self::COMMISSION_MODE_COMMANDE_VALIDEE;
        $mode = (string) self::get($orgId, self::CLE_VENTES_COMMISSION_MODE, $default);

        if (! in_array($mode, self::ventesCommissionModes(), true)) {
            return $default;
        }

        return $mode;
    }

    public static function setVentesCommissionMode(string $orgId, string $mode): void
    {
        if (! in_array($mode, self::ventesCommissionModes(), true)) {
            throw new \InvalidArgumentException('Mode de commission de vente invalide.');
        }

        static::updateOrCreate(
            ['organization_id' => $orgId, 'cle' => self::CLE_VENTES_COMMISSION_MODE],
            [
                'organization_id' => $orgId,
                'cle' => self::CLE_VENTES_COMMISSION_MODE,
                'valeur' => $mode,
                'type' => self::TYPE_STRING,
                'groupe' => self::GROUPE_VENTES,
                'description' => 'Moment de génération des commissions de vente',
            ],
        );

        Cache::forget(self::cacheKey($orgId, self::CLE_VENTES_COMMISSION_MODE));
    }

    public static function ventesCommissionModes(): array
    {
        return [
            self::COMMISSION_MODE_COMMANDE_VALIDEE,
            self::COMMISSION_MODE_FACTURE_PAYEE,
        ];
    }

    // ── Relations ─────────────────────────────────────────────────────────────

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
