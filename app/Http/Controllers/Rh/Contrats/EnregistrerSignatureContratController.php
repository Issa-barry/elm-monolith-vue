<?php

namespace App\Http\Controllers\Rh\Contrats;

use App\Http\Controllers\Controller;
use App\Models\Contrat;
use App\Models\DocumentContrat;
use App\Services\Rh\DocumentContratService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/** « Marquer comme signé » (ADR 0044) : la version en cours est figée à la date de signature saisie. */
class EnregistrerSignatureContratController extends Controller
{
    use VerifieAccesContrat;

    public function __invoke(Request $request, Contrat $contrat, DocumentContrat $document, DocumentContratService $documents): RedirectResponse
    {
        $this->verifierAccesContrat($request->user(), $contrat, ecriture: true);
        abort_unless($document->contrat_id === $contrat->id, 404);

        $data = $request->validate(
            ['signe_le' => ['required', 'date_format:Y-m-d']],
            ['signe_le.required' => 'La date de signature est obligatoire.', 'signe_le.date_format' => 'Date de signature invalide.'],
        );

        $documents->enregistrerSignature($document, Carbon::createFromFormat('Y-m-d', $data['signe_le'])->startOfDay(), $request->user());

        return back()->with('success', 'Version '.$document->version.' marquée comme signée : elle est désormais figée.');
    }
}
