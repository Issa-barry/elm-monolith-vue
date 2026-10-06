<?php

namespace App\Http\Controllers\Ventes\Precommandes;

use App\Enums\ModePaiement;
use App\Http\Controllers\Controller;
use App\Models\CommandeVente;
use App\Models\RemboursementVente;
use App\Services\Ventes\PrecommandeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Remboursement du trop-perçu d'une précommande (ADR 0019) — décaissement réel, ADR 0009. */
class RemboursementPrecommandeController extends Controller
{
    public function __construct(private readonly PrecommandeService $precommandes) {}

    public function __invoke(Request $request, CommandeVente $commande_vente): RedirectResponse
    {
        $this->authorize('rembourser', $commande_vente);

        $data = $request->validate(self::regles(), self::messages());

        try {
            $this->precommandes->rembourser($commande_vente, $request->user(), $data, RemboursementVente::MOTIF_TROP_PERCU);
        } catch (\RuntimeException $e) {
            return back()->withErrors(['comptabilisation' => "Remboursement non enregistré : {$e->getMessage()}"]);
        }

        return back()->with('success', 'Remboursement enregistré.');
    }

    /** @return array<string, mixed> */
    public static function regles(): array
    {
        return [
            'montant' => ['required', 'numeric', 'min:0.01'],
            'mode_paiement' => ['required', Rule::in(array_column(ModePaiement::cases(), 'value'))],
            'compte_tresorerie_id' => ['nullable', 'string', 'required_unless:mode_paiement,'.ModePaiement::ESPECES->value],
            'reference_paiement' => ['nullable', 'string', 'max:190'],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return [
            'compte_tresorerie_id.required_unless' => "Choisissez le compte d'où sort ce remboursement.",
        ];
    }
}
