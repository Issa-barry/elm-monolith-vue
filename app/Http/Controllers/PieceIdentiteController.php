<?php

namespace App\Http\Controllers;

use App\Http\Requests\RejeterPieceIdentiteRequest;
use App\Http\Requests\StorePieceIdentiteRequest;
use App\Http\Requests\UpdatePieceIdentiteRequest;
use App\Models\PieceIdentite;
use App\Models\Proprietaire;
use App\Services\PieceIdentiteService;
use App\Services\PieceIdentiteStorageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class PieceIdentiteController extends Controller
{
    // Segment de dossier de stockage pour cette entité — voir arborescence dans
    // PieceIdentiteStorageService. À dupliquer/paramétrer quand une autre entité
    // (Client, Employe...) sera ouverte à la fonctionnalité.
    private const ENTITE_SEGMENT = 'proprietaires';

    public function __construct(private readonly PieceIdentiteService $service) {}

    public function store(StorePieceIdentiteRequest $request, Proprietaire $proprietaire): RedirectResponse
    {
        $data = $request->validated();

        $this->service->creer(
            $proprietaire,
            self::ENTITE_SEGMENT,
            $data,
            $request->file('recto'),
            $request->file('verso'),
            $request->user()->id,
        );

        return redirect()->route('proprietaires.show', $proprietaire)
            ->with('success', "Pièce d'identité ajoutée avec succès.");
    }

    public function update(UpdatePieceIdentiteRequest $request, PieceIdentite $pieceIdentite): RedirectResponse
    {
        $proprietaire = $pieceIdentite->identifiable;
        $data = $request->validated();

        $this->service->mettreAJour(
            $pieceIdentite,
            self::ENTITE_SEGMENT,
            $data,
            $request->file('recto'),
            $request->file('verso'),
            $request->user()->id,
        );

        return redirect()->route('proprietaires.show', $proprietaire)
            ->with('success', "Pièce d'identité mise à jour.");
    }

    public function showFile(Request $request, PieceIdentite $pieceIdentite, string $face, PieceIdentiteStorageService $storage)
    {
        $this->authorize('download', $pieceIdentite);

        abort_unless(in_array($face, ['recto', 'verso'], true), 404);

        $path = $pieceIdentite->{"{$face}_path"};
        abort_if(! $path, 404);

        return $storage->response(
            $path,
            $pieceIdentite->{"{$face}_nom_original"} ?? $face,
            $pieceIdentite->{"{$face}_mime_type"}
        );
    }

    public function valider(Request $request, PieceIdentite $pieceIdentite): RedirectResponse
    {
        $this->authorize('valider', $pieceIdentite);

        $this->service->valider($pieceIdentite, $request->user()->id);

        return back()->with('success', "Pièce d'identité validée.");
    }

    public function rejeter(RejeterPieceIdentiteRequest $request, PieceIdentite $pieceIdentite): RedirectResponse
    {
        $this->service->rejeter($pieceIdentite, $request->validated('motif_rejet'), $request->user()->id);

        return back()->with('success', "Pièce d'identité rejetée.");
    }

    public function destroy(PieceIdentite $pieceIdentite): RedirectResponse
    {
        $this->authorize('delete', $pieceIdentite);

        $proprietaire = $pieceIdentite->identifiable;
        $this->service->supprimer($pieceIdentite);

        return redirect()->route('proprietaires.show', $proprietaire)
            ->with('success', "Pièce d'identité supprimée.");
    }
}
