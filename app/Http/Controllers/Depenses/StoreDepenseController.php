<?php

namespace App\Http\Controllers\Depenses;

use App\Enums\AuditEvent;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDepenseRequest;
use App\Models\Depense;
use App\Models\DepenseType;
use App\Services\AuditLogService;
use App\Services\DroitCreationDepenseService;
use Illuminate\Http\RedirectResponse;

class StoreDepenseController extends Controller
{
    public function __construct(
        private readonly AuditLogService $audit,
        private readonly DroitCreationDepenseService $droitCreationDepense,
    ) {}

    public function __invoke(StoreDepenseRequest $request): RedirectResponse
    {
        $this->authorize('create', Depense::class);

        $user = auth()->user();
        $orgId = $user->organization_id;

        abort_unless(
            $this->droitCreationDepense->peutCreerSurSite($user, $orgId, (string) $request->site_id),
            403,
            'Vous n\'êtes pas autorisé à créer une dépense sur ce site.'
        );

        if (! $user->isAdmin()) {
            $allowedSiteIds = $user->sites()->pluck('sites.id')->all();
            abort_unless(
                in_array($request->site_id, $allowedSiteIds, true),
                403,
                'Vous ne pouvez pas choisir ce site.'
            );
        }

        $type = DepenseType::where('organization_id', $orgId)->findOrFail($request->depense_type_id);

        $depense = Depense::create([
            'organization_id' => $orgId,
            'user_id' => auth()->id(),
            'depense_type_id' => $request->depense_type_id,
            'beneficiaire_type' => $type->categorie->needsBeneficiaire() ? $type->categorie->value : null,
            'beneficiaire_id' => $type->categorie->needsBeneficiaire() ? $request->beneficiaire_id : null,
            'site_id' => $request->site_id,
            'montant' => $request->montant,
            'date_depense' => $request->date_depense,
            'commentaire' => $request->commentaire,
            'statut' => $request->statut,
        ]);

        $this->audit->record($depense, AuditEvent::CREATED, auth()->user(), null, null, [
            'module' => 'depenses',
            'site_id' => $depense->site_id,
            'description' => "Dépense créée — {$type->libelle}",
        ]);

        return redirect()->route('depenses.index')->with('success', 'Dépense enregistrée.');
    }
}
