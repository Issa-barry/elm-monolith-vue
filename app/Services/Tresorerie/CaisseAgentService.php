<?php

namespace App\Services\Tresorerie;

use App\Enums\NatureMouvementFonds;
use App\Enums\StatutMouvementFonds;
use App\Enums\TypeSupportTresorerie;
use App\Models\CompteComptable;
use App\Models\CompteTresorerie;
use App\Models\MouvementFonds;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Caisses dédiées à un agent (chantier Trésorerie > Supports, décision du
 * 2026-09-19). Une caisse dédiée est un support de trésorerie de type Caisse
 * rattaché à un agent (`agent_id`) et à un site, avec son PROPRE sous-compte
 * comptable (571001, 571002...) : `compta_ecritures` ne porte pas de support,
 * seulement compte + site — c'est ce sous-compte qui rend son solde
 * distinguable de celui de la caisse de l'agence (cf.
 * TresorerieDisponibiliteService::situationParSupport()).
 *
 * Règles garanties ici (côté serveur, jamais seulement par l'interface) :
 *  - l'agent appartient à l'organisation, est actif et rattaché au site ;
 *  - au plus UNE caisse dédiée active par (agent, site) — nécessaire pour que
 *    le routage automatique des encaissements en espèces reste sans ambiguïté ;
 *  - type et compte comptable ne se modifient jamais après création ;
 *  - une caisse est créée en BROUILLON (inactive, inutilisable) : elle ne devient active qu'à sa
 *    validation (SupportTresorerieValidationService), qui revérifie l'agent et l'unicité ;
 *  - une caisse qui détient encore de l'argent, ou dont un versement est en cours (envoyé ou
 *    contesté), ne peut pas être désactivée.
 *
 * Une caisse dédiée démarre à 0, sans solde d'ouverture : son alimentation
 * passe par un transfert depuis la caisse de l'agence.
 */
class CaisseAgentService
{
    /** Compte racine des caisses : les sous-comptes en sont numérotés à la suite (571001...571999). */
    private const COMPTE_RACINE = '571000';

    private const PREFIXE_SOUS_COMPTE = '571';

    private const NUMERO_MAX = 571999;

    public function __construct(
        private readonly TresorerieDisponibiliteService $disponibilite,
    ) {}

    public function creer(string $organizationId, string $siteId, string $agentId, ?string $libelle = null): CompteTresorerie
    {
        return DB::transaction(function () use ($organizationId, $siteId, $agentId, $libelle) {
            $site = Site::where('organization_id', $organizationId)->whereKey($siteId)->first();
            if (! $site) {
                throw ValidationException::withMessages(['site_id' => 'Agence introuvable dans cette organisation.']);
            }

            // Verrou sur l'agent : sérialise deux créations simultanées pour le même
            // agent, sinon les deux passeraient le contrôle d'unicité ci-dessous.
            $agent = User::where('organization_id', $organizationId)->whereKey($agentId)->lockForUpdate()->first();
            if (! $agent) {
                throw ValidationException::withMessages(['agent_id' => 'Agent introuvable dans cette organisation.']);
            }
            $this->verifierAgent($agent, $site, 'agent_id');

            $this->garantirUneSeuleCaisseActive($organizationId, $agent, $site, null, 'agent_id');

            $compte = $this->creerSousCompte($organizationId, $site, $agent);

            return CompteTresorerie::create([
                'organization_id' => $organizationId,
                'site_id' => $site->id,
                'agent_id' => $agent->id,
                'compte_comptable_id' => $compte->id,
                'type' => TypeSupportTresorerie::CAISSE->value,
                // Vide → libellé automatique « Caisse {agent} » (CompteTresorerie::boot()).
                'libelle' => trim((string) $libelle),
                // Étiquette de pré-sélection dans les formulaires de paiement/mouvement.
                'moyen_paiement_defaut' => 'especes',
                // Brouillon : inutilisable jusqu'à la validation (SupportTresorerieValidationService).
                'actif' => false,
            ]);
        });
    }

    /**
     * Conditions à réunir AU MOMENT de mettre une caisse dédiée en service (validation) : entre la
     * création du brouillon et sa validation, l'agent a pu être désactivé ou retiré de l'agence, ou
     * une autre caisse active lui avoir été attribuée. Appelé sous transaction par
     * SupportTresorerieValidationService::valider().
     *
     * @throws ValidationException
     */
    public function verifierActivable(CompteTresorerie $support, string $champ = 'statut'): void
    {
        $agent = User::where('organization_id', $support->organization_id)->whereKey($support->agent_id)->lockForUpdate()->first();
        if (! $agent) {
            throw ValidationException::withMessages([$champ => 'Agent introuvable dans cette organisation.']);
        }

        $site = Site::where('organization_id', $support->organization_id)->whereKey($support->site_id)->first();
        if (! $site) {
            throw ValidationException::withMessages([$champ => 'Agence introuvable dans cette organisation.']);
        }

        $this->verifierAgent($agent, $site, $champ);
        $this->garantirUneSeuleCaisseActive($support->organization_id, $agent, $site, $support->id, $champ);
    }

