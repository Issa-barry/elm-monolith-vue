<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver;

class ImageService
{
    private ImageManager $manager;

    // Paramètres par défaut
    private int $maxWidth   = 1200;
    private int $maxHeight  = 1200;
    private int $quality    = 80;   // qualité WebP (0-100)

    public function __construct()
    {
        $this->manager = new ImageManager(new Driver());
    }

    /**
     * Optimise, redimensionne et convertit en WebP, puis stocke dans public disk.
     * Retourne le chemin relatif (ex: "produits/abc123.webp").
     */
    public function storeAsWebp(UploadedFile $file, string $folder): string
    {
        $image = $this->manager->read($file->getRealPath());

        // Réduire si trop grande (garde le ratio)
        if ($image->width() > $this->maxWidth || $image->height() > $this->maxHeight) {
            $image->scaleDown($this->maxWidth, $this->maxHeight);
        }

        $filename = Str::uuid() . '.webp';
        $path     = $folder . '/' . $filename;

        Storage::disk('public')->put(
            $path,
            $image->toWebp($this->quality)->toString()
        );

        return $path;
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
}
