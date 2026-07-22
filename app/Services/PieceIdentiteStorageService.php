<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class PieceIdentiteStorageService
{
    private const DISK = 'pieces_identite';

    /**
     * Stocke un fichier (recto ou verso) sur le disque privé et retourne ses métadonnées.
     * Ne convertit jamais le fichier (PDF/JPG/PNG conservés tels quels — ce sont des
     * documents officiels, contrairement aux photos de véhicules gérées par ImageService).
     *
     * $entiteSegment est le segment de dossier propre à l'entité rattachée (ex: "proprietaires",
     * plus tard "employes"...) — garde l'arborescence lisible même si plusieurs types
     * d'entités partagent la même table pieces_identite.
     *
     * @return array{path: string, nom_original: string, mime_type: string, taille: int}
     */
    public function store(UploadedFile $file, string $organizationId, string $entiteSegment, string $identifiableId, string $pieceId, string $face): array
    {
        $extension = $file->getClientOriginalExtension() ?: $file->extension() ?: 'bin';
        $filename = $face.'-'.Str::uuid().'.'.$extension;
        $directory = "{$organizationId}/{$entiteSegment}/{$identifiableId}/{$pieceId}";

        $path = $file->storeAs($directory, $filename, self::DISK);

        if ($path === false) {
            throw new RuntimeException("Échec du stockage du fichier {$face}.");
        }

        return [
            'path' => $path,
            'nom_original' => $file->getClientOriginalName(),
            'mime_type' => $file->getClientMimeType(),
            'taille' => $file->getSize(),
        ];
    }

    public function delete(?string $path): void
    {
        if ($path) {
            Storage::disk(self::DISK)->delete($path);
        }
    }

    /**
     * Supprime le dossier {piece_id} s'il ne contient plus aucun fichier
     * (appelé après forceDelete, une fois recto/verso supprimés).
     */
    public function deleteDirectoryIfEmpty(?string $directory): void
    {
        if (! $directory) {
            return;
        }

        $disk = Storage::disk(self::DISK);

        if ($disk->exists($directory) && count($disk->allFiles($directory)) === 0) {
            $disk->deleteDirectory($directory);
        }
    }

    /**
     * Réponse HTTP streamée pour affichage/téléchargement contrôlé (jamais d'URL publique).
     */
    public function response(string $path, string $filename, ?string $mimeType)
    {
        return Storage::disk(self::DISK)->response($path, $filename, array_filter([
            'Content-Type' => $mimeType,
        ]));
    }
}
