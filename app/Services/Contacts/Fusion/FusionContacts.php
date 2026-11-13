<?php

namespace App\Services\Contacts\Fusion;

use App\Enums\AuditEvent;
use App\Models\CategorieLibre;
use App\Models\ContactsFusion;
use App\Models\ContactsFusionOperation;
use App\Models\EntrepriseTierce;
use App\Models\Parrain;
use App\Models\Personne;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\Comptes\RattachementAutomatiqueService;
use App\Services\Contacts\ClientIdentiteSynchronisation;
use App\Services\Contacts\VisibiliteContacts;
use App\Support\Clients\ClientUniqueness;
use App\Support\Contacts\NumeroSecondaire;
use App\Support\Contacts\PresentationContacts;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Fusion manuelle de deux fiches du référentiel Contacts (ADR 0060, lot 9b) : la fiche A est
 * conservée, la fiche B est absorbée puis archivée (suppression logique) avec un lien vers A.
 *
 * - Jamais automatique : simulation (aucune écriture) puis confirmation explicite.
 * - Blocages : même fonction des deux côtés, fiche archivée comprise (F1) ; interlocuteurs de la
 *   même entreprise (décision 4) ; un compte de chaque côté (F2) ; fonction ou lien que
 *   l'utilisateur ne peut pas consulter (décision 6) ; donnée d'une autre organisation ; conflit
 *   d'unicité côté client si la synchronisation R1 est active.
 * - Les fonctions changent de fiche avec tout leur historique : rien n'est supprimé, recréé ni
 *   réaffecté à une autre fonction.
 * - Transaction avec verrou sur A et B, empreinte comparée à celle de la simulation (modification
 *   concurrente), jeton de formulaire (double envoi).
 * - Journal : chaque valeur écrite, avant et après, effets induits compris (identité recopiée sur
 *   le client, raison sociale recopiée, rattachement au compte et rôles ajoutés).
 */
final class FusionContacts
{
    /** Champs arbitrés d'une Personne : clé => [libellé, colonnes]. Téléphones à part. */
    public const CHAMPS_PERSONNE = [
        'nom' => ['Nom', ['nom']],
        'prenom' => ['Prénom', ['prenom']],
        'nom_complet' => ['Nom complet', ['nom_complet']],
        'surnom' => ['Surnom', ['surnom']],
        'email' => ['E-mail', ['email']],
        'pays' => ['Pays', ['pays', 'code_pays']],
        'ville' => ['Ville', ['ville']],
        'quartier' => ['Quartier', ['quartier']],
        'adresse' => ['Adresse', ['adresse']],
        'position' => ['Position GPS', ['latitude', 'longitude']],
        'date_naissance' => ['Date de naissance', ['date_naissance']],
        'lieu_naissance' => ['Lieu de naissance', ['lieu_naissance']],
        'civilite' => ['Civilité', ['civilite']],
        'sexe' => ['Sexe', ['sexe']],
        'nationalite' => ['Nationalité', ['nationalite']],
    ];

    /** Champs arbitrés d'une Entreprise (décision 8 : contact principal compris). */
    public const CHAMPS_ENTREPRISE = [
        'raison_sociale' => ['Raison sociale', ['raison_sociale']],
        'nom_commercial' => ['Nom commercial', ['nom_commercial']],
        'identifiant_fiscal' => ['NIF', ['identifiant_fiscal']],
        'rccm' => ['RCCM', ['rccm']],
        'email' => ['E-mail', ['email']],
        'pays' => ['Pays', ['pays', 'code_pays']],
        'ville' => ['Ville', ['ville']],
        'quartier' => ['Quartier', ['quartier']],
        'adresse' => ['Adresse', ['adresse']],
        'position' => ['Position GPS', ['latitude', 'longitude']],
        'contact_personne_id' => ['Contact principal', ['contact_personne_id']],
        'is_active' => ['Statut', ['is_active']],
    ];

    /**
     * Noms : par défaut toujours ceux de A, même vides (le nom affiché peut venir d'une fonction) ;
     * prendre ceux de B est un choix explicite. Les autres champs vides de A sont complétés par B.
     */
    private const NOMS = ['nom', 'prenom', 'nom_complet', 'surnom', 'raison_sociale', 'nom_commercial'];

    /** Confirmation renforcée du transfert d'un compte (décision 5, précision 1). */
    public const MOT_TRANSFERT_COMPTE = 'TRANSFERER';

    /** Tables dont les lignes de la fiche conservée sont comparées avant/après (effets induits). */
    private const INDUITS = [
        ContactsFusion::TYPE_PERSONNE => ['clients', 'livreurs', 'proprietaires', 'parrains', 'prestataires', 'entreprise_interlocuteurs'],
        ContactsFusion::TYPE_ENTREPRISE => ['clients', 'proprietaires', 'fournisseurs', 'prestataires', 'entreprise_interlocuteurs'],
    ];

    /** Fonctions qu'un compte peut recevoir par rattachement automatique (ADR 0056). */
    private const TABLES_RATTACHABLES = ['livreurs', 'proprietaires', 'parrains', 'prestataires', 'entreprise_interlocuteurs'];

    public function __construct(
        private readonly VisibiliteContacts $visibilite,
        private readonly AuditLogService $audit,
        private readonly RattachementAutomatiqueService $rattachement,
        private readonly ClientIdentiteSynchronisation $synchronisation,
    ) {}

    // ── Accès ───────────────────────────────────────────────────────────────

    /** Fiche active, de l'organisation et visible de l'utilisateur ; sinon 404 (rien n'est révélé). */
    public function charger(string $type, string $id, User $acteur): Personne|EntrepriseTierce
    {
        $fiche = $type === ContactsFusion::TYPE_PERSONNE ? Personne::find($id) : EntrepriseTierce::find($id);
        $visible = $fiche !== null
            && $fiche->organization_id === $acteur->organization_id
            && ($fiche instanceof Personne ? $this->visibilite->personneVisible($fiche, $acteur) : $this->visibilite->entrepriseVisible($fiche, $acteur));

        if (! $visible) {
            throw new NotFoundHttpException;
        }

        return $fiche;
    }

