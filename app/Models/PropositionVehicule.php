<?php

namespace App\Models;

use App\Enums\StatutPropositionVehicule;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class PropositionVehicule extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'propositions_vehicules';

    protected $fillable = [
        'organization_id',
        'user_id',
        'client_id',
        'proprietaire_id',
        'livreur_id',
        'nom_contact',
        'telephone_contact',
        'nom_vehicule',
        'marque',
        'modele',
        'immatriculation',
        'type_vehicule',
        'capacite_packs',
        'commentaire',
        'statut',
        'decision_note',
        'traitee_at',
        'traitee_par',
    ];

    protected $appends = ['statut_label'];

    protected function casts(): array
    {
        return [
            'capacite_packs' => 'integer',
            'traitee_at' => 'datetime',
            'statut' => StatutPropositionVehicule::class,
        ];
    }

    public function getStatutLabelAttribute(): string
    {
        return $this->statut instanceof StatutPropositionVehicule
            ? $this->statut->label()
            : '';
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function proprietaire(): BelongsTo
    {
        return $this->belongsTo(Proprietaire::class);
    }

    public function livreur(): BelongsTo
    {
        return $this->belongsTo(Livreur::class);
    }

    public function traitePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'traitee_par');
    }
}
