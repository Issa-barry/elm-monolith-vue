<?php

namespace App\Models;

use App\Enums\PrestataireType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Prestataire extends Model
{
    use HasFactory, SoftDeletes;

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
        'type',
        'notes',
        'is_active',
    ];

    protected $appends = ['nom_complet', 'type_label'];

    protected function casts(): array
    {
        return [
            'type' => PrestataireType::class,
            'is_active' => 'boolean',
        ];
    }

    // ── Boot ──────────────────────────────────────────────────────────────────

    protected static function booted(): void
    {
        static::creating(function (Prestataire $p) {
            if (empty($p->reference)) {
                $p->reference = self::generateReference();
            }
            if (empty($p->type)) {
                $p->type = PrestataireType::FOURNISSEUR;
            }
            $p->code_pays = self::normalizeIsoCountryCode($p->code_pays) ?? 'GN';
            $p->code_phone_pays = self::normalizeDialCode($p->code_phone_pays) ?? '+224';
            $p->phone = self::normalizePhoneE164($p->phone, $p->code_phone_pays);
            if (empty($p->pays)) {
                $p->pays = 'Guinée';
            }
        });

        static::updating(function (Prestataire $p) {
            $p->code_pays = self::normalizeIsoCountryCode($p->code_pays) ?? 'GN';
            $p->code_phone_pays = self::normalizeDialCode($p->code_phone_pays) ?? '+224';
            $p->phone = self::normalizePhoneE164($p->phone, $p->code_phone_pays);
        });
    }

    // ── Référence auto ────────────────────────────────────────────────────────

    public static function generateReference(): string
    {
        do {
            $letters = strtoupper(substr(str_shuffle('ABCDEFGHIJKLMNOPQRSTUVWXYZ'), 0, 2));
            $digits = str_pad(rand(0, 9999), 4, '0', STR_PAD_LEFT);
            $ref = 'P'.$letters.$digits;
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

    public function getTypeLabelAttribute(): string
    {
        return $this->type instanceof PrestataireType ? $this->type->label() : '';
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

    public function scopeParType(Builder $q, PrestataireType|string $type): Builder
    {
        $value = $type instanceof PrestataireType ? $type->value : $type;

        return $q->where('type', $value);
    }

    // ── Normalisation statique ────────────────────────────────────────────────

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
