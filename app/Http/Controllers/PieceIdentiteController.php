<?php

namespace App\Http\Controllers;

use App\Enums\StatutVerificationPieceIdentite;
use App\Http\Requests\RejeterPieceIdentiteRequest;
use App\Http\Requests\StorePieceIdentiteRequest;
use App\Http\Requests\UpdatePieceIdentiteRequest;
use App\Models\Employe;
use App\Models\PieceIdentite;
use App\Services\PieceIdentiteStorageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class PieceIdentiteController extends Controller
{
    public function store(StorePieceIdentiteRequest $request, Employe $employe): RedirectResponse
    {
        $data = $request->validated();
        $storage = app(PieceIdentiteStorageService::class);
        $storedPaths = [];

        try {
            DB::transaction(function () use ($request, $employe, $data, $storage, &$storedPaths) {
                $piece = new PieceIdentite([
                    'organization_id' => $employe->organization_id,
                    'type_piece' => $data['type_piece'],
                    'numero' => $data['numero'] ?? null,
                    'pays_delivrance' => $data['pays_delivrance'] ?? null,
                    'date_delivrance' => $data['date_delivrance'] ?? null,
                    'date_expiration' => $data['date_expiration'] ?? null,
                    'statut_verification' => StatutVerificationPieceIdentite::EN_ATTENTE->value,
                    'est_active' => true,
                    'created_by' => $request->user()->id,
                    'updated_by' => $request->user()->id,
                ]);
                $piece->identifiable()->associate($employe);
                $piece->save();

                $recto = $storage->store($request->file('recto'), $employe->organization_id, $employe->id, $piece->id, 'recto');
                $storedPaths[] = $recto['path'];

                $updates = [
                    'recto_path' => $recto['path'],
                    'recto_nom_original' => $recto['nom_original'],
                    'recto_mime_type' => $recto['mime_type'],
                    'recto_taille' => $recto['taille'],
                ];

                if ($request->hasFile('verso')) {
                    $verso = $storage->store($request->file('verso'), $employe->organization_id, $employe->id, $piece->id, 'verso');
                    $storedPaths[] = $verso['path'];

                    $updates += [
                        'verso_path' => $verso['path'],
                        'verso_nom_original' => $verso['nom_original'],
                        'verso_mime_type' => $verso['mime_type'],
                        'verso_taille' => $verso['taille'],
                    ];
                }

                $piece->update($updates);

                // Une seule pièce active par type : la nouvelle désactive les précédentes.
                $employe->piecesIdentite()
                    ->where('type_piece', $data['type_piece'])
                    ->where('id', '!=', $piece->id)
                    ->actives()
                    ->update(['est_active' => false]);
            });
        } catch (Throwable $e) {
            foreach ($storedPaths as $path) {
                $storage->delete($path);
            }
            throw $e;
        }

        return redirect()->route('employes.edit', $employe)
            ->with('success', "Pièce d'identité ajoutée avec succès.");
    }

    public function update(UpdatePieceIdentiteRequest $request, PieceIdentite $pieceIdentite): RedirectResponse
    {
        $employe = $pieceIdentite->identifiable;
        $data = $request->validated();
        $storage = app(PieceIdentiteStorageService::class);
        $newPaths = [];
        $oldPaths = [];

        try {
            DB::transaction(function () use ($request, $pieceIdentite, $employe, $data, $storage, &$newPaths, &$oldPaths) {
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
                    'updated_by' => $request->user()->id,
                ];

                if ($request->hasFile('recto')) {
                    $recto = $storage->store($request->file('recto'), $pieceIdentite->organization_id, $employe->id, $pieceIdentite->id, 'recto');
                    $newPaths[] = $recto['path'];
                    if ($pieceIdentite->recto_path) {
                        $oldPaths[] = $pieceIdentite->recto_path;
                    }
                    $updates += [
                        'recto_path' => $recto['path'],
                        'recto_nom_original' => $recto['nom_original'],
                        'recto_mime_type' => $recto['mime_type'],
                        'recto_taille' => $recto['taille'],
                    ];
                }

                if ($request->hasFile('verso')) {
                    $verso = $storage->store($request->file('verso'), $pieceIdentite->organization_id, $employe->id, $pieceIdentite->id, 'verso');
                    $newPaths[] = $verso['path'];
                    if ($pieceIdentite->verso_path) {
                        $oldPaths[] = $pieceIdentite->verso_path;
                    }
                    $updates += [
                        'verso_path' => $verso['path'],
                        'verso_nom_original' => $verso['nom_original'],
                        'verso_mime_type' => $verso['mime_type'],
                        'verso_taille' => $verso['taille'],
                    ];
                }

                $pieceIdentite->update($updates);

                $employe->piecesIdentite()
                    ->where('type_piece', $data['type_piece'])
                    ->where('id', '!=', $pieceIdentite->id)
                    ->actives()
                    ->update(['est_active' => false]);
            });
        } catch (Throwable $e) {
            foreach ($newPaths as $path) {
                $storage->delete($path);
            }
            throw $e;
        }

        foreach ($oldPaths as $path) {
            $storage->delete($path);
        }

        return redirect()->route('employes.edit', $employe)
            ->with('success', "Pièce d'identité mise à jour.");
    }

    public function showFile(Request $request, PieceIdentite $pieceIdentite, string $face)
    {
        $this->authorize('download', $pieceIdentite);

        abort_unless(in_array($face, ['recto', 'verso'], true), 404);

        $path = $pieceIdentite->{"{$face}_path"};
        abort_if(! $path, 404);

        $storage = app(PieceIdentiteStorageService::class);

        return $storage->response(
            $path,
            $pieceIdentite->{"{$face}_nom_original"} ?? "{$face}",
            $pieceIdentite->{"{$face}_mime_type"}
        );
    }

    public function valider(Request $request, PieceIdentite $pieceIdentite): RedirectResponse
    {
        $this->authorize('valider', $pieceIdentite);

        $pieceIdentite->update([
            'statut_verification' => StatutVerificationPieceIdentite::VALIDEE->value,
            'motif_rejet' => null,
            'verifiee_par' => $request->user()->id,
            'verifiee_le' => now(),
            'updated_by' => $request->user()->id,
        ]);

        return back()->with('success', "Pièce d'identité validée.");
    }

    public function rejeter(RejeterPieceIdentiteRequest $request, PieceIdentite $pieceIdentite): RedirectResponse
    {
        $pieceIdentite->update([
            'statut_verification' => StatutVerificationPieceIdentite::REJETEE->value,
            'motif_rejet' => $request->validated('motif_rejet'),
            'verifiee_par' => $request->user()->id,
            'verifiee_le' => now(),
            'updated_by' => $request->user()->id,
        ]);

        return back()->with('success', "Pièce d'identité rejetée.");
    }

    public function destroy(PieceIdentite $pieceIdentite): RedirectResponse
    {
        $this->authorize('delete', $pieceIdentite);

        $employe = $pieceIdentite->identifiable;
        $pieceIdentite->delete();

        return redirect()->route('employes.edit', $employe)
            ->with('success', "Pièce d'identité supprimée.");
    }
}
