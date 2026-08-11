<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Véhicule d'un partenaire (Client::type = PARTENAIRE) — volontairement léger et hors flotte
 * gérée. Ne porte jamais d'équipe, de propriétaire, de capacités, de dépenses ni de
 * commissions : c'est une simple information de transport facultative, jamais un `Vehicule`.
 * Tous les champs métier sont nullable, seul client_id est requis — cf. migration
 * create_client_vehicules_table.
 */
class ClientVehicle extends Model
{
    use HasFactory, HasUlids, SoftDeletes;

    protected $table = 'client_vehicules';

    protected $fillable = [
        'organization_id',
        'client_id',
        'nom_vehicule',
        'immatriculation',
        'chauffeur_nom',
        'chauffeur_telephone',
        'chauffeur_code_pays',
        'chauffeur_code_phone_pays',
        'chauffeur_pays',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * Libellé lisible pour les pickers ("Toyota Hilux — RC-1234-AB", ou juste
     * l'immatriculation/libellé seul si l'un des deux manque, ou un repli neutre si les deux
     * sont vides — cas légitime, la plaque n'est jamais obligatoire).
     */
    public function getLibelleAfficheAttribute(): string
    {
        $parts = array_filter([$this->nom_vehicule, $this->immatriculation]);

        return $parts ? implode(' — ', $parts) : 'Véhicule partenaire';
    }
}
