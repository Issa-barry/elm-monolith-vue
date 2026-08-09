<?php

namespace App\Services;

use App\Models\Parametre;
use App\Models\Produit;
use App\Models\ProduitMedia;
use App\Models\ProduitVariante;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MediaService
{
    public function __construct(private ImageService $imageService) {}

    /**
     * Upload d'un lot de photos pour un produit. Verrouille la ligne Produit pendant la
     * vérification+écriture pour qu'un double-upload concurrent ne contourne pas la limite
     * configurée par organisation (Parametre::getMaxPhotosProduit).
     *
     * @param  UploadedFile[]  $fichiers
     * @return Collection<int, ProduitMedia>
     */
    public function ajouter(Produit $produit, array $fichiers): Collection
    {
        if (empty($fichiers)) {
            return collect();
        }

        return DB::transaction(function () use ($produit, $fichiers) {
            $produitVerrouille = Produit::whereKey($produit->id)->lockForUpdate()->firstOrFail();

            $max = Parametre::getMaxPhotosProduit($produitVerrouille->organization_id);
            $existants = $produitVerrouille->medias()->count();

            if ($existants + count($fichiers) > $max) {
                throw ValidationException::withMessages([
                    'images' => "Cette organisation autorise au maximum {$max} photos par produit ({$existants} déjà utilisées, ".count($fichiers).' envoyée(s)).',
                ]);
            }

            $position = (int) ($produitVerrouille->medias()->max('position') ?? -1);
            $premiere = $existants === 0;
            $medias = collect();

            foreach ($fichiers as $fichier) {
                $position++;
                $path = $this->imageService->storeAsWebp($fichier, "produits/{$produitVerrouille->id}");
                $thumbPath = $this->imageService->storeThumb($fichier, "produits/{$produitVerrouille->id}/thumbs");

                $medias->push($produitVerrouille->medias()->create([
                    'organization_id' => $produitVerrouille->organization_id,
                    'path' => $path,
                    'thumb_path' => $thumbPath,
                    'original_name' => $fichier->getClientOriginalName(),
                    'mime_type' => $fichier->getClientMimeType(),
                    'size' => $fichier->getSize(),
                    'is_primary' => $premiere,
                    'position' => $position,
                ]));

                $premiere = false;
            }

            return $medias;
        });
    }

    /**
     * Associe un média (déjà présent dans la galerie du produit) à un lot de variantes du MÊME
     * produit — plusieurs variantes peuvent ainsi partager la même photo sans duplication
     * (ex: "appliquer à toutes les variantes Blanc"). Ignore silencieusement les ids de
     * variantes n'appartenant pas au produit du média (défensif, pas une erreur utilisateur).
     *
     * @param  string[]  $varianteIds
     */
    public function assignerAuxVariantes(ProduitMedia $media, array $varianteIds): int
    {
        return ProduitVariante::where('produit_id', $media->produit_id)
            ->whereIn('id', $varianteIds)
            ->update(['media_id' => $media->id]);
    }

    public function definirPrincipale(Produit $produit, string $mediaId): void
    {
        DB::transaction(function () use ($produit, $mediaId) {
            $produit->medias()->update(['is_primary' => false]);
            $produit->medias()->whereKey($mediaId)->update(['is_primary' => true]);
        });
    }

    /**
     * @param  string[]  $ordreIds  IDs des médias dans le nouvel ordre souhaité
     */
    public function reordonner(Produit $produit, array $ordreIds): void
    {
        DB::transaction(function () use ($produit, $ordreIds) {
            foreach (array_values($ordreIds) as $position => $mediaId) {
                $produit->medias()->whereKey($mediaId)->update(['position' => $position]);
            }
        });
    }

    public function supprimer(ProduitMedia $media): void
    {
        DB::transaction(function () use ($media) {
            $etaitPrincipale = $media->is_primary;
            $produit = $media->produit;

            $this->imageService->delete($media->path);
            $this->imageService->delete($media->thumb_path);
            $media->delete();

            if ($etaitPrincipale) {
                $suivante = $produit->medias()->orderBy('position')->first();
                $suivante?->update(['is_primary' => true]);
            }
        });
    }
}
