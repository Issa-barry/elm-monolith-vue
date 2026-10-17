<?php

namespace App\Services\Tresorerie;

use App\Enums\StatutMouvementFonds;
use App\Models\CompteTresorerie;
use App\Models\MouvementFonds;
use Illuminate\Validation\ValidationException;

/**
 * Désactivation d'un support d'AGENCE (caisse, banque, Mobile Money de l'agence — ADR 0028) :
 * même règle que pour un support dédié à un agent (CaisseAgentService) — un support désactivé ne
 * doit pas « cacher » de l'argent.
 *
 *  - aucun mouvement de fonds en transit (envoyé ou contesté) ne part de ce support ni n'y
 *    arrive : un retour le recréditerait, une réception le créditerait après sa désactivation ;
 *  - son solde au grand livre est nul.
 *
 * Le grand livre ne connaît que (agence, compte) : quand un autre support actif de la même agence
 * porte le même compte comptable, le solde reste affiché sur celui-ci — rien n'est caché, le
 * contrôle de solde ne s'applique donc pas (cf. TresorerieDisponibiliteService::situationParSupport()).
 */
class SupportAgenceDesactivationService
{
    public function __construct(
        private readonly TresorerieDisponibiliteService $disponibilite,
    ) {}

    /** À appeler sous transaction, le support verrouillé (`lockForUpdate()`). */
    public function garantirDesactivable(CompteTresorerie $support): void
    {
        if ($support->isDediee()) {
            throw new \LogicException('garantirDesactivable() ne concerne que les supports d\'agence (cf. CaisseAgentService).');
        }

        $enTransit = MouvementFonds::where('organization_id', $support->organization_id)
            ->where(fn ($q) => $q
                ->where('compte_tresorerie_origine_id', $support->id)
                ->orWhere('compte_tresorerie_destination_id', $support->id)
            )
            ->whereIn('statut', [StatutMouvementFonds::ENVOYE->value, StatutMouvementFonds::CONTESTE->value])
            ->exists();
        if ($enTransit) {
            throw ValidationException::withMessages([
                'actif' => 'Un mouvement de fonds de ce support est en cours (envoyé ou contesté) : attendez sa réception ou son retour avant de le désactiver.',
            ]);
        }

        $compteVisibleAilleurs = CompteTresorerie::forOrg($support->organization_id)
            ->actifs()
            ->where('site_id', $support->site_id)
            ->where('compte_comptable_id', $support->compte_comptable_id)
            ->whereKeyNot($support->id)
            ->exists();
        if ($compteVisibleAilleurs) {
            return;
        }

        $solde = $this->disponibilite->soldePourSupport($support);
        if (abs($solde) >= 0.005) {
            $montant = number_format(abs($solde), 0, ',', ' ');
            $sens = $solde > 0 ? 'détient encore' : 'présente un solde négatif de';

            throw ValidationException::withMessages([
                'actif' => "« {$support->libelle} » {$sens} {$montant} GNF : régularisez ce solde (mouvement vers un autre support) avant de le désactiver.",
            ]);
        }
    }
}
