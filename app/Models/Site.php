<?php

namespace App\Models;

use App\Enums\SiteStatut;
use App\Enums\SiteType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Site extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'organization_id',
        'nom',
        'code',
        'type',
        'statut',
        'localisation',
        'pays',
        'ville',
        'quartier',
        'description',
        'parent_id',
        'latitude',
        'longitude',
        'telephone',
        'email',
    ];

    protected function casts(): array
    {
        return [
            'type' => SiteType::class,
            'statut' => SiteStatut::class,
        ];
    }

    protected $appends = ['type_label', 'statut_label'];

    // ── Boot ──────────────────────────────────────────────────────────────────

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Site $site) {
            if (empty($site->statut)) {
                $site->statut = SiteStatut::ACTIVE;
            }
            if (empty($site->code)) {
                $orgId = $site->organization_id;
                $num = static::withTrashed()->where('organization_id', $orgId)->count() + 1;
                do {
                    $code = str_pad((string) $num, 3, '0', STR_PAD_LEFT);
                    $num++;
                } while (static::withTrashed()->where('organization_id', $orgId)->where('code', $code)->exists());
                $site->code = $code;
            }
        });
    }

    // ── Accessors ─────────────────────────────────────────────────────────────

    public function getTypeLabelAttribute(): string
    {
        return $this->type instanceof SiteType
            ? $this->type->label()
            : '';
    }

    public function getStatutLabelAttribute(): string
    {
        return $this->statut instanceof SiteStatut
            ? $this->statut->label()
            : '';
    }

    // ── Relations ─────────────────────────────────────────────────────────────

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'parent_id');
    }

    public function enfants(): HasMany
    {
        return $this->hasMany(Site::class, 'parent_id');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_sites')
            ->withPivot('role', 'is_default')
            ->withTimestamps();
    }

    public function userSites(): HasMany
    {
        return $this->hasMany(UserSite::class);
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(UserInvitation::class);
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopeActives($query)
    {
        return $query->where('statut', SiteStatut::ACTIVE->value);
    }

    public function scopeDuType($query, string $type)
    {
        return $query->where('type', $type);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    public function isSiege(): bool
    {
        return $this->type === SiteType::SIEGE;
    }

    public function isActive(): bool
    {
        return $this->statut === SiteStatut::ACTIVE;
    }
}
