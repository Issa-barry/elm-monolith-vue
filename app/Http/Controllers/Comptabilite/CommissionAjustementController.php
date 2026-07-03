<?php

namespace App\Http\Controllers\Comptabilite;

use App\Enums\AuditEvent;
use App\Enums\MotifAjustementCommission;
use App\Http\Controllers\Controller;
use App\Models\CommissionLogistique;
use App\Models\CommissionLogistiquePart;
use App\Models\CommissionPart;
use App\Models\CommissionVente;
use App\Models\Livreur;
use App\Models\PaiementPeriode;
use App\Models\Proprietaire;
use App\Services\AuditLogService;
use App\Services\CommissionAdjustmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CommissionAjustementController extends Controller
{
    /**
     * Équipe globale d'un véhicule sur toute la période (1 ligne par bénéficiaire, montants
     * cumulés) + actions d'ajustement. Le métier raisonne "je traite le véhicule X pour la
     * quinzaine", pas commande par commande : les commandes/transferts ne sont exposés que
     * comme information de contexte, pas comme unité de travail (accédé depuis la liste
     * "Commissions par véhicule" du détail période).
     */
    public function vehicule(PaiementPeriode $periode, string $vehicule, Request $request): Response
    {
        $this->authorize('ajuster', $periode);

        $vehiculeId = $vehicule === 'sans-vehicule' ? null : $vehicule;
        $groupesRaw = CommissionAdjustmentService::groupesParVehicule($periode, $vehiculeId);

        abort_if(empty($groupesRaw), 404);

        $filters = $request->only(['beneficiaire', 'validation']);

        $beneficiaires = collect(CommissionAdjustmentService::beneficiairesParVehicule($periode, $vehiculeId))
            ->filter(function (array $b) use ($filters) {
                if (! empty($filters['beneficiaire'])) {
                    $needle = mb_strtolower(trim($filters['beneficiaire']));
                    if (! str_contains(mb_strtolower($b['beneficiaire_nom']), $needle)) {
                        return false;
                    }
                }

                if (! empty($filters['validation'])) {
                    $veutValidee = $filters['validation'] === 'validee';
                    if ($b['est_validee'] !== $veutValidee) {
                        return false;
                    }
                }

                return true;
            })
            ->map(fn (array $b) => [
                'cle' => $b['cle'],
                'type_beneficiaire' => $b['type_beneficiaire'],
                'beneficiaire_nom' => $b['beneficiaire_nom'],
                'theorique' => $b['theorique'],
                'ajuste' => $b['ajuste'],
                'ecart' => $b['ecart'],
                'est_validee' => $b['est_validee'],
                'peut_etre_ajustee' => $b['peut_etre_ajustee'],
                // La référence de commande n'est jamais exposée à l'écran d'ajustement : le
                // métier raisonne "bénéficiaire x véhicule x période", jamais commande par
                // commande. Le détail par commande reste en base pour l'audit uniquement.
                'parts' => collect($b['parts'])
                    ->map(fn (array $l) => Arr::except($this->transform($l['part'], $l['type']), ['reference']))
                    ->values(),
            ])
            ->values();

        $premier = $groupesRaw[0];
        $orgId = $periode->organization_id;

        return Inertia::render('Comptabilite/Ajustements/Vehicule', [
            'periode' => $this->transformPeriode($periode),
            'vehicule' => [
                'id' => $vehiculeId,
                'route_segment' => $vehicule,
                'nom' => $premier['vehicule_nom'] ?? 'Sans véhicule',
                'immat' => $premier['vehicule_immat'],
                'theorique' => round((float) collect($groupesRaw)->sum('theorique'), 2),
                'ajuste' => round((float) collect($groupesRaw)->sum('ajuste'), 2),
                'ecart' => round((float) collect($groupesRaw)->sum('ecart'), 2),
            ],
            'beneficiaires' => $beneficiaires,
            'filters' => $filters,
            'motifs' => MotifAjustementCommission::options(),
            'commissions_vente' => collect($groupesRaw)->where('type', 'vente')
                ->map(fn (array $g) => ['id' => $g['commission_id'], 'label' => 'Commande '.$g['reference']])
                ->values(),
            'commissions_logistique' => collect($groupesRaw)->where('type', 'logistique')
                ->map(fn (array $g) => ['id' => $g['commission_id'], 'label' => 'Transfert '.$g['reference']])
                ->values(),
            'livreurs' => Livreur::where('organization_id', $orgId)->orderBy('nom')->get(['id', 'nom', 'prenom'])
                ->map(fn (Livreur $l) => ['id' => $l->id, 'nom' => $l->nom_complet]),
            'proprietaires' => Proprietaire::where('organization_id', $orgId)->orderBy('nom')->get(['id', 'nom', 'prenom'])
                ->map(fn (Proprietaire $p) => ['id' => $p->id, 'nom' => $p->nom_complet]),
        ]);
    }

    private function transformPeriode(PaiementPeriode $periode): array
    {
        return [
            'id' => $periode->id,
            'reference' => $periode->reference,
            'type' => $periode->type?->value,
            'statut' => $periode->statut?->value,
            'date_debut' => $periode->date_debut?->toDateString(),
            'date_fin' => $periode->date_fin?->toDateString(),
        ];
    }

    public function ajuster(Request $request, string $type, string $partId): RedirectResponse
    {
        $part = $this->resolvePart($type, $partId);
        $this->authorizeSurPart($part);

        $data = $request->validate([
            'montant' => ['required', 'numeric', 'min:0'],
            'motif' => ['required', Rule::in(array_column(MotifAjustementCommission::cases(), 'value'))],
            'commentaire' => ['nullable', 'string', 'max:500'],
        ]);

        CommissionAdjustmentService::ajusterMontant(
            $part,
            (float) $data['montant'],
            MotifAjustementCommission::from($data['motif']),
            $data['commentaire'] ?? null,
            $request->user(),
        );

        return back()->with('success', 'Montant ajusté.');
    }

    public function absence(Request $request, string $type, string $partId): RedirectResponse
    {
        $part = $this->resolvePart($type, $partId);
        $this->authorizeSurPart($part);

        $data = $request->validate([
            'commentaire' => ['nullable', 'string', 'max:500'],
        ]);

        CommissionAdjustmentService::declarerAbsence($part, $data['commentaire'] ?? null, $request->user());

        return back()->with('success', 'Absence déclarée, montant mis à 0.');
    }

    /**
     * Ajuste le montant total d'un bénéficiaire sur toutes ses parts du véhicule/période en
     * une seule saisie (vue agrégée) : le responsable métier ne voit et ne fournit qu'un
     * montant global, jamais le détail par commande.
     */
    public function ajusterGroupe(Request $request, PaiementPeriode $periode): RedirectResponse
    {
        $this->authorize('ajuster', $periode);

        $data = $request->validate([
            'parts' => ['required', 'array', 'min:1'],
            'parts.*.type' => ['required', Rule::in(['vente', 'logistique'])],
            'parts.*.id' => ['required', 'string'],
            'montant' => ['required', 'numeric', 'min:0'],
            'motif' => ['required', Rule::in(array_column(MotifAjustementCommission::cases(), 'value'))],
            'commentaire' => ['nullable', 'string', 'max:500'],
        ]);

        $parts = collect($data['parts'])->map(function (array $p) {
            $part = $this->resolvePart($p['type'], $p['id']);
            $this->authorizeSurPart($part);

            return $part;
        });

        try {
            CommissionAdjustmentService::ajusterMontantGroupe(
                $parts,
                (float) $data['montant'],
                MotifAjustementCommission::from($data['motif']),
                $data['commentaire'] ?? null,
                $request->user(),
            );
        } catch (\LogicException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Montant ajusté.');
    }

    /** Déclare un bénéficiaire absent sur toutes ses parts du véhicule/période (vue agrégée) : chaque montant est mis à 0. */
    public function absenceGroupe(Request $request, PaiementPeriode $periode): RedirectResponse
    {
        $this->authorize('ajuster', $periode);

        $data = $request->validate([
            'parts' => ['required', 'array', 'min:1'],
            'parts.*.type' => ['required', Rule::in(['vente', 'logistique'])],
            'parts.*.id' => ['required', 'string'],
            'commentaire' => ['nullable', 'string', 'max:500'],
        ]);

        foreach ($data['parts'] as $p) {
            $part = $this->resolvePart($p['type'], $p['id']);
            $this->authorizeSurPart($part);

            if (! $part->peutEtreAjustee()) {
                continue;
            }

            CommissionAdjustmentService::declarerAbsence($part, $data['commentaire'] ?? null, $request->user());
        }

        return back()->with('success', 'Absence déclarée pour toutes les commandes concernées.');
    }

    public function remplacant(Request $request, PaiementPeriode $periode): RedirectResponse
    {
        $this->authorize('ajuster', $periode);

        $data = $request->validate([
            'commission_type' => ['required', Rule::in(['vente', 'logistique'])],
            'commission_id' => ['required', 'string'],
            'type_beneficiaire' => ['required', Rule::in(['livreur', 'proprietaire'])],
            'livreur_id' => ['required_if:type_beneficiaire,livreur', 'nullable', 'exists:livreurs,id'],
            'proprietaire_id' => ['required_if:type_beneficiaire,proprietaire', 'nullable', 'exists:proprietaires,id'],
            'beneficiaire_nom' => ['required', 'string', 'max:150'],
            'montant' => ['required', 'numeric', 'min:0.01'],
            'commentaire' => ['nullable', 'string', 'max:500'],
        ]);

        if ($data['commission_type'] === 'vente') {
            $commission = CommissionVente::where('organization_id', $periode->organization_id)->findOrFail($data['commission_id']);
            $part = CommissionAdjustmentService::ajouterRemplacantVente($commission, $data, $request->user());
        } else {
            $commission = CommissionLogistique::where('organization_id', $periode->organization_id)->findOrFail($data['commission_id']);
            $part = CommissionAdjustmentService::ajouterRemplacantLogistique($commission, $data, $request->user());
        }

        app(AuditLogService::class)->record($periode, AuditEvent::CREATED, $request->user(), null, null, [
            'module' => 'ajustements_commissions',
            'description' => "Remplaçant {$part->beneficiaire_nom} ajouté pour la période {$periode->reference}",
        ]);

        return back()->with('success', 'Remplaçant ajouté.');
    }

    public function valider(Request $request, string $type, string $partId): RedirectResponse
    {
        $part = $this->resolvePart($type, $partId);
        $this->authorizeSurPart($part);

        CommissionAdjustmentService::validerPart($part, $request->user());

        return back()->with('success', 'Commission validée.');
    }

    public function validerLot(Request $request, PaiementPeriode $periode): RedirectResponse
    {
        $this->authorize('ajuster', $periode);

        $data = $request->validate([
            'parts' => ['required', 'array', 'min:1'],
            'parts.*.type' => ['required', Rule::in(['vente', 'logistique'])],
            'parts.*.id' => ['required', 'string'],
        ]);

        $resolved = collect($data['parts'])->map(fn (array $p) => $this->resolvePart($p['type'], $p['id']));

        $count = CommissionAdjustmentService::validerLot($resolved, $request->user());

        app(AuditLogService::class)->record($periode, AuditEvent::VALIDATED, $request->user(), null, null, [
            'module' => 'ajustements_commissions',
            'nb_parts' => $count,
            'description' => "{$count} commission(s) validée(s) pour la période {$periode->reference}",
        ]);

        return back()->with('success', "{$count} commission(s) validée(s).");
    }

    private function resolvePart(string $type, string $partId): CommissionPart|CommissionLogistiquePart
    {
        return match ($type) {
            'vente' => CommissionPart::findOrFail($partId),
            'logistique' => CommissionLogistiquePart::findOrFail($partId),
            default => abort(404),
        };
    }

    private function authorizeSurPart(CommissionPart|CommissionLogistiquePart $part): void
    {
        abort_unless($part->commission->organization_id === auth()->user()->organization_id, 403, 'Accès refusé.');
        abort_unless(auth()->user()->isAdmin(), 403, 'Réservé aux administrateurs.');
    }

    private function transform(CommissionPart|CommissionLogistiquePart $part, string $type): array
    {
        return [
            'id' => $part->id,
            'type' => $type,
            'beneficiaire_nom' => $part->beneficiaire_nom,
            'type_beneficiaire' => $part->type_beneficiaire,
            'origine' => $part->origine?->value,
            'origine_label' => $part->origine?->label(),
            'montant_theorique' => (float) $part->montant_net,
            'montant_actuel' => $part->montant_actuel !== null ? (float) $part->montant_actuel : null,
            'montant_a_payer' => $part->montant_a_payer,
            'ecart' => round($part->montant_a_payer - (float) $part->montant_net, 2),
            'statut' => $part->statut?->value,
            'statut_label' => $part->statut?->label(),
            'est_validee' => $part->estValidee(),
            'validateur_nom' => $part->validateur?->name,
            'validated_at' => $part->validated_at?->toDateTimeString(),
            'peut_etre_ajustee' => $part->peutEtreAjustee(),
            'reference' => $type === 'vente'
                ? ($part->commission->commande->reference ?? '—')
                : ($part->commission->transfert->reference ?? '—'),
        ];
    }
}
