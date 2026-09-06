<?php

namespace App\Models;

use App\Enums\ClientType;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Client extends Model
{
    use HasFactory, HasUlids, SoftDeletes;

    protected $fillable = [
        'organization_id',
        'user_id',
        'nom',
        'prenom',
        'nom_complet',
        'email',
        'telephone',
        'code_phone_pays',
        'code_pays',
        'pays',
        'ville',
        'adresse',
        'is_active',
        'type',
        'cashback_eligible',
        'cashback_montant_par_pack',
        'derogation_impayes_autorisee',
        'seuil_derogation_impayes',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'type' => ClientType::class,
            'cashback_eligible' => 'boolean',
            'cashback_montant_par_pack' => 'integer',
            'derogation_impayes_autorisee' => 'boolean',
            'seuil_derogation_impayes' => 'integer',
        ];
    }

    /**
     * Comme Livreur : nom_complet est le champ réellement saisi/affiché dans l'interface.
     * nom/prenom ne sont conservés que pour compat avec d'anciennes données (fallback ici,
     * jamais redemandés dans le formulaire).
     */
    public function getNomCompletAttribute(): string
    {
        $saisi = trim((string) ($this->attributes['nom_complet'] ?? ''));

        return $saisi !== '' ? $saisi : trim("{$this->prenom} {$this->nom}");
    }

    public function isExterne(): bool
    {
        return $this->type === ClientType::EXTERNE;
    }

    public function isRevendeur(): bool
    {
        return $this->type === ClientType::REVENDEUR;
    }

    public function isGrossiste(): bool
    {
        return $this->type === ClientType::GROSSISTE;
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function vehicules(): HasMany
    {
        return $this->hasMany(ClientVehicle::class);
    }

    public function cashbackTransactions(): HasMany
    {
        return $this->hasMany(CashbackTransaction::class);
    }

    /** Tarifs Grossiste (Enlèvement/Livraison par catégorie) propres à ce client — cf. docs/grossiste.md. */
    public function tarifsGrossiste(): HasMany
    {
        return $this->hasMany(CategorieTarifGrossiste::class);
    }
}
