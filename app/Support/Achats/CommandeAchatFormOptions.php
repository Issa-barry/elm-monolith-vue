<?php

namespace App\Support\Achats;

use App\Enums\ProduitStatut;
use App\Models\Fournisseur;
use App\Models\Produit;
use App\Models\ProduitVariante;
use App\Models\User;
use App\Services\Achats\AchatReferentielValidator;
use App\Services\Achats\PerimetreCommandesAchat;

/**
 * Options des formulaires de création et de modification d'un bon de commande fournisseur :
 * agences du périmètre « Peut acheter pour », fournisseurs actifs, et une option par VARIANTE active
 * d'un produit actif achetable (un produit à plusieurs déclinaisons ne pouvait pas être commandé
 * avant ADR 0021, faute de sélecteur de variante).
 */
class CommandeAchatFormOptions
{
    public function __construct(
        private readonly AchatReferentielValidator $referentiel,
        private readonly PerimetreCommandesAchat $perimetre,
    ) {}

    public function pour(User $user): array
    {
        $orgId = $user->organization_id;

        $variantes = Produit::where('organization_id', $orgId)
            ->where('statut', ProduitStatut::ACTIF)
            ->whereHas('produitType', fn ($q) => $q->where('achetable', true))
            ->with(['variantes' => fn ($q) => $q->where('is_active', true)->orderBy('position')])
            ->orderBy('nom')
            ->get()
            ->flatMap(fn (Produit $p) => $p->variantes->map(function (ProduitVariante $v) use ($p) {
                $v->setRelation('produit', $p);

                return [
                    'id' => $v->id,
                    'label' => $this->referentiel->libelle($v),
                    'prix_achat' => (int) ($v->prix_achat ?? 0),
                ];
            }))
            ->values();

        // nom n'est pas une colonne de fournisseurs (Personne/EntrepriseTierce) : tri en PHP, puis
        // values() pour sérialiser un tableau JSON et non un objet.
        $fournisseurs = Fournisseur::where('organization_id', $orgId)
            ->where('is_active', true)
            ->with(['personne', 'entrepriseTierce'])
            ->get()
            ->sortBy('nom_complet')
            ->values()
            ->map(fn (Fournisseur $f) => ['id' => $f->id, 'nom' => $f->nom_complet]);

        // Agences du périmètre « Peut acheter pour » des rôles de l'utilisateur, seules acceptées
        // par le serveur (AchatReferentielValidator).
        $sites = $this->perimetre->sites($user);

        $siteParDefaut = $user->sites()->wherePivot('is_default', true)->value('sites.id');

        return [
            'variantes' => $variantes,
            'fournisseurs' => $fournisseurs,
            'sites' => $sites->map(fn ($s) => ['id' => $s->id, 'nom' => $s->nom])->values(),
            'site_par_defaut' => $siteParDefaut,
        ];
    }
}
