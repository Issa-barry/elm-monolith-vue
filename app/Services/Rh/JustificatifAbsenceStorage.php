<?php

namespace App\Services\Rh;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Justificatifs d'absence sur le disque privé `absences_rh` (même principe que les pièces
 * d'identité, PieceIdentiteStorageService) : nom de fichier aléatoire, arborescence
 * {organisation}/{salarié}/{absence}, jamais d'URL publique. Le fichier est conservé tel quel
 * (PDF ou image), jamais converti.
 */
class JustificatifAbsenceStorage
{
    public const DISK = 'absences_rh';

    /** Formats acceptés : PDF et images courantes (vérifiés sur le contenu, pas sur le nom). */
    public const MIMES = ['pdf', 'jpg', 'jpeg', 'png', 'webp'];

    /** Taille maximale, en kilo-octets (5 Mo, comme les pièces d'identité). */
    public const TAILLE_MAX_KO = 5120;

    /**
     * @return array{path: string, nom_original: string, mime_type: string, taille: int}
     */
    public function stocker(UploadedFile $fichier, string $organizationId, string $employeId, string $absenceId): array
    {
        $extension = strtolower($fichier->extension() ?: $fichier->getClientOriginalExtension() ?: 'bin');
        $nom = 'justificatif-'.Str::uuid().'.'.$extension;
        $chemin = $fichier->storeAs("{$organizationId}/{$employeId}/{$absenceId}", $nom, self::DISK);

        if ($chemin === false) {
            throw new RuntimeException('Échec du stockage du justificatif.');
        }

        return [
            'path' => $chemin,
            'nom_original' => Str::limit($fichier->getClientOriginalName(), 250, ''),
            // Type détecté sur le contenu du fichier, jamais celui annoncé par le navigateur.
            'mime_type' => $fichier->getMimeType() ?: 'application/octet-stream',
            'taille' => (int) $fichier->getSize(),
        ];
    }

    public function supprimer(?string $chemin): void
    {
        if ($chemin) {
            Storage::disk(self::DISK)->delete($chemin);
        }
    }

    /** Affichage dans le navigateur (PDF, image) après contrôle des droits par le contrôleur. */
    public function reponse(string $chemin, string $nom, ?string $mime): StreamedResponse
    {
        return Storage::disk(self::DISK)->response($chemin, $nom, array_filter([
            'Content-Type' => $mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]));
    }
}