    // ── Simulation ──────────────────────────────────────────────────────────

    /**
     * Simulation complète, sans aucune écriture.
     *
     * @param  array<string, mixed>  $choix
     * @return array<string, mixed>
     */
    public function simuler(User $acteur, string $type, string $idA, string $idB, array $choix = []): array
    {
        if ($idA === $idB) {
            throw ValidationException::withMessages(['fiche_b' => 'Choisissez deux fiches différentes.']);
        }

        return $this->analyser($acteur, $type, $this->charger($type, $idA, $acteur), $this->charger($type, $idB, $acteur), $choix, false);
    }

    /**
     * @param  array<string, mixed>  $choix
     * @return array<string, mixed>
     */
    private function analyser(User $acteur, string $type, Personne|EntrepriseTierce $a, Personne|EntrepriseTierce $b, array $choix, bool $strict): array
    {
        $orgId = $a->organization_id;
        $references = ReferencesFusion::pour($type);
        $lignes = [];
        foreach ($references as $i => $reference) {
            $lignes[$i] = ['a' => ReferencesFusion::lignes($reference, $a->id), 'b' => ReferencesFusion::lignes($reference, $b->id)];
        }

        $blocages = [];
        $bloquer = function (string $code, string $message) use (&$blocages) {
            $blocages[$code.'|'.$message] = ['code' => $code, 'message' => $message];
        };

        $laisses = $this->elementsLaisses($references, $lignes);
        $this->controlerOrganisation($references, $lignes, $orgId, $bloquer);
        $this->controlerFonctions($type, $references, $lignes, $bloquer);
        $this->controlerVisibilite($acteur, $references, $lignes, $bloquer);
        $compte = $this->compte($acteur, $references, $lignes, $orgId, $bloquer);

        $champs = $this->champs($type, $a, $b, $choix, $strict);
        $telephones = $this->telephones($type, $a, $b, $choix, $strict);
        $principal = $type === ContactsFusion::TYPE_ENTREPRISE ? $this->interlocuteurPrincipal($references, $lignes, $laisses, $choix, $strict) : null;

        if ($type === ContactsFusion::TYPE_PERSONNE) {
            $this->controlerSynchronisationClients($a, $b, $champs, $telephones, $bloquer);
        }
        // CONTACT-063 : le numéro principal retenu ne doit être le secondaire d'aucune autre fiche.
        $autre = NumeroSecondaire::fiche($a::class, $orgId, $telephones['principal'], [$a->id, $b->id]);
        if ($autre !== null) {
            $bloquer('numero_secondaire', NumeroSecondaire::message($autre, $acteur));
        }

        $confirmations = ['fusion'];
        if ($compte !== null) {
            $confirmations[] = 'compte';
            if ($compte['cote'] === 'b') {
                $confirmations[] = 'transfert_compte';
            }
        }
        if ($telephones['retires'] !== []) {
            $confirmations[] = 'numeros_retires';
        }

        return [
            'type' => $type,
            'a' => $this->resume($type, $a, $acteur),
            'b' => $this->resume($type, $b, $acteur),
            'champs' => $champs,
            'telephones' => $telephones,
            'interlocuteur_principal' => $principal,
            'relations' => $this->relations($references, $lignes, $laisses),
            'compte' => $compte,
            'blocages' => array_values($blocages),
            'confirmations_requises' => $confirmations,
            'mot_transfert_compte' => self::MOT_TRANSFERT_COMPTE,
            'choix' => [
                ...array_map(fn (array $c) => $c['choix'], array_column($champs, null, 'cle')),
                'telephone' => $telephones['principal'],
                'telephone_secondaire' => $telephones['secondaire'],
                ...($principal !== null ? ['interlocuteur_principal' => $principal['choix']] : []),
            ],
            'empreinte' => $this->empreinte($a, $b, $lignes),
        ];
    }

    /**
     * Éléments de B déjà présents sur A, laissés sur la fiche archivée (rien n'est supprimé) :
     * catégorie déjà portée, ou interlocuteur déjà lié à A (fusion d'entreprises).
     *
     * @return array<string, true> "index|id" des lignes laissées
     */
    private function elementsLaisses(array $references, array $lignes): array
    {
        $laisses = [];
        foreach ($references as $i => $reference) {
            $cle = match ($reference['role']) {
                'categorie' => 'contact_categorie_id',
                'lien_interlocuteur' => 'personne_id',
                default => null,
            };
            if ($cle === null) {
                continue;
            }
            $presents = $lignes[$i]['a']->pluck($cle)->all();
            foreach ($lignes[$i]['b'] as $ligne) {
                if (in_array($ligne->{$cle}, $presents, true)) {
                    $laisses["{$i}|{$ligne->id}"] = true;
                }
            }
        }

        return $laisses;
    }

    private function controlerOrganisation(array $references, array $lignes, string $orgId, callable $bloquer): void
    {
        foreach ($references as $i => $reference) {
            foreach ([...$lignes[$i]['a'], ...$lignes[$i]['b']] as $ligne) {
                if (property_exists($ligne, 'organization_id') && $ligne->organization_id !== $orgId) {
                    $bloquer('organisation', 'Une donnée d\'une autre organisation (ou sans organisation) est liée à l\'une des fiches : fusion refusée.');
                }
            }
        }
    }

