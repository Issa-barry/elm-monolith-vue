<?php

namespace App\Services;

use App\Enums\StatutVerificationPieceIdentite;
use App\Models\PieceIdentite;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Logique métier des pièces d'identité, indépendante du contrôleur HTTP.
 * Générique sur le modèle "identifiable" (actuellement seul Proprietaire est
 * autorisé — voir PieceIdentite::ALLOWED_IDENTIFIABLE_TYPES) pour rester
 * réutilisable telle quelle quand une nouvelle entité sera ouverte.
 */
class PieceIdentiteService
{
    public function __construct(private readonly PieceIdentiteStorageService $storage) {}

    /**
     * @param  Model  $identifiable  Doit exposer organization_id et id.
     */
    public function creer(Model $identifiable, string $entiteSegment, array $data, UploadedFile $recto, ?UploadedFile $verso, string $auteurId): PieceIdentite
    {
        $storedPaths = [];

        try {
            return DB::transaction(function () use ($identifiable, $entiteSegment, $data, $recto, $verso, $auteurId, &$storedPaths) {
                $piece = new PieceIdentite([
                    'organization_id' => $identifiable->organization_id,
                    'type_piece' => $data['type_piece'],
                    'numero' => $data['numero'] ?? null,
                    'pays_delivrance' => $data['pays_delivrance'] ?? null,
                    'date_delivrance' => $data['date_delivrance'] ?? null,
                    'date_expiration' => $data['date_expiration'] ?? null,
                    'statut_verification' => StatutVerificationPieceIdentite::EN_ATTENTE->value,
                    'est_active' => true,
                    'created_by' => $auteurId,
                    'updated_by' => $auteurId,
                ]);
                $piece->identifiable()->associate($identifiable);
                $piece->save();

                $rectoMeta = $this->storage->store($recto, $identifiable->organization_id, $entiteSegment, $identifiable->id, $piece->id, 'recto');
                $storedPaths[] = $rectoMeta['path'];

                $updates = [
                    'recto_path' => $rectoMeta['path'],
                    'recto_nom_original' => $rectoMeta['nom_original'],
                    'recto_mime_type' => $rectoMeta['mime_type'],
                    'recto_taille' => $rectoMeta['taille'],
                ];

                if ($verso) {
                    $versoMeta = $this->storage->store($verso, $identifiable->organization_id, $entiteSegment, $identifiable->id, $piece->id, 'verso');
                    $storedPaths[] = $versoMeta['path'];

                    $updates += [
                        'verso_path' => $versoMeta['path'],
                        'verso_nom_original' => $versoMeta['nom_original'],
                        'verso_mime_type' => $versoMeta['mime_type'],
                        'verso_taille' => $versoMeta['taille'],
                    ];
                }

                $piece->update($updates);

                $this->desactiverAnciennesPiecesDuMemeType($identifiable, $data['type_piece'], $piece->id);

                return $piece;
            });
        } catch (Throwable $e) {
            foreach ($storedPaths as $path) {
                $this->storage->delete($path);
            }
            throw $e;
        }
    }

    public function mettreAJour(PieceIdentite $pieceIdentite, string $entiteSegment, array $data, ?UploadedFile $recto, ?UploadedFile $verso, string $auteurId): PieceIdentite
    {
        $identifiable = $pieceIdentite->identifiable;
        $newPaths = [];
        $oldPaths = [];

        try {
            DB::transaction(function () use ($pieceIdentite, $identifiable, $entiteSegment, $data, $recto, $verso, $auteurId, &$newPaths, &$oldPaths) {
                $updates = [
                    'type_piece' => $data['type_piece'],
                    'numero' => $data['numero'] ?? null,
                    'pays_delivrance' => $data['pays_delivrance'] ?? null,
                    'date_delivrance' => $data['date_delivrance'] ?? null,
                    'date_expiration' => $data['date_expiration'] ?? null,
                    // Toute modification remet la vérification à zéro.
                    'statut_verification' => StatutVerificationPieceIdentite::EN_ATTENTE->value,
                    'motif_rejet' => null,
                    'verifiee_par' => null,
                    'verifiee_le' => null,
                    'updated_by' => $auteurId,
                ];

                if ($recto) {
                    $rectoMeta = $this->storage->store($recto, $pieceIdentite->organization_id, $entiteSegment, $identifiable->id, $pieceIdentite->id, 'recto');
                    $newPaths[] = $rectoMeta['path'];
                    if ($pieceIdentite->recto_path) {
                        $oldPaths[] = $pieceIdentite->recto_path;
                    }
                    $updates += [
                        'recto_path' => $rectoMeta['path'],
                        'recto_nom_original' => $rectoMeta['nom_original'],
                        'recto_mime_type' => $rectoMeta['mime_type'],
                        'recto_taille' => $rectoMeta['taille'],
                    ];
                }

                if ($verso) {
                    $versoMeta = $this->storage->store($verso, $pieceIdentite->organization_id, $entiteSegment, $identifiable->id, $pieceIdentite->id, 'verso');
                    $newPaths[] = $versoMeta['path'];
                    if ($pieceIdentite->verso_path) {
                        $oldPaths[] = $pieceIdentite->verso_path;
                    }
                    $updates += [
                        'verso_path' => $versoMeta['path'],
                        'verso_nom_original' => $versoMeta['nom_original'],
                        'verso_mime_type' => $versoMeta['mime_type'],
                        'verso_taille' => $versoMeta['taille'],
                    ];
                }

                $pieceIdentite->update($updates);

                $this->desactiverAnciennesPiecesDuMemeType($identifiable, $data['type_piece'], $pieceIdentite->id);
            });
        } catch (Throwable $e) {
            foreach ($newPaths as $path) {
                $this->storage->delete($path);
            }
            throw $e;
        }

        foreach ($oldPaths as $path) {
            $this->storage->delete($path);
        }

        return $pieceIdentite->fresh();
    }

    public function valider(PieceIdentite $pieceIdentite, string $verificateurId): PieceIdentite
    {
        $pieceIdentite->update([
            'statut_verification' => StatutVerificationPieceIdentite::VALIDEE->value,
            'motif_rejet' => null,
            'verifiee_par' => $verificateurId,
            'verifiee_le' => now(),
            'updated_by' => $verificateurId,
        ]);

        return $pieceIdentite;
    }

    public function rejeter(PieceIdentite $pieceIdentite, string $motifRejet, string $verificateurId): PieceIdentite
    {
        $pieceIdentite->update([
            'statut_verification' => StatutVerificationPieceIdentite::REJETEE->value,
            'motif_rejet' => $motifRejet,
            'verifiee_par' => $verificateurId,
            'verifiee_le' => now(),
            'updated_by' => $verificateurId,
        ]);

        return $pieceIdentite;
    }

    public function supprimer(PieceIdentite $pieceIdentite): void
    {
        // Soft delete : les fichiers sont volontairement conservés pour audit
        // (voir PieceIdentite::booted() pour le nettoyage physique au forceDelete).
        $pieceIdentite->delete();
    }

    private function desactiverAnciennesPiecesDuMemeType(Model $identifiable, string $typePiece, string $exceptPieceId): void
    {
        $identifiable->piecesIdentite()
            ->where('type_piece', $typePiece)
            ->where('id', '!=', $exceptPieceId)
            ->actives()
            ->update(['est_active' => false]);
    }
}
