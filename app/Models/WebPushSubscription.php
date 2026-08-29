<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un abonnement Web Push navigateur — endpoint + clés de chiffrement
 * (p256dh/auth), données techniques sensibles : jamais sérialisées (`$hidden`),
 * jamais loguées en clair (cf. WebPushService, qui ne logue qu'un `id`).
 */
class WebPushSubscription extends Model
{
    use HasUlids;

    protected $fillable = [
        'user_id',
        'endpoint',
        'endpoint_hash',
        'p256dh',
        'auth',
        'content_encoding',
        'user_agent',
        'last_used_at',
    ];

    protected $hidden = [
        'endpoint',
        'p256dh',
        'auth',
    ];

    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
        ];
    }

    public static function hashEndpoint(string $endpoint): string
    {
        return hash('sha256', $endpoint);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