    private function controlerFonctions(string $type, array $references, array $lignes, callable $bloquer): void
    {
        foreach ($references as $i => $reference) {
            if ($reference['role'] === 'fonction' && $lignes[$i]['a']->isNotEmpty() && $lignes[$i]['b']->isNotEmpty()) {
                $libelle = PresentationContacts::LIBELLES_FONCTIONS[$reference['fonction']];
                $bloquer('F1', "Les deux fiches portent la fonction {$libelle} (fiches archivées comprises) : les fonctions ne sont jamais fusionnées.");
            }
            if ($type === ContactsFusion::TYPE_PERSONNE && $reference['role'] === 'interlocuteur') {
                $communes = array_intersect($lignes[$i]['a']->pluck('entreprise_tierce_id')->all(), $lignes[$i]['b']->pluck('entreprise_tierce_id')->all());
                if ($communes !== []) {
                    $bloquer('interlocuteur', 'Les deux fiches sont interlocuteurs de la même entreprise : fusion refusée.');
                }
            }
        }
    }

    /** Décision 6 : chaque fonction et chaque lien des deux fiches doit être consultable. */
    private function controlerVisibilite(User $acteur, array $references, array $lignes, callable $bloquer): void
    {
        foreach ($references as $i => $reference) {
            $tout = collect([...$lignes[$i]['a'], ...$lignes[$i]['b']]);
            if ($tout->isNotEmpty() && ! $this->toutVisible($acteur, $reference, $tout)) {
                $bloquer('visibilite', 'Une fonction ou un lien de ces fiches ne vous est pas visible : la fusion est refusée.');
            }
        }
    }

    /** @param  Collection<int, object>  $lignes */
    private function toutVisible(User $acteur, array $reference, Collection $lignes): bool
    {
        $entreprisesVisibles = fn (Collection $ids) => $this->visibilite->entreprisesVisibles($acteur)
            ->whereIn('entreprises_tierces.id', $ids->unique()->values())->count() === $ids->unique()->count();

        return match ($reference['role']) {
            'fonction' => $this->visibilite->peutLire($acteur, $reference['fonction'])
                && ($reference['fonction'] !== 'parrain'
                    || Parrain::withTrashed()->visiblesPar($acteur)->whereIn('parrains.id', $lignes->pluck('id'))->count() === $lignes->count()),
            'interlocuteur' => $entreprisesVisibles($lignes->pluck('entreprise_tierce_id')),
            'contact' => $entreprisesVisibles($lignes->pluck('id')),
            'lien_interlocuteur' => $this->visibilite->peutLire($acteur, 'interlocuteur'),
            default => true,
        };
    }