    /**
     * Seuls le libellé et l'activation sont modifiables : l'agent, le site, le type
     * et le sous-compte sont figés (les écritures déjà posées y font référence).
     *
     * @param  array{libelle:string, actif:bool}  $data
     */
    public function mettreAJour(CompteTresorerie $support, array $data): CompteTresorerie
    {
        if (! $support->isDediee()) {
            throw new \LogicException('mettreAJour() ne concerne que les caisses dédiées à un agent.');
        }

        return DB::transaction(function () use ($support, $data) {
            $actif = (bool) $data['actif'];

            // Un brouillon ne s'active que par sa validation, jamais par un simple basculement.
            if ($actif && ! $support->estValide()) {
                throw ValidationException::withMessages([
                    'actif' => "Cette caisse n'est pas encore validée : validez-la avant de l'activer.",
                ]);
            }

            // Même verrou que creer() : réactiver et créer ne doivent pas s'enchaîner en parallèle.
            $agent = User::whereKey($support->agent_id)->lockForUpdate()->first();

            if ($actif && ! $support->actif && $agent) {
                $this->garantirUneSeuleCaisseActive($support->organization_id, $agent, $support->site, $support->id, 'actif');
            }

            if (! $actif && $support->actif) {
                // Un versement envoyé (ou contesté) n'a pas encore quitté définitivement la caisse :
                // un retour la recréditerait après désactivation, et une caisse désactivée doit
                // rester vide. Le solde seul ne le voit pas — l'argent est alors en transit.
                $versementEnCours = MouvementFonds::where('organization_id', $support->organization_id)
                    ->where('compte_tresorerie_origine_id', $support->id)
                    ->where('nature', NatureMouvementFonds::INTERNE_CAISSES->value)
                    ->whereIn('statut', [StatutMouvementFonds::ENVOYE->value, StatutMouvementFonds::CONTESTE->value])
                    ->exists();
                if ($versementEnCours) {
                    throw ValidationException::withMessages([
                        'actif' => 'Un versement de cette caisse est en cours (envoyé ou contesté) : attendez sa réception ou son retour avant de la désactiver.',
                    ]);
                }

                $solde = $this->disponibilite->soldePourSupport($support);
                if (abs($solde) >= 0.005) {
                    $montant = number_format(abs($solde), 0, ',', ' ');
                    throw ValidationException::withMessages([
                        'actif' => "Cette caisse détient encore {$montant} GNF : versez d'abord ce solde à la caisse de l'agence avant de la désactiver.",
                    ]);
                }
            }

            $support->update([
                'libelle' => trim($data['libelle']),
                'actif' => $actif,
            ]);

            return $support->fresh();
        });
    }

    private function verifierAgent(User $agent, Site $site, string $champ): void
    {
        if (! $agent->is_active) {
            throw ValidationException::withMessages([$champ => "{$agent->name} est désactivé : une caisse ne peut pas lui être attribuée."]);
        }
        if (! $agent->isAssignedToSite($site->id)) {
            throw ValidationException::withMessages([$champ => "{$agent->name} n'est pas rattaché à l'agence {$site->nom}."]);
        }
    }

    private function garantirUneSeuleCaisseActive(string $organizationId, User $agent, Site $site, ?string $ignorerSupportId, string $champ): void
    {
        $existe = CompteTresorerie::forOrg($organizationId)
            ->dediees()
            ->actifs()
            ->where('agent_id', $agent->id)
            ->where('site_id', $site->id)
            ->when($ignorerSupportId, fn ($q) => $q->whereKeyNot($ignorerSupportId))
            ->exists();

        if ($existe) {
            throw ValidationException::withMessages([
                $champ => "{$agent->name} a déjà une caisse dédiée active à {$site->nom}.",
            ]);
        }
    }

    private function creerSousCompte(string $organizationId, Site $site, User $agent): CompteComptable
    {
        $parentId = CompteComptable::where('organization_id', $organizationId)
            ->where('numero', self::COMPTE_RACINE)
            ->value('id');

        $libelle = Str::limit("Caisse {$agent->name} ({$site->nom})", 150, '');

        // Le numéro est calculé puis inséré sans verrou global : si deux agents
        // différents sont créés au même instant, la contrainte unique
        // (organization_id, numero) fait échouer le second, qui reprend le numéro suivant.
        for ($tentative = 0; $tentative < 3; $tentative++) {
            try {
                return CompteComptable::create([
                    'organization_id' => $organizationId,
                    'numero' => $this->prochainNumero($organizationId),
                    'libelle' => $libelle,
                    'parent_id' => $parentId,
                    'actif' => true,
                ]);
            } catch (UniqueConstraintViolationException) {
                continue;
            }
        }

        throw ValidationException::withMessages([
            'agent_id' => 'Impossible de réserver un numéro de compte pour cette caisse, réessayez.',
        ]);
    }

    private function prochainNumero(string $organizationId): string
    {
        $max = (int) self::COMPTE_RACINE;

        $numeros = CompteComptable::where('organization_id', $organizationId)
            ->where('numero', 'like', self::PREFIXE_SOUS_COMPTE.'%')
            ->pluck('numero');

        foreach ($numeros as $numero) {
            if (preg_match('/^'.self::PREFIXE_SOUS_COMPTE.'\d{3}$/', $numero)) {
                $max = max($max, (int) $numero);
            }
        }

        $prochain = $max + 1;
        if ($prochain > self::NUMERO_MAX) {
            throw ValidationException::withMessages([
                'agent_id' => 'Plus aucun numéro de sous-compte disponible sous '.self::PREFIXE_SOUS_COMPTE.'.',
            ]);
        }

        return (string) $prochain;
    }
}
