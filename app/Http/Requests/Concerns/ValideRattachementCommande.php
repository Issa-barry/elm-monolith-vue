<?php

namespace App\Http\Requests\Concerns;

use App\Models\DepenseType;
use App\Services\Depenses\CommandeCarburantService;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Validator;

/**
 * Rattachement à une commande des dépenses dont le type a `commande_obligatoire` (ADR 0031) —
 * partagé par StoreDepenseRequest et UpdateDepenseRequest. Exige une commande de vente ou un
 * transfert logistique, jamais les deux, sans exception possible (décision du 17/10/2026). La
 * commande est revérifiée côté serveur : la sélection faite à l'écran n'est jamais crue telle quelle.
 */
trait ValideRattachementCommande
{
    protected function typeExigeCommande(): bool
    {
        return (bool) DepenseType::where('organization_id', $this->user()->organization_id)
            ->where('is_active', true)
            ->find($this->depense_type_id)
            ?->commande_obligatoire;
    }

    /** @return array<string, list<mixed>> */
    protected function rattachementCommandeRules(): array
    {
        if (! $this->typeExigeCommande()) {
            return [];
        }

        return [
            'commande_vente_id' => ['nullable', 'ulid'],
            'transfert_logistique_id' => ['nullable', 'ulid'],
        ];
    }

    /**
     * Attributs à enregistrer sur la dépense : la commande si le type l'exige, sinon rien. Le motif
     * d'une ancienne exception (`motif_sans_commande`, avant le 17/10/2026) est effacé dès que la
     * dépense est réenregistrée : elle porte alors une commande, ou n'en exige plus.
     *
     * @return array{commande_vente_id: ?string, transfert_logistique_id: ?string, motif_sans_commande: null}
     */
    public function rattachementCommandeAttributs(): array
    {
        $exige = $this->typeExigeCommande();

        return [
            'commande_vente_id' => $exige ? ($this->input('commande_vente_id') ?: null) : null,
            'transfert_logistique_id' => $exige ? ($this->input('transfert_logistique_id') ?: null) : null,
            'motif_sans_commande' => null,
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            if (! $this->typeExigeCommande()) {
                return;
            }

            $erreur = $this->erreurRattachementCommande($v->errors()->hasAny(['beneficiaire_id', 'date_depense', 'site_id']));
            if ($erreur !== null) {
                $v->errors()->add('commande', $erreur);
            }
        });
    }

    private function erreurRattachementCommande(bool $contexteInvalide): ?string
    {
        $venteId = $this->input('commande_vente_id') ?: null;
        $transfertId = $this->input('transfert_logistique_id') ?: null;

        if ($venteId === null && $transfertId === null) {
            return 'Une dépense de carburant doit être rattachée à une commande de vente ou à un transfert logistique.';
        }
        if ($venteId !== null && $transfertId !== null) {
            return 'Une dépense ne peut être rattachée qu\'à une seule commande.';
        }
        if ($contexteInvalide || ! $this->beneficiaire_id) {
            return null;
        }

        $erreur = app(CommandeCarburantService::class)->verifier(
            $this->user()->organization_id,
            (string) $this->beneficiaire_id,
            Carbon::parse($this->date_depense),
            $this->site_id ?: null,
            $venteId,
            $transfertId,
        );

        return $erreur === null ? null : ucfirst($erreur);
    }
}