    /**
     * F2 et précision 1 : un compte de chaque côté bloque ; un seul compte demande une confirmation
     * (renforcée s'il est sur B) et la permission de modifier les utilisateurs.
     *
     * @return array<string, mixed>|null
     */
    private function compte(User $acteur, array $references, array $lignes, string $orgId, callable $bloquer): ?array
    {
        $i = array_search('compte', array_column($references, 'role'), true);
        if ($i === false) {
            return null;
        }

        $comptesA = $lignes[$i]['a'];
        $comptesB = $lignes[$i]['b'];
        if ($comptesA->isEmpty() && $comptesB->isEmpty()) {
            return null;
        }
        if ($comptesA->isNotEmpty() && $comptesB->isNotEmpty()) {
            $bloquer('F2', 'Les deux fiches ont chacune un compte utilisateur : fusion refusée.');
        }
        if (! $acteur->can('users.update')) {
            $bloquer('droits_compte', 'Une des fiches a un compte utilisateur : la fusion demande aussi la permission de modifier les utilisateurs.');
        }

        $cote = $comptesA->isNotEmpty() ? 'a' : 'b';
        $compte = User::with(['roles', 'authIdentities'])->find(($cote === 'a' ? $comptesA : $comptesB)->first()->id);

        $rattachables = [];
        foreach ($references as $j => $reference) {
            if (! in_array($reference['table'], self::TABLES_RATTACHABLES, true)) {
                continue;
            }
            foreach ([...$lignes[$j]['a'], ...$lignes[$j]['b']] as $ligne) {
                if (($ligne->user_id ?? null) === null) {
                    $rattachables[] = $this->libelleLigne($reference, $ligne, []);
                }
            }
        }

        return [
            'cote' => $cote,
            'nom' => $compte->personne?->nom_complet,
            'statut' => $compte->status,
            'actif' => (bool) $compte->is_active,
            'roles' => $compte->roles->map(fn ($role) => $role->label ?? $role->name)->values()->all(),
            // Les identifiants de connexion appartiennent au compte : la fusion ne les modifie jamais.
            'identifiants_connexion' => $compte->authIdentities->pluck('value')->values()->all(),
            'fonctions_rattachables' => $rattachables,
            'organisation_differente' => $compte->organization_id !== $orgId,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function champs(string $type, Model $a, Model $b, array $choix, bool $strict): array
    {
        $definitions = $type === ContactsFusion::TYPE_PERSONNE ? self::CHAMPS_PERSONNE : self::CHAMPS_ENTREPRISE;
        $resultat = [];

        foreach ($definitions as $cle => [$libelle, $colonnes]) {
            $valeursA = array_map(fn ($c) => $a->getRawOriginal($c), $colonnes);
            $valeursB = array_map(fn ($c) => $b->getRawOriginal($c), $colonnes);
            $different = ! $this->memesValeurs($valeursA, $valeursB);
            $videA = $this->vide($valeursA);

            $demande = $choix[$cle] ?? null;
            if ($demande !== null && ! in_array($demande, ['a', 'b'], true)) {
                if ($strict) {
                    throw ValidationException::withMessages(["choix.{$cle}" => 'Choix invalide.']);
                }
                $demande = null;
            }
            $completer = $videA && ! $this->vide($valeursB) && ! in_array($cle, self::NOMS, true);
            $retenu = $different ? ($demande ?? ($completer ? 'b' : 'a')) : 'a';

            $resultat[] = [
                'cle' => $cle,
                'libelle' => $libelle,
                'valeur_a' => $this->affichage($cle, $valeursA),
                'valeur_b' => $this->affichage($cle, $valeursB),
                'different' => $different,
                'choix' => $retenu,
                'colonnes' => $colonnes,
            ];
        }

        return $resultat;
    }

    /**
     * Décision 2 (F3) : un principal et un secondaire au plus, choisis parmi les numéros des deux
     * fiches ; les autres restent visibles dans la simulation et le journal.
     *
     * @return array{numeros: list<array{normalise: string, valeur: string, code_phone_pays: ?string, origine: string}>, principal: ?string, secondaire: ?string, retires: list<array<string, mixed>>}
     */
    private function telephones(string $type, Model $a, Model $b, array $choix, bool $strict): array
    {
        $numeros = [];
        foreach ([
            [$a, 'telephone', 'A — principal'], [$a, 'telephone_secondaire', 'A — secondaire'],
            [$b, 'telephone', 'B — principal'], [$b, 'telephone_secondaire', 'B — secondaire'],
        ] as [$fiche, $colonne, $origine]) {
            $valeur = trim((string) $fiche->getRawOriginal($colonne));
            $normalise = Personne::normaliserTelephone($valeur);
            if ($normalise === '' || isset($numeros[$normalise])) {
                continue;
            }
            $numeros[$normalise] = [
                'normalise' => $normalise,
                'valeur' => $valeur,
                'code_phone_pays' => $colonne === 'telephone' ? $fiche->getRawOriginal('code_phone_pays') : null,
                'origine' => $origine,
            ];
        }

        $principalDefaut = array_key_first($numeros);
        $principal = $this->choixNumero($choix, 'telephone', $numeros, $principalDefaut, $strict, false);
        if ($type === ContactsFusion::TYPE_PERSONNE && $principal === null && $numeros !== []) {
            $principal = $principalDefaut;
        }

        $autres = array_values(array_diff(array_keys($numeros), [$principal]));
        $secondaireA = Personne::normaliserTelephone((string) $a->getRawOriginal('telephone_secondaire'));
        $secondaireDefaut = $secondaireA !== '' && $secondaireA !== $principal ? $secondaireA : ($autres[0] ?? null);
        $secondaire = $this->choixNumero($choix, 'telephone_secondaire', $numeros, $secondaireDefaut, $strict, true);
        if ($secondaire === $principal) {
            if ($strict) {
                throw ValidationException::withMessages(['choix.telephone_secondaire' => 'Le numéro secondaire doit être différent du principal.']);
            }
            $secondaire = null;
        }

        return [
            'numeros' => array_values($numeros),
            'principal' => $principal,
            'secondaire' => $secondaire,
            'retires' => array_values(array_filter($numeros, fn ($n) => ! in_array($n['normalise'], [$principal, $secondaire], true))),
        ];
    }

    private function choixNumero(array $choix, string $cle, array $numeros, ?string $defaut, bool $strict, bool $aucunPermis): ?string
    {
        if (! array_key_exists($cle, $choix)) {
            return $defaut;
        }
        $demande = $choix[$cle];
        if (($demande === null || $demande === '') && $aucunPermis) {
            return null;
        }
        if (is_string($demande) && isset($numeros[$demande])) {
            return $demande;
        }
        if ($strict) {
            throw ValidationException::withMessages(["choix.{$cle}" => 'Choix de numéro invalide.']);
        }

        return $defaut;
    }

    /**
     * Décision 8 : si A et B ont chacune un interlocuteur principal (transféré), un seul le reste.
     *
     * @return array<string, mixed>|null
     */
    private function interlocuteurPrincipal(array $references, array $lignes, array $laisses, array $choix, bool $strict): ?array
    {
        $i = array_search('lien_interlocuteur', array_column($references, 'role'), true);
        $principalA = $lignes[$i]['a']->first(fn ($l) => (bool) $l->est_principal);
        $principalB = $lignes[$i]['b']->first(fn ($l) => (bool) $l->est_principal && ! isset($laisses["{$i}|{$l->id}"]));
        if ($principalA === null || $principalB === null) {
            return null;
        }

        $noms = Personne::withTrashed()->whereIn('id', [$principalA->personne_id, $principalB->personne_id])->get()->keyBy('id');
        $options = [
            ['id' => $principalA->id, 'libelle' => ($noms[$principalA->personne_id]?->nom_complet ?? '—').' (A)'],
            ['id' => $principalB->id, 'libelle' => ($noms[$principalB->personne_id]?->nom_complet ?? '—').' (B)'],
        ];
        $demande = $choix['interlocuteur_principal'] ?? null;
        if ($demande !== null && ! in_array($demande, [$principalA->id, $principalB->id], true)) {
            if ($strict) {
                throw ValidationException::withMessages(['choix.interlocuteur_principal' => 'Choix invalide.']);
            }
            $demande = null;
        }

        return ['options' => $options, 'choix' => $demande ?? $principalA->id];
    }

    /** R1 active : l'identité retenue sera recopiée sur le client ; un conflit d'unicité bloque. */
    private function controlerSynchronisationClients(Personne $a, Personne $b, array $champs, array $telephones, callable $bloquer): void
    {
        if (! ClientIdentiteSynchronisation::estActivePour($a->organization_id)) {
            return;
        }

        $email = collect($champs)->firstWhere('cle', 'email');
        $emailFinal = $email['choix'] === 'b' ? $b->getRawOriginal('email') : $a->getRawOriginal('email');
        $numero = collect($telephones['numeros'])->firstWhere('normalise', $telephones['principal']);
        $clients = DB::table('clients')->whereIn('personne_id', [$a->id, $b->id])->whereNull('deleted_at')->pluck('id');

        foreach ($clients as $clientId) {
            try {
                if ($numero !== null) {
                    ClientUniqueness::assertPhoneUniqueInOrg($numero['valeur'], $a->organization_id, $clientId);
                }
                if (! empty($emailFinal)) {
                    ClientUniqueness::assertEmailUniqueInOrg($emailFinal, $a->organization_id, $clientId);
                }
            } catch (ValidationException) {
                $bloquer('R1', 'L\'identité retenue serait recopiée sur le client, mais un autre client porte déjà ce téléphone ou cet e-mail : fusion refusée.');
            }
        }
    }

    // ── Exécution ───────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $choix
     * @param  array<string, mixed>  $confirmations
     */
    public function executer(User $acteur, string $type, string $idA, string $idB, array $choix, array $confirmations, string $empreinte, string $jeton): ContactsFusion
    {
        if ($idA === $idB) {
            throw ValidationException::withMessages(['fiche_b' => 'Choisissez deux fiches différentes.']);
        }
        $deja = ContactsFusion::where('jeton', $jeton)->first();
        if ($deja !== null) {
            return $this->dejaFaite($deja, $acteur);
        }

        try {
            return DB::transaction(fn () => $this->executerVerrouille($acteur, $type, $idA, $idB, $choix, $confirmations, $empreinte, $jeton));
        } catch (UniqueConstraintViolationException $e) {
            $deja = ContactsFusion::where('jeton', $jeton)->first();
            if ($deja !== null) {
                return $this->dejaFaite($deja, $acteur);
            }

            throw ValidationException::withMessages(['fusion' => 'Une donnée vient d\'être modifiée par ailleurs : relancez la simulation.']);
        }
    }

    private function dejaFaite(ContactsFusion $fusion, User $acteur): ContactsFusion
    {
        if ($fusion->organization_id !== $acteur->organization_id) {
            throw new NotFoundHttpException;
        }

        return $fusion;
    }

    private function executerVerrouille(User $acteur, string $type, string $idA, string $idB, array $choix, array $confirmations, string $empreinte, string $jeton): ContactsFusion
    {
        $classe = $type === ContactsFusion::TYPE_PERSONNE ? Personne::class : EntrepriseTierce::class;
        // Verrou dans un ordre fixe : deux fusions croisées ne s'interbloquent pas.
        $verrouillees = $classe::withTrashed()->whereIn('id', [$idA, $idB])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        foreach ([$idA, $idB] as $id) {
            if ($verrouillees->get($id)?->trashed() && $verrouillees->get($id)->organization_id === $acteur->organization_id) {
                throw ValidationException::withMessages(['fusion' => 'Une des deux fiches vient d\'être archivée ou fusionnée : relancez la recherche.']);
            }
        }

        $a = $this->charger($type, $idA, $acteur);
        $b = $this->charger($type, $idB, $acteur);
        $simulation = $this->analyser($acteur, $type, $a, $b, $choix, true);

        if ($simulation['blocages'] !== []) {
            throw ValidationException::withMessages(['fusion' => $simulation['blocages'][0]['message']]);
        }
        if (! hash_equals($simulation['empreinte'], $empreinte)) {
            throw ValidationException::withMessages(['fusion' => 'Les fiches ont été modifiées depuis la simulation : relancez-la avant de confirmer.']);
        }
        $this->controlerConfirmations($simulation['confirmations_requises'], $confirmations);

        $fusion = ContactsFusion::create([
            'organization_id' => $a->organization_id,
            'type' => $type,
            'fiche_conservee_id' => $a->id,
            'fiche_absorbee_id' => $b->id,
            'statut' => ContactsFusion::EXECUTEE,
            'jeton' => $jeton,
            'acteur_id' => $acteur->id,
            'simulation' => $simulation,
            'choix' => $simulation['choix'],
            'confirmations' => array_intersect_key($confirmations, array_flip($simulation['confirmations_requises'])),
        ]);
        $journal = new JournalFusion($fusion);
        $tableFiche = ReferencesFusion::tableFiche($type);
        $nomB = $simulation['b']['nom'];

        // 1. Le numéro de B est libéré (unicité par organisation, archivées comprises).
        if ($b->getRawOriginal('telephone_normalise') !== null) {
            DB::table($tableFiche)->where('id', $b->id)->update(['telephone_normalise' => null]);
            $journal->ecrire(ContactsFusionOperation::CHAMP, $tableFiche, $b->id, 'telephone_normalise', $b->getRawOriginal('telephone_normalise'), null, "Numéro de {$nomB} libéré");
        }
        // Son numéro secondaire aussi (CONTACT-063) : il peut devenir un numéro de A.
        if ($b->getRawOriginal('telephone_secondaire_normalise') !== null) {
            DB::table($tableFiche)->where('id', $b->id)->update(['telephone_secondaire_normalise' => null]);
            $journal->ecrire(ContactsFusionOperation::CHAMP, $tableFiche, $b->id, 'telephone_secondaire_normalise', $b->getRawOriginal('telephone_secondaire_normalise'), null, "Numéro secondaire de {$nomB} libéré");
        }
        foreach ($simulation['telephones']['retires'] as $numero) {
            $journal->ecrire(ContactsFusionOperation::LAISSE, $tableFiche, $b->id, 'telephone', $numero['valeur'], null, "Numéro {$numero['valeur']} ({$numero['origine']}) retiré de la fiche conservée, gardé au journal");
        }

        // 2. Liens transférés de B vers A.
        $references = ReferencesFusion::pour($type);
        $lignes = [];
        foreach ($references as $i => $reference) {
            $lignes[$i] = ['a' => ReferencesFusion::lignes($reference, $a->id), 'b' => ReferencesFusion::lignes($reference, $b->id)];
        }
        $laisses = $this->elementsLaisses($references, $lignes);
        $noms = $this->nomsLies($references, $lignes);
        foreach ($references as $i => $reference) {
            foreach ($lignes[$i]['b'] as $ligne) {
                $libelle = $this->libelleLigne($reference, $ligne, $noms);
                if (isset($laisses["{$i}|{$ligne->id}"])) {
                    $journal->ecrire(ContactsFusionOperation::LAISSE, $reference['table'], $ligne->id, $reference['colonne'], $b->id, $b->id, "{$libelle} : déjà présent sur la fiche conservée, laissé sur la fiche archivée");

                    continue;
                }
                DB::table($reference['table'])->where('id', $ligne->id)->update([$reference['colonne'] => $a->id]);
                $journal->ecrire(ContactsFusionOperation::LIEN, $reference['table'], $ligne->id, $reference['colonne'], $b->id, $a->id, "{$libelle} transféré");
            }
        }

        // 3. Interlocuteur principal unique (décision 8).
        if ($simulation['interlocuteur_principal'] !== null) {
            foreach ($simulation['interlocuteur_principal']['options'] as $option) {
                if ($option['id'] !== $simulation['interlocuteur_principal']['choix']) {
                    DB::table('entreprise_interlocuteurs')->where('id', $option['id'])->update(['est_principal' => false]);
                    $journal->ecrire(ContactsFusionOperation::CHAMP, 'entreprise_interlocuteurs', $option['id'], 'est_principal', '1', '0', "{$option['libelle']} n'est plus l'interlocuteur principal");
                }
            }
        }

        // 4. Effets induits : état des fonctions de A avant l'enregistrement de son identité.
        $avant = $this->instantane($type, $a->id);
        $compte = $type === ContactsFusion::TYPE_PERSONNE ? User::where('personne_id', $a->id)->first() : null;
        $rolesAvant = $compte?->getRoleNames()->all() ?? [];

        // 5. Champs arbitrés et téléphones de A.
        $this->appliquerChoix($type, $a, $b, $simulation);
        $originaux = $a->getRawOriginal();
        $modifies = array_diff(array_keys($a->getDirty()), ['updated_at']);
        try {
            // Garde CONTACT-063 du modèle : le numéro principal retenu n'est le secondaire d'aucune autre fiche.
            $a->save();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['fusion' => 'Le numéro retenu vient d\'être enregistré pour une autre fiche : relancez la simulation.']);
        }
        $libelles = $this->libellesColonnes($type);
        foreach ($modifies as $colonne) {
            $journal->ecrire(ContactsFusionOperation::CHAMP, $tableFiche, $a->id, $colonne, $originaux[$colonne] ?? null, $a->getRawOriginal($colonne), ($libelles[$colonne] ?? $colonne).' de la fiche conservée');
        }

        // 6. Copies d'identité portées par les fonctions (mêmes règles qu'une modification d'identité).
        if ($type === ContactsFusion::TYPE_PERSONNE) {
            if (ClientIdentiteSynchronisation::estActivePour($a->organization_id)) {
                $this->synchronisation->depuisPersonne($a->fresh());
            }
        } else {
            DB::table('proprietaires')->where('entreprise_tierce_id', $a->id)->update(['raison_sociale' => $a->raison_sociale]);
            DB::table('clients')->where('entreprise_tierce_id', $a->id)->update(['nom_complet' => $a->raison_sociale]);
        }

        // 7. Rattachement au compte (ADR 0056), confirmé à l'étape de simulation (F2).
        if ($compte !== null) {
            $this->rattachement->rattacherPersonne($a->id, 'fusion');
        }

        foreach ($this->differences($avant, $this->instantane($type, $a->id)) as [$table, $id, $colonne, $valeurAvant, $valeurApres]) {
            $journal->ecrire(ContactsFusionOperation::INDUIT, $table, $id, $colonne, $valeurAvant, $valeurApres, $this->libelleInduit($table, $colonne));
        }
        if ($compte !== null) {
            foreach (array_diff($compte->fresh()->getRoleNames()->all(), $rolesAvant) as $role) {
                $journal->ecrire(ContactsFusionOperation::INDUIT, 'model_has_roles', $compte->id, 'role', null, $role, "Rôle « {$role} » ajouté au compte par le rattachement");
            }
        }

        // 8. Archivage de B, avec le lien vers A (aucune suppression physique).
        $maintenant = now()->format('Y-m-d H:i:s');
        DB::table($tableFiche)->where('id', $b->id)->update(['fusionnee_dans_id' => $a->id, 'fusionnee_le' => $maintenant, 'deleted_at' => $maintenant]);
        $journal->ecrire(ContactsFusionOperation::ARCHIVAGE, $tableFiche, $b->id, 'fusionnee_dans_id', null, $a->id, "{$nomB} : lien vers la fiche conservée");
        $journal->ecrire(ContactsFusionOperation::ARCHIVAGE, $tableFiche, $b->id, 'fusionnee_le', null, $maintenant, "{$nomB} : date de fusion");
        $journal->ecrire(ContactsFusionOperation::ARCHIVAGE, $tableFiche, $b->id, 'deleted_at', null, $maintenant, "{$nomB} archivée");

        // 9. Plus aucun lien vers B, hors éléments laissés volontairement.
        foreach ($references as $i => $reference) {
            $restants = ReferencesFusion::lignes($reference, $b->id)->reject(fn ($l) => isset($laisses["{$i}|{$l->id}"]));
            if ($restants->isNotEmpty()) {
                throw new RuntimeException("Fusion interrompue : la table {$reference['table']} pointe encore vers la fiche absorbée.");
            }
        }

        $meta = ['origine' => 'fusion', 'fusion_id' => $fusion->id, 'fiche_conservee_id' => $a->id, 'fiche_absorbee_id' => $b->id];
        $this->audit->record($a, AuditEvent::UPDATED, $acteur, null, null, [...$meta, 'champs_modifies' => array_values($modifies)]);
        $this->audit->record($b->fresh() ?? $b, AuditEvent::DELETED, $acteur, null, null, $meta);

        return $fusion;
    }

    /** @param  list<string>  $requises */
    private function controlerConfirmations(array $requises, array $confirmations): void
    {
        $messages = [
            'fusion' => 'Confirmez explicitement la fusion.',
            'compte' => 'Confirmez les conséquences sur le compte utilisateur.',
            'numeros_retires' => 'Confirmez que les numéros non retenus quittent la fiche (ils restent au journal).',
            'transfert_compte' => 'Saisissez '.self::MOT_TRANSFERT_COMPTE.' pour confirmer le transfert du compte.',
        ];

        foreach ($requises as $cle) {
            $ok = $cle === 'transfert_compte'
                ? mb_strtoupper(trim((string) ($confirmations[$cle] ?? ''))) === self::MOT_TRANSFERT_COMPTE
                : ($confirmations[$cle] ?? false) === true;
            if (! $ok) {
                throw ValidationException::withMessages(["confirmations.{$cle}" => $messages[$cle]]);
            }
        }
    }

    private function appliquerChoix(string $type, Model $a, Model $b, array $simulation): void
    {
        foreach ($simulation['champs'] as $champ) {
            if ($champ['different'] && $champ['choix'] === 'b') {
                foreach ($champ['colonnes'] as $colonne) {
                    $a->forceFill([$colonne => $b->getRawOriginal($colonne)]);
                }
            }
        }

        $numeros = collect($simulation['telephones']['numeros'])->keyBy('normalise');
        $principal = $numeros->get($simulation['telephones']['principal']);
        $secondaire = $numeros->get($simulation['telephones']['secondaire']);
        if ($principal !== null) {
            $a->forceFill([
                'telephone' => $principal['valeur'],
                'telephone_normalise' => $principal['normalise'],
                'code_phone_pays' => $principal['code_phone_pays'] ?? $a->getRawOriginal('code_phone_pays'),
            ]);
        } elseif ($type === ContactsFusion::TYPE_ENTREPRISE) {
            $a->forceFill(['telephone' => null, 'telephone_normalise' => null]);
        }
        $a->telephone_secondaire = $secondaire['valeur'] ?? null;
    }

    // ── Instantanés des effets induits ──────────────────────────────────────

    /** @return array<string, array<string, array<string, mixed>>> table => id => colonnes */
    private function instantane(string $type, string $ficheId): array
    {
        $colonne = $type === ContactsFusion::TYPE_PERSONNE ? 'personne_id' : 'entreprise_tierce_id';
        $resultat = [];
        foreach (self::INDUITS[$type] as $table) {
            foreach (DB::table($table)->where($colonne, $ficheId)->get() as $ligne) {
                $resultat[$table][$ligne->id] = array_diff_key((array) $ligne, ['updated_at' => true]);
            }
        }

        return $resultat;
    }

    /** @return list<array{string, string, string, mixed, mixed}> */
    private function differences(array $avant, array $apres): array
    {
        $resultat = [];
        foreach ($apres as $table => $lignes) {
            foreach ($lignes as $id => $colonnes) {
                foreach ($colonnes as $colonne => $valeur) {
                    $ancienne = $avant[$table][$id][$colonne] ?? null;
                    if (! self::egales($ancienne, $valeur)) {
                        $resultat[] = [$table, (string) $id, $colonne, $ancienne, $valeur];
                    }
                }
            }
        }

        return $resultat;
    }

    private function libelleInduit(string $table, string $colonne): string
    {
        $tables = [
            'clients' => 'client', 'livreurs' => 'livreur', 'proprietaires' => 'propriétaire', 'parrains' => 'parrain',
            'prestataires' => 'prestataire', 'fournisseurs' => 'fournisseur', 'entreprise_interlocuteurs' => 'interlocuteur',
        ];

        return $colonne === 'user_id'
            ? 'Fiche '.($tables[$table] ?? $table).' rattachée au compte'
            : 'Fiche '.($tables[$table] ?? $table)." : {$colonne} recopié de la fiche conservée";
    }

    // ── Présentation ────────────────────────────────────────────────────────

    /**
     * Nom affiché comme sur la fiche Contacts : pour une personne, celui de ses fonctions visibles
     * quand la Personne n'en porte pas (livreur créé par sa fiche, par exemple).
     *
     * @return array{id: string, nom: string, lien: string, telephone: ?string}
     */
    public function resume(string $type, Personne|EntrepriseTierce $fiche, User $lecteur): array
    {
        if ($fiche instanceof EntrepriseTierce) {
            return ['id' => $fiche->id, 'nom' => (string) $fiche->raison_sociale, 'lien' => "/backoffice/contacts/entreprises/{$fiche->id}", 'telephone' => $fiche->telephone];
        }

        $fonctions = $this->visibilite->fonctionsDesPersonnes(collect([$fiche]), $lecteur)[$fiche->id] ?? [];

        return [
            'id' => $fiche->id,
            'nom' => (new PresentationContacts($fiche->organization_id))->nomPersonne($fiche, $fonctions),
            'lien' => "/backoffice/contacts/personnes/{$fiche->id}",
            'telephone' => $fiche->telephone,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function relations(array $references, array $lignes, array $laisses): array
    {
        $noms = $this->nomsLies($references, $lignes);
        $resultat = [];
        foreach ($references as $i => $reference) {
            foreach (['a', 'b'] as $cote) {
                foreach ($lignes[$i][$cote] as $ligne) {
                    $resultat[] = [
                        'groupe' => $this->groupe($reference['role']),
                        'libelle' => $this->libelleLigne($reference, $ligne, $noms),
                        'cote' => $cote,
                        'action' => $cote === 'a' ? 'conservee' : (isset($laisses["{$i}|{$ligne->id}"]) ? 'laissee' : 'transferee'),
                        'archivee' => ($ligne->deleted_at ?? null) !== null,
                    ];
                }
            }
        }

        return $resultat;
    }

    private function groupe(string $role): string
    {
        return match ($role) {
            'fonction' => 'Fonctions',
            'compte' => 'Compte utilisateur',
            'interlocuteur', 'lien_interlocuteur' => 'Interlocuteurs',
            'contact' => 'Contact d\'entreprise',
            'categorie' => 'Catégories',
            'piece' => 'Pièces d\'identité',
            default => 'Autres liens',
        };
    }

    /** Noms des entreprises, personnes et catégories référencées, chargés en une fois. */
    private function nomsLies(array $references, array $lignes): array
    {
        $entreprises = [];
        $personnes = [];
        $categories = [];
        foreach ($references as $i => $reference) {
            foreach ([...$lignes[$i]['a'], ...$lignes[$i]['b']] as $ligne) {
                match ($reference['role']) {
                    'interlocuteur' => $entreprises[] = $ligne->entreprise_tierce_id,
                    'lien_interlocuteur' => $personnes[] = $ligne->personne_id,
                    'categorie' => $categories[] = $ligne->contact_categorie_id,
                    default => null,
                };
            }
        }

        return [
            'entreprises' => EntrepriseTierce::withTrashed()->whereIn('id', array_unique($entreprises))->pluck('raison_sociale', 'id')->all(),
            'personnes' => Personne::withTrashed()->whereIn('id', array_unique($personnes))->get()->mapWithKeys(fn (Personne $p) => [$p->id => $p->nom_complet])->all(),
            'categories' => CategorieLibre::whereIn('id', array_unique($categories))->pluck('libelle', 'id')->all(),
        ];
    }

    private function libelleLigne(array $reference, object $ligne, array $noms): string
    {
        $archive = ($ligne->deleted_at ?? null) !== null ? ' (archivée)' : '';

        return match ($reference['role']) {
            'fonction' => PresentationContacts::LIBELLES_FONCTIONS[$reference['fonction']]
                .(($detail = $ligne->nom_complet ?? $ligne->reference ?? $ligne->matricule ?? $ligne->raison_sociale ?? null) ? " — {$detail}" : '').$archive,
            'compte' => 'Compte utilisateur'.(($ligne->matricule ?? null) ? " ({$ligne->matricule})" : ''),
            'interlocuteur' => 'Interlocuteur de '.($noms['entreprises'][$ligne->entreprise_tierce_id] ?? 'une entreprise'),
            'lien_interlocuteur' => 'Interlocuteur : '.($noms['personnes'][$ligne->personne_id] ?? '—'),
            'contact' => 'Contact principal de '.($ligne->raison_sociale ?? 'une entreprise').$archive,
            'categorie' => 'Catégorie « '.($noms['categories'][$ligne->contact_categorie_id] ?? '—').' »',
            'piece' => 'Pièce d\'identité'.(($ligne->type_piece ?? null) ? " ({$ligne->type_piece})" : '').$archive,
            default => $reference['table'],
        };
    }

    /** @return array<string, string> colonne => libellé */
    private function libellesColonnes(string $type): array
    {
        $libelles = [
            'telephone' => 'Téléphone principal', 'telephone_normalise' => 'Téléphone principal (normalisé)',
            'telephone_secondaire' => 'Téléphone secondaire', 'telephone_secondaire_normalise' => 'Téléphone secondaire (normalisé)',
            'code_phone_pays' => 'Indicatif',
        ];
        foreach ($type === ContactsFusion::TYPE_PERSONNE ? self::CHAMPS_PERSONNE : self::CHAMPS_ENTREPRISE as [$libelle, $colonnes]) {
            foreach ($colonnes as $colonne) {
                $libelles[$colonne] = $libelle;
            }
        }

        return $libelles;
    }

    private function affichage(string $cle, array $valeurs): ?string
    {
        if ($this->vide($valeurs)) {
            return null;
        }

        return match ($cle) {
            'contact_personne_id' => Personne::withTrashed()->find($valeurs[0])?->nom_complet ?? '—',
            'is_active' => (bool) $valeurs[0] ? 'Active' : 'Inactive',
            'pays' => trim(($valeurs[0] ?? '').(($valeurs[1] ?? null) ? " ({$valeurs[1]})" : '')),
            'position' => implode(', ', array_map(fn ($v) => (string) $v, $valeurs)),
            default => (string) $valeurs[0],
        };
    }

    // ── Empreinte et comparaisons ───────────────────────────────────────────

    /** Empreinte des deux fiches et de tous leurs liens : toute modification concurrente la change. */
    private function empreinte(Model $a, Model $b, array $lignes): string
    {
        $donnees = [$this->attributsTries($a->getRawOriginal()), $this->attributsTries($b->getRawOriginal())];
        foreach ($lignes as $paire) {
            $donnees[] = $paire['a']->map(fn ($l) => (array) $l)->all();
            $donnees[] = $paire['b']->map(fn ($l) => (array) $l)->all();
        }

        return hash('sha256', json_encode($donnees, JSON_UNESCAPED_UNICODE));
    }

    private function attributsTries(array $attributs): array
    {
        ksort($attributs);

        return array_map(fn ($v) => $v === null ? null : (string) $v, $attributs);
    }

    private function memesValeurs(array $a, array $b): bool
    {
        foreach ($a as $i => $valeur) {
            if (! self::egales($valeur, $b[$i] ?? null)) {
                return false;
            }
        }

        return true;
    }

    private function vide(array $valeurs): bool
    {
        return collect($valeurs)->every(fn ($v) => $v === null || trim((string) $v) === '');
    }

    /** Égalité de valeurs brutes : vide = vide, nombres comparés comme nombres. */
    public static function egales(mixed $a, mixed $b): bool
    {
        $a = $a === null || (is_string($a) && trim($a) === '') ? null : $a;
        $b = $b === null || (is_string($b) && trim($b) === '') ? null : $b;
        if ($a === null || $b === null) {
            return $a === $b;
        }
        if (is_bool($a) || is_bool($b)) {
            return (int) $a === (int) $b;
        }
        if (is_numeric($a) && is_numeric($b)) {
            return (float) $a === (float) $b;
        }

        return (string) $a === (string) $b;
    }
}
