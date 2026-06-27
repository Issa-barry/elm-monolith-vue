<?php

namespace App\Http\Controllers\Api\Backoffice\Logistique;

use App\Enums\StatutTransfert;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Logistique\ValidationAdminRequest;
use App\Http\Resources\Api\Logistique\TransfertResource;
use App\Models\TransfertLogistique;
use App\Models\User;
use App\Services\CommissionLogistiqueService;
use App\Services\MouvementStockService;
use App\Services\TransfertActiviteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class ValidationAdminController extends Controller
{
    public function __invoke(ValidationAdminRequest $request, TransfertLogistique $transfert): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->organization_id && $transfert->organization_id !== $user->organization_id) {
            return response()->json(['message' => 'Accès non autorisé.'], 403);
        }

        if ($transfert->statut !== StatutTransfert::RECEPTION) {
            return response()->json(['message' => 'Seul un transfert réceptionné peut être validé.'], 422);
        }

        $decision = $request->validated()['decision'];
        $motif = $request->validated()['motif'] ?? null;

        match ($decision) {
            'accord' => $this->handleAccord($transfert, $user),
            'refus' => $this->handleRefus($transfert, $motif, $user),
            'invalider' => $this->handleInvalider($transfert, $user),
        };

        $transfert->load([
            'siteSource:id,nom',
            'siteDestination:id,nom',
            'vehicule:id,nom_vehicule,immatriculation',
            'equipeLivraison:id,vehicule_id', 'equipeLivraison.vehicule:id,nom_vehicule',
            'lignes.produit:id,nom,code_interne,image_url',
            'commission.parts',
            'activites',
        ]);

        return response()->json(new TransfertResource($transfert->fresh()));
    }

    private function handleAccord(TransfertLogistique $transfert, User $user): void
    {
        DB::transaction(function () use ($transfert, $user) {
            $transfert->update([
                'validation_reception' => 'accord',
                'validation_motif' => null,
                'validated_by' => $user->id,
                'validated_at' => now(),
            ]);

            CommissionLogistiqueService::genererAutomatique($transfert);
        });

        TransfertActiviteService::log($transfert, 'validation_accord', [], $user->id);
    }

    private function handleRefus(TransfertLogistique $transfert, ?string $motif, User $user): void
    {
        $transfert->update([
            'validation_reception' => 'refus',
            'validation_motif' => $motif,
            'validated_by' => $user->id,
            'validated_at' => now(),
        ]);

        TransfertActiviteService::log($transfert, 'validation_refus', ['motif' => $motif], $user->id);
    }

    private function handleInvalider(TransfertLogistique $transfert, User $user): void
    {
        DB::transaction(function () use ($transfert) {
            $transfert->update([
                'validation_reception' => null,
                'validation_motif' => null,
                'validated_by' => null,
                'validated_at' => null,
                'statut' => StatutTransfert::TRANSIT->value,
                'date_arrivee_reelle' => null,
            ]);

            MouvementStockService::supprimerEntreeDestination($transfert);
        });

        TransfertActiviteService::log($transfert, 'reception_invalidee', [], $user->id);
    }
}
