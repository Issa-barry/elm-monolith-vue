<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserInvitation extends Model
{
    use HasUlids;

    protected $fillable = [
        'email',
        'organization_id',
        'site_id',
        'role',
        'token_hash',
        'invited_by',
        'expires_at',
        'accepted_at',
        'revoked_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'accepted_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    // ── Status accessors ──────────────────────────────────────────────────────

    public function getStatutAttribute(): string
    {
        if ($this->accepted_at !== null) {
            return 'accepted';
        }
        if ($this->revoked_at !== null) {
            return 'revoked';
        }
        if ($this->expires_at->isPast()) {
            return 'expired';
        }

        return 'pending';
    }

    public function getStatutLabelAttribute(): string
    {
        return match ($this->statut) {
            'accepted' => 'Acceptée',
            'revoked' => 'Révoquée',
            'expired' => 'Expirée',
            default => 'En attente',
        };
    }

    public function isPending(): bool
    {
        return $this->statut === 'pending';
    }

    public function isExpired(): bool
    {
        return $this->statut === 'expired';
    }

    public function isAccepted(): bool
    {
        return $this->statut === 'accepted';
    }

    public function isRevoked(): bool
    {
        return $this->statut === 'revoked';
    }

    // ── Relations ─────────────────────────────────────────────────────────────

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }
}
