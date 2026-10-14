<?php

namespace App\Services\Tresorerie;

use App\Enums\EvenementComptable;
use App\Enums\NatureMouvementFonds;
use App\Enums\OperateurMobileMoney;
use App\Enums\StatutMouvementFonds;
use App\Enums\TypeSupportTresorerie;
use App\Models\CompteComptable;
use App\Models\CompteMapping;
use App\Models\CompteTresorerie;
use App\Models\MouvementFonds;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Supports de trésorerie dédiés à un agent : sa caisse en espèces (chantier Trésorerie > Supports,
 * décision du 2026-09-19, ADR 0001) et son compte Mobile Money (ADR 0027). Un support dédié est
 * rattaché à un agent (`agent_id`) et à un site, avec son PROPRE sous-compte comptable (571001,
 * 571002... pour une caisse ; à la suite du compte de l'opérateur pour un compte Mobile Money) :
 * `compta_ecritures` ne porte pas de support, seulement compte + site — c'est ce sous-compte qui
 * rend son solde distinguable de celui des supports de l'agence (cf.
 * TresorerieDisponibiliteService::situationParSupport()).
 *
 * Règles garanties ici (côté serveur, jamais seulement par l'interface) :
 *  - l'agent appartient à l'organisation, est actif et rattaché au site ;
 *  - au plus UNE caisse dédiée active par (agent, site) — nécessaire pour que le routage
 *    automatique des encaissements en espèces reste sans ambiguïté — et au plus UN compte Mobile
 *    Money actif par (agent, site, opérateur) ; caisse et comptes Mobile Money coexistent ;
 *  - un portefeuille réel ne porte qu'un seul compte : le numéro d'un compte Mobile Money d'agent
 *    n'est celui d'aucun autre support Mobile Money non désactivé du même opérateur, dans
 *    l'organisation ;
 *  - type, opérateur et compte comptable ne se modifient jamais après création ;
 *  - un support est créé en BROUILLON (inactif, inutilisable) : il ne devient actif qu'à sa
 *    validation (SupportTresorerieValidationService), qui revérifie l'agent et les unicités ;
 *  - un support qui détient encore de l'argent, ou dont un versement est en cours (envoyé ou
 *    contesté), ne peut pas être désactivé.
 *
 * Un support dédié démarre à 0, sans solde d'ouverture ni écriture à sa création. Une caisse
 * s'alimente par les encaissements en espèces de l'agent ou par un approvisionnement depuis la
 * caisse de l'agence, confirmé par l'agent lui-même
 * (MouvementFondsService::approvisionnerCaisseAgent(), ADR 0018). Un compte Mobile Money n'est
 * encore alimenté par rien : l'encaissement sur ce compte et son transfert vers l'agence sont
 * les lots suivants de l'ADR 0027.
 */
class CaisseAgentService
{
    /** Compte racine des caisses : les sous-comptes en sont numérotés à la suite (571001...571999). */
    private const COMPTE_RACINE = '571000';

    private const PREFIXE_SOUS_COMPTE = '571';

    /** Les sous-comptes portent un suffixe à 3 chiffres : 999 par préfixe et par organisation. */
    private const SUFFIXE_MAX = 999;

    public function __construct(
        private readonly TresorerieDisponibiliteService $disponibilite,
    ) {}

