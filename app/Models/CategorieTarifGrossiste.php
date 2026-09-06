<?php

namespace App\Models;

use App\Enums\ModeRemiseGrossiste;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

/**
 * Tarif de vente Grossiste — PROPRE À CHAQUE CLIENT (décision produit du 05/09/2026 : deux
 * Grossistes peuvent avoir des prix négociés différents pour la même catégorie/mode, jamais une
 * grille partagée par toute l'organisation), pour une catégorie du catalogue produit et un mode
 * de remise (Enlèvement/Livraison). Jamais une colonne sur `produit_variantes` (contrairement à
 * prix_externe/prix_revendeur/prix_distributeur) : le tarif dépend de la catégorie commerciale du
 * produit, du mode de la commande ET du client, pas de la variante seule. Résolu par
 * `GrossisteTarifResolver`, jamais lu directement par les contrôleurs. Cf. docs/grossiste.md.
 */
class CategorieTarifGrossiste extends Model
{
    use HasUlids;

    protected $table = 'categorie_tarifs_grossiste';

    protected $fillable = [
        'organization_id',
        'client_id',
        'categorie_id',
        'mode',
        'prix',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'mode' => ModeRemiseGrossiste::class,
            'prix' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $tarif) {
            if (Auth::check()) {
                $tarif->updated_by = Auth::id();
                if (! $tarif->exists) {
                    $tarif->created_by = Auth::id();
                }
            }
        });
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function categorie(): BelongsTo
    {
        return $this->belongsTo(Categorie::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Grille catégorie × mode pour UN client Grossiste — catégories racines de l'organisation
     * (lignes toujours affichées, même sans tarif configuré) + tarifs déjà enregistrés pour ce
     * client précis. Seule source de cette lecture, réutilisée par ClientController::show()
     * (embarquée server-side sur la fiche client) et CategorieTarifGrossisteController::forClient()
     * (fetch live depuis Ventes/Create.vue/Edit.vue au choix du client).
     *
     * @return array{categories: array<int, array{id: string, nom: string, produits_count: int}>, tarifs: array<int, array{categorie_id: string, mode: string, prix: int}>}
     */
    public static function gridForClient(string $organizationId, string $clientId): array
    {
        $categories = Categorie::where('organization_id', $organizationId)
            ->whereNull('parent_id')
            ->orderBy('position')
            ->orderBy('nom')
            ->withCount('produits')
            ->get()
            ->map(fn (Categorie $c) => [
                'id' => $c->id,
                'nom' => $c->nom,
                'produits_count' => $c->produits_count,
            ])
            ->all();

        $tarifs = self::where('client_id', $clientId)
            ->get()
            ->map(fn (self $t) => [
                'categorie_id' => $t->categorie_id,
                'mode' => $t->mode->value,
                'prix' => (int) $t->prix,
            ])
            ->all();

        return ['categories' => $categories, 'tarifs' => $tarifs];
    }
}
