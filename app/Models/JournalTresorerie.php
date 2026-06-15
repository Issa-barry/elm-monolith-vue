<?php

namespace App\Models;

use App\Enums\CategorieJournal;
use App\Enums\SensJournal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JournalTresorerie extends Model
{
    use HasUlids;

    protected $table = 'journal_tresorerie';

    protected $fillable = [
        'organization_id',
        'site_id',
        'date_operation',
        'sens',
        'categorie',
        'libelle',
        'reference',
        'montant',
        'source_type',
        'source_id',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'date_operation' => 'date',
            'montant' => 'decimal:2',
            'sens' => SensJournal::class,
            'categorie' => CategorieJournal::class,
        ];
    }

    // ── Relations ─────────────────────────────────────────────────────────────

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function createur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopeForOrg(Builder $query, string $orgId): Builder
    {
        return $query->where('organization_id', $orgId);
    }

    public function scopeEntrees(Builder $query): Builder
    {
        return $query->where('sens', SensJournal::ENTREE->value);
    }

    public function scopeSorties(Builder $query): Builder
    {
        return $query->where('sens', SensJournal::SORTIE->value);
    }

    public function scopeByPeriode(Builder $query, $debut, $fin): Builder
    {
        return $query->whereBetween('date_operation', [$debut, $fin]);
    }
}