    public function creer(string $organizationId, string $siteId, string $agentId, ?string $libelle = null): CompteTresorerie
    {
        return DB::transaction(function () use ($organizationId, $siteId, $agentId, $libelle) {
            [$site, $agent] = $this->siteEtAgentVerrouille($organizationId, $siteId, $agentId);

            $this->garantirUneSeuleCaisseActive($organizationId, $agent, $site, null, 'agent_id');

            $parentId = CompteComptable::where('organization_id', $organizationId)
                ->where('numero', self::COMPTE_RACINE)
                ->value('id');
            $compte = $this->creerSousCompte($organizationId, $parentId, self::PREFIXE_SOUS_COMPTE, "Caisse {$agent->name} ({$site->nom})");

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
     * Compte Mobile Money dédié à un agent (ADR 0027) : un par (agent, agence, opérateur), numéro
     * du portefeuille obligatoire, sous-compte propre créé à la suite du compte de l'opérateur
     * (561100001, 561100002... sous 561100) — le compte de l'opérateur vient de compta_mappings,
     * jamais d'un numéro codé en dur. Créé en brouillon, à 0, sans écriture.
     */
    public function creerCompteMobileMoney(string $organizationId, string $siteId, string $agentId, string $operateur, ?string $numero, ?string $libelle = null): CompteTresorerie
    {
        $operateur = OperateurMobileMoney::tryFrom($operateur);
        if (! $operateur || ! in_array($operateur, OperateurMobileMoney::avecWallet(), true)) {
            throw ValidationException::withMessages(['operateur_mobile_money' => 'Choisissez l\'opérateur du portefeuille.']);
        }

        $numero = trim((string) $numero);
        if (CompteTresorerie::normaliserNumero($numero) === '') {
            throw ValidationException::withMessages(['numero' => 'Le numéro du portefeuille est obligatoire.']);
        }

        // Paramétrage lu AVANT la transaction, pour qu'elle commence par ses verrous (ci-dessous).
        $compteOperateurId = $this->compteOperateurId($organizationId, $operateur);
        if (! $compteOperateurId) {
            throw ValidationException::withMessages([
                'operateur_mobile_money' => "Aucun compte comptable n'est paramétré pour {$operateur->label()} dans cette organisation.",
            ]);
        }

        return DB::transaction(function () use ($organizationId, $siteId, $agentId, $operateur, $compteOperateurId, $numero, $libelle) {
            // Les verrous d'abord, avant toute lecture ordinaire. Sous MySQL (REPEATABLE READ), une
            // transaction fige ce qu'elle voit à sa première lecture non verrouillante : prise avant
            // l'attente du verrou, elle ignorerait le compte qu'une création concurrente vient
            // d'enregistrer, et le contrôle d'unicité comme la numérotation liraient un état périmé.
            // Verrou sur l'agent (comme creer()), puis sur le compte de l'opérateur, qui sérialise
            // les créations, validations et corrections de numéro des comptes de cet opérateur.
            $agent = User::where('organization_id', $organizationId)->whereKey($agentId)->lockForUpdate()->first();
            $compteOperateur = CompteComptable::where('organization_id', $organizationId)->whereKey($compteOperateurId)->lockForUpdate()->first();

            $site = Site::where('organization_id', $organizationId)->whereKey($siteId)->first();
            if (! $site) {
                throw ValidationException::withMessages(['site_id' => 'Agence introuvable dans cette organisation.']);
            }
            if (! $agent) {
                throw ValidationException::withMessages(['agent_id' => 'Agent introuvable dans cette organisation.']);
            }
            $this->verifierAgent($agent, $site, 'agent_id');
            if (! $compteOperateur) {
                throw ValidationException::withMessages([
                    'operateur_mobile_money' => "Aucun compte comptable n'est paramétré pour {$operateur->label()} dans cette organisation.",
                ]);
            }

            $this->garantirUnSeulCompteMobileMoneyActif($organizationId, $agent, $site, $operateur, null, 'agent_id');
            $this->garantirNumeroLibre($organizationId, $operateur, $numero, null);

            $compte = $this->creerSousCompte($organizationId, $compteOperateur->id, $compteOperateur->numero, "{$operateur->label()} {$agent->name} ({$site->nom})");

            return CompteTresorerie::create([
                'organization_id' => $organizationId,
                'site_id' => $site->id,
                'agent_id' => $agent->id,
                'compte_comptable_id' => $compte->id,
                'type' => TypeSupportTresorerie::MOBILE_MONEY->value,
                'operateur_mobile_money' => $operateur->value,
                'numero' => $numero,
                // Vide → libellé automatique « {Opérateur} — {agent} » (CompteTresorerie::boot()).
                'libelle' => trim((string) $libelle),
                'moyen_paiement_defaut' => 'mobile_money:'.$operateur->detailComptable(),
                'actif' => false,
            ]);
        });
    }

    /**
     * Conditions à réunir AU MOMENT de mettre un support dédié en service (validation) : entre la
     * création du brouillon et sa validation, l'agent a pu être désactivé ou retiré de l'agence, un
     * autre support actif lui avoir été attribué, ou le numéro avoir été pris par un autre compte.
     * Appelé sous transaction par SupportTresorerieValidationService::valider().
     *
     * @throws ValidationException
     */
    public function verifierActivable(CompteTresorerie $support, string $champ = 'statut'): void
    {
        $agent = User::where('organization_id', $support->organization_id)->whereKey($support->agent_id)->lockForUpdate()->first();
        if (! $agent) {
            throw ValidationException::withMessages([$champ => 'Agent introuvable dans cette organisation.']);
        }
        // Avant la première lecture ordinaire, cf. creerCompteMobileMoney().
        $this->verrouillerCompteOperateur($support);

        $site = Site::where('organization_id', $support->organization_id)->whereKey($support->site_id)->first();
        if (! $site) {
            throw ValidationException::withMessages([$champ => 'Agence introuvable dans cette organisation.']);
        }

        $this->verifierAgent($agent, $site, $champ);
        $this->garantirUnicite($support, $agent, $site, $champ);
    }

    /**
     * Seuls le libellé et l'activation sont modifiables : l'agent, le site, le type, l'opérateur et
     * le sous-compte sont figés (les écritures déjà posées y font référence). Le numéro d'un compte
     * Mobile Money se corrige tant qu'il est en brouillon, plus après sa validation.
     *
     * @param  array{libelle:string, actif:bool, numero?:?string}  $data
     */
    public function mettreAJour(CompteTresorerie $support, array $data): CompteTresorerie
    {
        if (! $support->isDediee()) {
            throw new \LogicException('mettreAJour() ne concerne que les supports dédiés à un agent.');
        }

        return DB::transaction(function () use ($support, $data) {
            $actif = (bool) $data['actif'];

            // Un brouillon ne s'active que par sa validation, jamais par un simple basculement.
            if ($actif && ! $support->estValide()) {
                throw ValidationException::withMessages([
                    'actif' => $support->isMobileMoneyDedie()
                        ? "Ce compte n'est pas encore validé : validez-le avant de l'activer."
                        : "Cette caisse n'est pas encore validée : validez-la avant de l'activer.",
                ]);
            }

            // Même verrou que creer() : réactiver et créer ne doivent pas s'enchaîner en parallèle.
            $agent = User::whereKey($support->agent_id)->lockForUpdate()->first();
            $this->verrouillerCompteOperateur($support);

            $modifications = ['libelle' => trim($data['libelle']), 'actif' => $actif];

            if ($support->isMobileMoneyDedie() && array_key_exists('numero', $data)) {
                $numero = $this->numeroCorrige($support, (string) $data['numero']);
                if ($numero !== null) {
                    $modifications['numero'] = $numero;
                }
            }

            if ($actif && ! $support->actif && $agent) {
                $this->garantirUnicite($support, $agent, $support->site, 'actif');
            }

            if (! $actif && $support->actif) {
                $this->garantirDesactivable($support);
            }

            $support->update($modifications);

            return $support->fresh();
        });
    }

    /**
     * Garde de la règle « un portefeuille = un seul compte » côté supports d'AGENCE : un compte
     * Mobile Money de l'agence ne peut pas prendre le numéro du compte d'un agent. Les supports
     * d'agence existants ne sont pas comparés entre eux (aucune règle nouvelle pour eux).
     *
     * @throws ValidationException
     */
    public function garantirNumeroNonPrisParUnAgent(string $organizationId, OperateurMobileMoney $operateur, ?string $numero, ?string $ignorerSupportId): void
    {
        if (CompteTresorerie::normaliserNumero($numero) === '') {
            return;
        }

        $this->garantirNumeroLibre($organizationId, $operateur, (string) $numero, $ignorerSupportId, agentsSeulement: true);
    }

    /**
     * Numéro à enregistrer s'il a réellement changé, null sinon.
     *
     * @throws ValidationException
     */
    private function numeroCorrige(CompteTresorerie $support, string $saisi): ?string
    {
        $numero = trim($saisi);
        if ($numero === trim((string) $support->numero)) {
            return null;
        }

        if ($support->estValide()) {
            // Le même portefeuille écrit autrement n'est pas une modification : rien à refuser.
            if (CompteTresorerie::memeNumero($numero, $support->numero)) {
                return null;
            }

            throw ValidationException::withMessages([
                'numero' => 'Le numéro d\'un compte validé ne peut plus être modifié : désactivez ce compte et créez-en un nouveau.',
            ]);
        }
        if (CompteTresorerie::normaliserNumero($numero) === '') {
            throw ValidationException::withMessages(['numero' => 'Le numéro du portefeuille est obligatoire.']);
        }

        $this->garantirNumeroLibre($support->organization_id, $support->operateur_mobile_money, $numero, $support->id);

        return $numero;
    }

    /**
     * Un support dédié ne se désactive que vide et sans argent en transit, dans un sens comme dans l'autre.
     */
    private function garantirDesactivable(CompteTresorerie $support): void
    {
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

        // Même raison pour un compte Mobile Money (ADR 0027) : un transfert retourné le recréditerait.
        $transfertEnCours = MouvementFonds::where('organization_id', $support->organization_id)
            ->where('compte_tresorerie_origine_id', $support->id)
            ->where('nature', NatureMouvementFonds::TRANSFERT_MOBILE_MONEY->value)
            ->whereIn('statut', [StatutMouvementFonds::ENVOYE->value, StatutMouvementFonds::CONTESTE->value])
            ->exists();
        if ($transfertEnCours) {
            throw ValidationException::withMessages([
                'actif' => 'Un transfert de ce compte est en cours (envoyé ou contesté) : attendez sa réception ou son retour avant de le désactiver.',
            ]);
        }

        // Sens inverse (ADR 0018) : un approvisionnement en attente créditerait une caisse
        // désactivée à sa confirmation.
        $approvisionnementEnCours = MouvementFonds::where('organization_id', $support->organization_id)
            ->where('compte_tresorerie_destination_id', $support->id)
            ->where('nature', NatureMouvementFonds::APPROVISIONNEMENT_CAISSE->value)
            ->whereIn('statut', [StatutMouvementFonds::ENVOYE->value, StatutMouvementFonds::CONTESTE->value])
            ->exists();
        if ($approvisionnementEnCours) {
            throw ValidationException::withMessages([
                'actif' => 'Un approvisionnement de cette caisse est en attente (envoyé ou contesté) : attendez sa confirmation par l\'agent ou son retour avant de la désactiver.',
            ]);
        }

        $solde = $this->disponibilite->soldePourSupport($support);
        if (abs($solde) >= 0.005) {
            $montant = number_format(abs($solde), 0, ',', ' ');
            throw ValidationException::withMessages([
                'actif' => $support->isMobileMoneyDedie()
                    ? "Ce compte détient encore {$montant} GNF : il doit être vide avant d'être désactivé."
                    : "Cette caisse détient encore {$montant} GNF : versez d'abord ce solde à la caisse de l'agence avant de la désactiver.",
            ]);
        }
    }

    /**
     * Verrou sur l'agent : sérialise deux créations simultanées pour le même agent, sinon les deux
     * passeraient les contrôles d'unicité.
     *
     * @return array{0: Site, 1: User}
     */
    private function siteEtAgentVerrouille(string $organizationId, string $siteId, string $agentId): array
    {
        $site = Site::where('organization_id', $organizationId)->whereKey($siteId)->first();
        if (! $site) {
            throw ValidationException::withMessages(['site_id' => 'Agence introuvable dans cette organisation.']);
        }

        $agent = User::where('organization_id', $organizationId)->whereKey($agentId)->lockForUpdate()->first();
        if (! $agent) {
            throw ValidationException::withMessages(['agent_id' => 'Agent introuvable dans cette organisation.']);
        }
        $this->verifierAgent($agent, $site, 'agent_id');

        return [$site, $agent];
    }

    private function verifierAgent(User $agent, Site $site, string $champ): void
    {
        if (! $agent->is_active) {
            throw ValidationException::withMessages([$champ => "{$agent->name} est désactivé : un support dédié ne peut pas lui être attribué."]);
        }
        if (! $agent->isAssignedToSite($site->id)) {
            throw ValidationException::withMessages([$champ => "{$agent->name} n'est pas rattaché à l'agence {$site->nom}."]);
        }
    }

    /** Unicités propres au type du support, à la validation comme à la réactivation. */
    private function garantirUnicite(CompteTresorerie $support, User $agent, Site $site, string $champ): void
    {
        if (! $support->isMobileMoneyDedie()) {
            $this->garantirUneSeuleCaisseActive($support->organization_id, $agent, $site, $support->id, $champ);

            return;
        }

        $operateur = $support->operateur_mobile_money;
        $this->garantirUnSeulCompteMobileMoneyActif($support->organization_id, $agent, $site, $operateur, $support->id, $champ);
        $this->garantirNumeroLibre($support->organization_id, $operateur, (string) $support->numero, $support->id, $champ);
    }

    private function garantirUneSeuleCaisseActive(string $organizationId, User $agent, Site $site, ?string $ignorerSupportId, string $champ): void
    {
        $existe = CompteTresorerie::forOrg($organizationId)
            ->caissesDediees()
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

    private function garantirUnSeulCompteMobileMoneyActif(string $organizationId, User $agent, Site $site, OperateurMobileMoney $operateur, ?string $ignorerSupportId, string $champ): void
    {
        $existe = CompteTresorerie::forOrg($organizationId)
            ->mobileMoneyDedies()
            ->actifs()
            ->where('agent_id', $agent->id)
            ->where('site_id', $site->id)
            ->where('operateur_mobile_money', $operateur->value)
            ->when($ignorerSupportId, fn ($q) => $q->whereKeyNot($ignorerSupportId))
            ->exists();

        if ($existe) {
            throw ValidationException::withMessages([
                $champ => "{$agent->name} a déjà un compte {$operateur->label()} actif à {$site->nom}.",
            ]);
        }
    }

    /**
     * Un portefeuille réel (un numéro chez un opérateur) ne porte qu'un seul compte dans
     * l'organisation : brouillons et supports actifs comptent, un support désactivé libère son
     * numéro. Deux organisations peuvent enregistrer le même numéro. Avec ou sans indicatif, c'est
     * le même portefeuille (CompteTresorerie::memeNumero()).
     *
     * Lecture ordinaire : elle n'est à jour que parce que l'appelant prend ses verrous avant toute
     * lecture (cf. creerCompteMobileMoney()). La validation reste le garde-fou : deux brouillons
     * portant le même numéro ne peuvent être validés ni l'un ni l'autre.
     */
    private function garantirNumeroLibre(string $organizationId, OperateurMobileMoney $operateur, string $numero, ?string $ignorerSupportId, string $champ = 'numero', bool $agentsSeulement = false): void
    {
        $conflit = CompteTresorerie::forOrg($organizationId)
            ->where('type', TypeSupportTresorerie::MOBILE_MONEY->value)
            ->where('operateur_mobile_money', $operateur->value)
            ->whereNotNull('numero')
            ->nonDesactives()
            ->when($agentsSeulement, fn ($q) => $q->dediees())
            ->when($ignorerSupportId, fn ($q) => $q->whereKeyNot($ignorerSupportId))
            ->get(['id', 'libelle', 'numero'])
            ->first(fn (CompteTresorerie $s) => CompteTresorerie::memeNumero($s->numero, $numero));

        if ($conflit) {
            throw ValidationException::withMessages([
                $champ => "Le numéro {$numero} est déjà celui de « {$conflit->libelle} » ({$operateur->label()}) : un portefeuille ne porte qu'un seul compte.",
            ]);
        }
    }

    /**
     * Compte comptable de l'opérateur, lu dans compta_mappings (« mobile_money:<detail> », celui de
     * l'encaissement de vente en priorité). Paramétrage : à lire avant la transaction.
     */
    private function compteOperateurId(string $organizationId, OperateurMobileMoney $operateur): ?string
    {
        $detail = $operateur->detailComptable();
        if ($detail === null) {
            return null;
        }

        $mappings = CompteMapping::where('organization_id', $organizationId)
            ->where('role', 'tresorerie')
            ->where('moyen_paiement', 'mobile_money:'.$detail)
            ->where('actif', true);

        return (clone $mappings)->where('evenement', EvenementComptable::ENCAISSEMENT_VENTE_RECU->value)->value('compte_comptable_id')
            ?? $mappings->value('compte_comptable_id');
    }

    /**
     * Même verrou que la création, pour un compte Mobile Money existant : le compte de l'opérateur
     * est le parent de son sous-compte. Lectures verrouillantes uniquement — elles ne figent pas
     * ce que voit la transaction. Sans effet pour une caisse.
     */
    private function verrouillerCompteOperateur(CompteTresorerie $support): void
    {
        if (! $support->isMobileMoneyDedie()) {
            return;
        }

        $parentId = CompteComptable::whereKey($support->compte_comptable_id)->lockForUpdate()->value('parent_id');
        if ($parentId) {
            CompteComptable::whereKey($parentId)->lockForUpdate()->value('id');
        }
    }

    private function creerSousCompte(string $organizationId, ?string $parentId, string $prefixe, string $libelle): CompteComptable
    {
        // Le numéro est calculé puis inséré sans verrou global : si deux agents
        // différents sont créés au même instant, la contrainte unique
        // (organization_id, numero) fait échouer le second, qui reprend le numéro suivant.
        for ($tentative = 0; $tentative < 3; $tentative++) {
            try {
                return CompteComptable::create([
                    'organization_id' => $organizationId,
                    'numero' => $this->prochainNumero($organizationId, $prefixe),
                    'libelle' => Str::limit($libelle, 150, ''),
                    'parent_id' => $parentId,
                    'actif' => true,
                ]);
            } catch (UniqueConstraintViolationException) {
                continue;
            }
        }

        throw ValidationException::withMessages([
            'agent_id' => 'Impossible de réserver un numéro de compte pour ce support, réessayez.',
        ]);
    }

    /** Préfixe + suffixe à 3 chiffres, à la suite du plus grand suffixe déjà pris sous ce préfixe. */
    private function prochainNumero(string $organizationId, string $prefixe): string
    {
        $max = 0;

        $numeros = CompteComptable::where('organization_id', $organizationId)
            ->where('numero', 'like', $prefixe.'%')
            ->pluck('numero');

        foreach ($numeros as $numero) {
            if (preg_match('/^'.preg_quote($prefixe, '/').'(\d{3})$/', $numero, $suffixe)) {
                $max = max($max, (int) $suffixe[1]);
            }
        }

        $prochain = $max + 1;
        if ($prochain > self::SUFFIXE_MAX) {
            throw ValidationException::withMessages([
                'agent_id' => 'Plus aucun numéro de sous-compte disponible sous '.$prefixe.'.',
            ]);
        }

        return $prefixe.str_pad((string) $prochain, 3, '0', STR_PAD_LEFT);
    }
}
