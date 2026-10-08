<?php

namespace App\Services\Achats;

use App\Enums\ProduitStatut;
use App\Models\Fournisseur;
use App\Models\ProduitVariante;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Contrôle d'appartenance des référentiels d'un bon de commande fournisseur (ADR 0021) : agence,
 * fournisseur et variantes doivent appartenir à l'organisation de l'utilisateur — `exists:` seul ne
 * le garantissait pas (une requête forgée pouvait rattacher une variante d'une autre organisation,
 * puis créer du stock dessus à la réception). L'agence doit en plus être couverte par le périmètre
 * « Peut acheter pour » d'un des rôles de l'utilisateur (PerimetreCommandesAchat).
 */
class AchatReferentielValidator
{
    public function __construct(private readonly PerimetreCommandesAchat $perimetre) {}

    /**
     * @param  array{site_id: string, fournisseur_id: string, lignes: list<array{variante_id: string}>}  $data
     * @return array{site: Site, fournisseur: Fournisseur, variantes: Collection<string, ProduitVariante>}
     *
     * @throws ValidationException
     */
    public function verifier(User $user, array $data): array
    {
        $orgId = $user->organization_id;
        $erreurs = [];

        $site = Site::where('organization_id', $orgId)->find($data['site_id']);
        if ($site === null) {
            $erreurs['site_id'] = 'Agence introuvable.';
        } elseif (! $this->perimetre->couvreSite($user, $site->id)) {
            $erreurs['site_id'] = "Votre rôle ne permet pas d'acheter pour cette agence.";
        }

        $fournisseur = Fournisseur::where('organization_id', $orgId)->find($data['fournisseur_id']);
        if ($fournisseur === null) {
            $erreurs['fournisseur_id'] = 'Fournisseur introuvable.';
        } elseif (! $fournisseur->is_active) {
            $erreurs['fournisseur_id'] = "Ce fournisseur n'est plus actif.";
        }

        $variantes = ProduitVariante::where('organization_id', $orgId)
            ->whereIn('id', collect($data['lignes'])->pluck('variante_id')->all())
            ->with('produit.produitType')
            ->get()
            ->keyBy('id');

        foreach ($data['lignes'] as $i => $ligne) {
            $variante = $variantes->get($ligne['variante_id']);
            $produit = $variante?->produit;

            if ($variante === null || $produit === null || $produit->organization_id !== $orgId) {
                $erreurs["lignes.{$i}.variante_id"] = 'Produit introuvable.';
            } elseif (! $variante->is_active || $produit->statut !== ProduitStatut::ACTIF) {
                $erreurs["lignes.{$i}.variante_id"] = "« {$this->libelle($variante)} » n'est plus actif.";
            } elseif (! $produit->produitType?->isAchetable()) {
                $erreurs["lignes.{$i}.variante_id"] = "« {$this->libelle($variante)} » n'est pas un produit achetable.";
            }
        }

        if ($erreurs !== []) {
            throw ValidationException::withMessages($erreurs);
        }

        return ['site' => $site, 'fournisseur' => $fournisseur, 'variantes' => $variantes];
    }

    public function libelle(ProduitVariante $variante): string
    {
        $nom = (string) $variante->produit?->nom;

        return $variante->libelle !== '' ? "{$nom} — {$variante->libelle}" : $nom;
    }
}
