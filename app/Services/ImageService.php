<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;

class ImageService
{
    private ImageManager $manager;

    // Paramètres par défaut
    private int $maxWidth = 1200;

    private int $maxHeight = 1200;

    private int $quality = 80;   // qualité WebP (0-100)

    public function __construct()
    {
        $this->manager = new ImageManager(new Driver);
    }

    /**
     * Optimise, redimensionne et convertit en WebP, puis stocke dans public disk.
     * Retourne le chemin relatif (ex: "produits/abc123.webp").
     */
    public function storeAsWebp(UploadedFile $file, string $folder): string
    {
        return $this->storeResized($file, $folder, $this->maxWidth, $this->maxHeight, $this->quality);
    }

    /**
     * Variante miniature (~200px par défaut) pour les listes/grilles denses (PDV, DataTable) —
     * évite de servir l'image 1200px partout. Même conversion WebP que storeAsWebp().
     */
    public function storeThumb(UploadedFile $file, string $folder, int $size = 200): string
    {
        return $this->storeResized($file, $folder, $size, $size, $this->quality);
    }

    /**
     * Supprime un fichier du disk public.
     */
    public function delete(?string $path): void
    {
        if ($path) {
            Storage::disk('public')->delete($path);
        }
    }

    private function storeResized(UploadedFile $file, string $folder, int $maxWidth, int $maxHeight, int $quality): string
    {
        $image = $this->manager->read($file->getRealPath());

        // Réduire si trop grande (garde le ratio)
        if ($image->width() > $maxWidth || $image->height() > $maxHeight) {
            $image->scaleDown($maxWidth, $maxHeight);
        }

        $filename = Str::uuid().'.webp';
        $path = $folder.'/'.$filename;

        Storage::disk('public')->put(
            $path,
            $image->toWebp($quality)->toString()
        );

        return $path;
    }
}
