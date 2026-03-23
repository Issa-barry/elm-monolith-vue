<?php

namespace App\Models;

use App\Enums\SiteRole;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserSite extends Model
{
    protected $table = 'user_sites';

    protected $fillable = [
        'user_id',
        'site_id',
        'role',
        'is_default',
    ];

    protected function casts(): array
    {
        return [
            'role'       => SiteRole::class,
            'is_default' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
