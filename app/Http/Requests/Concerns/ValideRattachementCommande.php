<?php

namespace App\Http\Requests\Concerns;

use App\Models\DepenseType;
use App\Services\Depenses\CommandeCarburantService;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Validator;

/**
 * Rattachement à une commande des dépenses dont le type a `commande_obligatoire` (ADR 0031) —
 * partagé par StoreDepenseRequest et UpdateDepenseRequest. Exige soit une commande de vente, soit
 * un transfert logistique (jamais les deux), soit une exception (`sans_commande`) avec motif. La
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
            'sans_commande' => ['boolean'],
            'motif_sans_commande' => ['nullable', 'required_if_accepted:sans_commande', 'string', 'min:5', 'max:500'],
            'commande_vente_id' => ['nullable', 'ulid'],
            'transfert_logistique_id' => ['nullable', 'ulid'],
        ];
    }

    /** @return array<string, string> */
    protected function rattachementCommandeMessages(): array
    {
        return [
            'motif_sans_commande.required_if_accepted' => 'Indiquez le motif de la dépense sans commande.',
            'motif_sans_commande.min' => 'Le motif doit faire au moins 5 caractères.',
            'motif_sans_commande.max' => 'Le motif ne peut pas dépasser 500 caractères.',
        ];
    }

    /**
     * Attributs à enregistrer sur la dépense : tout à null si le type n'exige pas de commande
     * (changement de type compris), sinon soit la commande, soit le motif d'exception.
     *
     * @return array{commande_vente_id: ?string, transfert_logistique_id: ?string, motif_sans_commande: ?string}
     */
    public function rattachementCommandeAttributs(): array
    {
        $aucun = ['commande_vente_id' => null, 'transfert_logistique_id' => null, 'motif_sans_commande' => null];

        if (! $this->typeExigeCommande()) {
            return $aucun;
        }

        if ($this->boolean('sans_commande')) {
            return [...$aucun, 'motif_sans_commande' => trim((string) $this->motif_sans_commande)];
        }

        return [
            ...$aucun,
            'commande_vente_id' => $this->input('commande_vente_id') ?: null,
            'transfert_logistique_id' => $this->input('transfert_logistique_id') ?: null,
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            if (! $this->typeExigeCommande() || $this->boolean('sans_commande')) {
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
            return 'Rattachez cette dépense à une commande de vente ou à un transfert logistique, ou déclarez une dépense sans commande avec son motif.';
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
