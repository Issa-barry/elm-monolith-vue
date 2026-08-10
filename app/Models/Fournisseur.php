<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Fournisseur extends Model
{
    use HasFactory, HasUlids, SoftDeletes;

    protected $fillable = [
        'organization_id',
        'reference',
        'nom',
        'prenom',
        'raison_sociale',
        'email',
        'phone',
        'code_phone_pays',
        'code_pays',
        'pays',
        'ville',
        'adresse',
        'notes',
        'is_active',
    ];

    protected $appends = ['nom_complet'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    // ── Boot ──────────────────────────────────────────────────────────────────

    protected static function booted(): void
    {
        static::creating(function (Fournisseur $f) {
            if (empty($f->reference)) {
                $f->reference = self::generateReference();
            }
            $f->code_pays = self::normalizeIsoCountryCode($f->code_pays) ?? 'GN';
            $f->code_phone_pays = self::normalizeDialCode($f->code_phone_pays) ?? '+224';
            $f->phone = self::normalizePhoneE164($f->phone, $f->code_phone_pays);
            if (empty($f->pays)) {
                $f->pays = 'Guinée';
            }
        });

        static::updating(function (Fournisseur $f) {
            $f->code_pays = self::normalizeIsoCountryCode($f->code_pays) ?? 'GN';
            $f->code_phone_pays = self::normalizeDialCode($f->code_phone_pays) ?? '+224';
            $f->phone = self::normalizePhoneE164($f->phone, $f->code_phone_pays);
        });
    }

    // ── Référence auto ────────────────────────────────────────────────────────

    // Préfixe 'F' (vs 'P' pour Prestataire) — permet de distinguer les deux séries de
    // références au premier coup d'œil malgré le même format lettres/chiffres.
    public static function generateReference(): string
    {
        do {
            $letters = strtoupper(substr(str_shuffle('ABCDEFGHIJKLMNOPQRSTUVWXYZ'), 0, 2));
            $digits = str_pad(rand(0, 9999), 4, '0', STR_PAD_LEFT);
            $ref = 'F'.$letters.$digits;
        } while (self::withTrashed()->where('reference', $ref)->exists());

        return $ref;
    }

    // ── Mutateurs ─────────────────────────────────────────────────────────────

    public function setNomAttribute(mixed $value): void
    {
        $v = self::normalizeIdentity($value);
        $this->attributes['nom'] = $v !== null ? mb_strtoupper($v, 'UTF-8') : null;
    }

    public function setPrenomAttribute(mixed $value): void
    {
        $v = self::normalizeIdentity($value);
        $this->attributes['prenom'] = $v !== null ? mb_convert_case($v, MB_CASE_TITLE, 'UTF-8') : null;
    }

    public function setRaisonSocialeAttribute(mixed $value): void
    {
        $v = self::normalizeIdentity($value);
        $this->attributes['raison_sociale'] = $v !== null ? mb_convert_case($v, MB_CASE_TITLE, 'UTF-8') : null;
    }

    public function setEmailAttribute(mixed $value): void
    {
        $this->attributes['email'] = self::normalizeEmail($value);
    }

    public function setVilleAttribute(mixed $value): void
    {
        $v = self::normalizeIdentity($value);
        $this->attributes['ville'] = $v !== null ? mb_convert_case($v, MB_CASE_TITLE, 'UTF-8') : null;
    }

    public function setAdresseAttribute(mixed $value): void
    {
        $this->attributes['adresse'] = self::normalizeIdentity($value);
    }

    public function setNotesAttribute(mixed $value): void
    {
        $this->attributes['notes'] = self::normalizeIdentity($value);
    }

    // ── Accesseurs ────────────────────────────────────────────────────────────

    public function getNomCompletAttribute(): ?string
    {
        if (! empty($this->raison_sociale)) {
            return $this->raison_sociale;
        }
        $full = trim(implode(' ', array_filter([$this->prenom, $this->nom])));

        return $full !== '' ? $full : null;
    }

    // ── Relations ─────────────────────────────────────────────────────────────

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopeActifs(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }

    // ── Normalisation statique (identique à Prestataire — même patron pays/téléphone) ──────

    public static function normalizeEmail(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $v = trim((string) $value);

        return $v !== '' ? strtolower($v) : null;
    }

    public static function normalizeIsoCountryCode(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $v = preg_replace('/[^A-Z]/', '', strtoupper(trim((string) $value))) ?? '';

        return $v !== '' ? substr($v, 0, 2) : null;
    }

    public static function normalizeDialCode(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $v = trim((string) $value);
        if ($v === '') {
            return null;
        }
        if (str_starts_with($v, '00')) {
            $v = '+'.substr($v, 2);
        }
        $digits = preg_replace('/\D/', '', $v) ?? '';

        return $digits !== '' ? '+'.substr($digits, 0, 4) : null;
    }

    public static function normalizePhoneE164(mixed $value, mixed $dialCode = null): ?string
    {
        if ($value === null) {
            return null;
        }
        $phone = trim((string) $value);
        if ($phone === '') {
            return null;
        }
        if (str_starts_with($phone, '00')) {
            $phone = '+'.substr($phone, 2);
        }
        if (! str_starts_with($phone, '+')) {
            $local = ltrim(preg_replace('/\D/', '', $phone) ?? '', '0');
            $cc = self::normalizeDialCode($dialCode) ?? '+224';
            $phone = $local !== '' ? $cc.$local : null;
        }
        if ($phone === null) {
            return null;
        }
        $digits = preg_replace('/\D/', '', ltrim($phone, '+')) ?? '';

        return $digits !== '' ? '+'.$digits : null;
    }

    private static function normalizeIdentity(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $v = trim(preg_replace('/\s+/u', ' ', (string) $value) ?? '');

        return $v !== '' ? $v : null;
    }
}
