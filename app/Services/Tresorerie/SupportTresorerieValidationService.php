<?php

namespace App\Services\Tresorerie;

use App\Models\CompteTresorerie;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Validation d'un support de trésorerie : brouillon → actif.
 *
 * Un support (caisse, banque, Mobile Money, caisse dédiée à un agent) est créé en brouillon,
 * inutilisable partout — encaissements, mouvements, versements, position de trésorerie, soldes
 * d'ouverture. Sa validation, réservée à `tresorerie.valider_supports` (policy
 * CompteTresoreriePolicy::valider), le met en service et trace qui l'a validé et quand.
 *
 * Aucun contrôle « créateur ≠ validateur » : la séparation se fait en attribuant la permission à
 * un autre profil que celui qui gère les supports. Le solde d'ouverture reste un acte distinct
 * (SoldeOuvertureTresorerieService), sans lien de dépendance avec la validation du support.
 *
 * Une caisse dédiée revérifie, à la validation, les conditions de sa création (agent actif et
 * rattaché à l'agence, une seule caisse dédiée active par agent et par agence).
 */
class SupportTresorerieValidationService
{
    public function __construct(
        private readonly CaisseAgentService $caissesAgents,
    ) {}

    /** @throws ValidationException si le support n'est pas un brouillon ou si une règle propre à la caisse dédiée échoue */
    public function valider(CompteTresorerie $support, User $par): CompteTresorerie
    {
        return DB::transaction(function () use ($support, $par) {
            // Verrou : deux validations simultanées se sérialisent, la seconde voit le support déjà validé.
            $verrouille = CompteTresorerie::forOrg($par->organization_id)->whereKey($support->id)->lockForUpdate()->firstOrFail();

            if ($verrouille->estValide()) {
                throw ValidationException::withMessages([
                    'statut' => "« {$verrouille->libelle} » est déjà validé.",
                ]);
            }

            if ($verrouille->isDediee()) {
                $this->caissesAgents->verifierActivable($verrouille);
            }

            $verrouille->update([
                'valide_le' => now(),
                'valide_par_id' => $par->id,
                'actif' => true,
            ]);

            return $verrouille->fresh();
        });
    }
}
