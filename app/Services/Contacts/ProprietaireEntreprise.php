<?php

namespace App\Services\Contacts;

use App\Enums\AuditEvent;
use App\Enums\TypeProprietaire;
use App\Models\EntrepriseTierce;
use App\Models\Fournisseur;
use App\Models\Personne;
use App\Models\Prestataire;
use App\Models\Proprietaire;
use App\Models\User;
use App\Services\AuditLogService;
use App\Support\Contacts\MessagesContacts;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Propriétaire de type entreprise relié à sa fiche Entreprise (ADR 0060, lot 5, CONTACT-027 à 032).
 *
 * - `personne_id` reste le contact du propriétaire (D1) ; il devient interlocuteur de l'entreprise,
 *   principal seulement si elle n'en a aucun (D9), sans doublon de lien.
 * - Jamais de rattachement d'office : une entreprise qui correspond (même téléphone, même NIF, même
 *   raison sociale normalisée) est présentée, l'utilisateur choisit ou crée explicitement.
 * - D6 : téléphone ou NIF d'une entreprise non visible du lecteur → message neutre et arrêt ; une
 *   raison sociale seule ne révèle rien (l'entreprise n'est pas proposée).
 * - D5 : la fiche Entreprise fait foi ; `proprietaires.raison_sociale` en est la copie (recopiée au
 *   lien, puis par EntrepriseTierce::booted() à chaque changement, quel que soit l'écran).
 * - D7 : modifier l'identité d'une entreprise qui porte une autre fonction exige `contacts.update`
 *   et une confirmation explicite, en plus de `proprietaires.update`.
 * - D8 : retirer le lien ne supprime ni l'entreprise ni ses interlocuteurs.
 * - Un seul propriétaire par entreprise, contrôlé sous verrou (entreprise puis propriétaire).
 * - Journal d'audit : identifiants et indicateurs, jamais de donnée personnelle.
 */
final class ProprietaireEntreprise
{
    public const CHOIX_NOUVELLE = 'nouvelle';

    public const ORIGINE_FORMULAIRE = 'formulaire_proprietaire';

    public const ORIGINE_REPRISE = 'reprise_proprietaires_entreprises';

    public const MESSAGE_CHOIX = 'Des entreprises correspondent à cette saisie : choisissez-en une ou créez une nouvelle entreprise.';

    public const MESSAGE_DROIT_PARTAGEE = "Cette entreprise porte aussi d'autres fonctions : modifier son identité demande le droit « Contacts — modifier l'identité d'une fiche ».";

    public const MESSAGE_CONFIRMATION = "Cette entreprise porte aussi d'autres fonctions : confirmez que la modification de son identité vaut pour toutes.";

    public function __construct(
        private readonly VisibiliteContacts $visibilite,
        private readonly InterlocuteurService $interlocuteurs,
        private readonly AuditLogService $journal,
    ) {}

    // ── Correspondances ─────────────────────────────────────────────────────

    /** Minuscules, sans accents ni ponctuation : « Société Kaloum S.A.R.L » = « societe kaloum s a r l ». */
    public static function normaliserRaisonSociale(?string $valeur): string
    {
        $v = preg_replace('/[^a-z0-9]+/', ' ', Str::ascii(mb_strtolower(trim((string) $valeur)))) ?? '';

        return trim($v);
    }

    public static function normaliserNif(?string $valeur): string
    {
        return mb_strtoupper(preg_replace('/[\s.\-\/]+/', '', trim((string) $valeur)) ?? '');
    }

    /**
     * Entreprises de l'organisation qui correspondent, avec les critères qui ont joué. Sans filtre de
     * visibilité : correspondancesPour() l'applique pour un utilisateur.
     *
     * @param  array{raison_sociale?: ?string, telephone?: ?string, identifiant_fiscal?: ?string}  $identite
     * @return array<string, list<'telephone'|'identifiant_fiscal'|'raison_sociale'>>
     */
    public function correspondancesBrutes(string $organizationId, array $identite): array
    {
        $telephone = Personne::normaliserTelephone((string) ($identite['telephone'] ?? ''));
        $nif = self::normaliserNif($identite['identifiant_fiscal'] ?? null);
        $raison = self::normaliserRaisonSociale($identite['raison_sociale'] ?? null);
        $resultat = [];

        EntrepriseTierce::where('organization_id', $organizationId)
            ->select(['id', 'raison_sociale', 'identifiant_fiscal', 'telephone_normalise'])
            ->chunkById(500, function ($entreprises) use ($telephone, $nif, $raison, &$resultat) {
                foreach ($entreprises as $e) {
                    $criteres = array_values(array_filter([
                        $telephone !== '' && $e->telephone_normalise === $telephone ? 'telephone' : null,
                        $nif !== '' && self::normaliserNif($e->identifiant_fiscal) === $nif ? 'identifiant_fiscal' : null,
                        $raison !== '' && self::normaliserRaisonSociale($e->raison_sociale) === $raison ? 'raison_sociale' : null,
                    ]));
                    if ($criteres !== []) {
                        $resultat[(string) $e->id] = $criteres;
                    }
                }
            });

        return $resultat;
    }

    /**
     * Ce que l'utilisateur peut voir des correspondances (D6). `bloque` : un téléphone ou un NIF
     * appartient à une entreprise qu'il ne peut pas consulter — rien d'autre n'est renvoyé.
     *
     * @param  array{raison_sociale?: ?string, telephone?: ?string, identifiant_fiscal?: ?string}  $identite
     * @return array{bloque: ?array{champ: string, message: string}, correspondances: list<array<string, mixed>>}
     */
    public function correspondancesPour(User $lecteur, array $identite, ?string $proprietaireId = null): array
    {
        $brutes = $this->correspondancesBrutes((string) $lecteur->organization_id, $identite);
        if ($brutes === []) {
            return ['bloque' => null, 'correspondances' => []];
        }

        $visibles = $this->visibilite->entreprisesVisibles($lecteur)
            ->whereIn('entreprises_tierces.id', array_keys($brutes))
            ->pluck('entreprises_tierces.id')
            ->flip()
            ->all();

        foreach ($brutes as $id => $criteres) {
            if (isset($visibles[$id])) {
                continue;
            }
            if (in_array('telephone', $criteres, true)) {
                return ['bloque' => ['champ' => 'telephone_entreprise', 'message' => MessagesContacts::NUMERO_NON_VISIBLE], 'correspondances' => []];
            }
            if (in_array('identifiant_fiscal', $criteres, true)) {
                return ['bloque' => ['champ' => 'identifiant_fiscal', 'message' => MessagesContacts::NIF_NON_VISIBLE], 'correspondances' => []];
            }
        }

        $ids = array_keys(array_intersect_key($brutes, $visibles));
        $dejaProprietaire = Proprietaire::whereIn('entreprise_tierce_id', $ids)
            ->when($proprietaireId, fn ($q, string $id) => $q->whereKeyNot($id))
            ->pluck('entreprise_tierce_id')
            ->flip()
            ->all();

        $correspondances = EntrepriseTierce::whereIn('id', $ids)
            ->orderBy('raison_sociale')
            ->get()
            ->map(fn (EntrepriseTierce $e) => [
                'id' => $e->id,
                'raison_sociale' => $e->raison_sociale,
                'identifiant_fiscal' => $e->identifiant_fiscal,
                'telephone' => $e->telephone,
                'ville' => $e->ville,
                'is_active' => $e->is_active,
                'criteres' => $brutes[$e->id],
                'deja_proprietaire' => isset($dejaProprietaire[$e->id]),
                'selectionnable' => $e->is_active && ! isset($dejaProprietaire[$e->id]),
            ])
            ->values()
            ->all();

        return ['bloque' => null, 'correspondances' => $correspondances];
    }

    // ── Formulaire Propriétaire ─────────────────────────────────────────────

    /**
     * Propriétaire entreprise pas encore relié (création, passage au type entreprise, ancienne fiche
     * sans lien) : relie l'entreprise choisie, ou en crée une (D3). À appeler dans une transaction.
     * Une entreprise choisie fait foi : la saisie (raison sociale, téléphone, NIF) ne la modifie pas.
     *
     * @param  array{raison_sociale: string, telephone?: ?string, identifiant_fiscal?: ?string}  $identite  téléphone au format international
     * @return array{entreprise: EntrepriseTierce, entreprise_creee: bool, valeurs_avant: array{entreprise_tierce_id: ?string, raison_sociale: ?string}, interlocuteur_id: string, interlocuteur_etat_avant: string, principal_pose: bool}
     */
    public function rattacherDepuisFormulaire(Proprietaire $proprietaire, array $identite, ?string $choix, User $acteur): array
    {
        $this->assertMemeOrganisation($proprietaire->organization_id, $acteur);

        $vue = $this->correspondancesPour($acteur, $identite, $proprietaire->id);
        if ($vue['bloque'] !== null) {
            throw ValidationException::withMessages([$vue['bloque']['champ'] => $vue['bloque']['message']]);
        }

        $choix = trim((string) $choix);
        if ($choix === '' && $vue['correspondances'] !== []) {
            throw ValidationException::withMessages(['entreprise_choix' => self::MESSAGE_CHOIX]);
        }

        if ($choix === '' || $choix === self::CHOIX_NOUVELLE) {
            $memeNumero = collect($vue['correspondances'])->first(fn (array $c) => in_array('telephone', $c['criteres'], true));
            if ($memeNumero !== null) {
                throw ValidationException::withMessages(['telephone_entreprise' => "Ce numéro est déjà celui d'une entreprise de la liste : choisissez-la plutôt que d'en créer une seconde."]);
            }
            $entreprise = $this->creerEntreprise($proprietaire, $identite);

            return ['entreprise' => $entreprise, ...$this->lier($proprietaire, $entreprise, $acteur, true, self::ORIGINE_FORMULAIRE)];
        }

        $entreprise = $this->entrepriseChoisie($choix, $acteur);

        return ['entreprise' => $entreprise, ...$this->lier($proprietaire, $entreprise, $acteur, false, self::ORIGINE_FORMULAIRE)];
    }

    /** L'entreprise porte-t-elle une autre fonction que ce propriétaire (active ou non) ? */
    public function estPartagee(EntrepriseTierce $entreprise, ?Proprietaire $proprietaire = null): bool
    {
        return Fournisseur::where('entreprise_tierce_id', $entreprise->id)->exists()
            || Prestataire::where('entreprise_tierce_id', $entreprise->id)->exists()
            || Proprietaire::where('entreprise_tierce_id', $entreprise->id)
                ->when($proprietaire, fn ($q, Proprietaire $p) => $q->whereKeyNot($p->id))
                ->exists();
    }

    /** D7 : droit de modifier, depuis l'écran Propriétaire, l'identité de l'entreprise reliée. */
    public function peutModifierIdentite(Proprietaire $proprietaire, User $user): bool
    {
        $entreprise = $proprietaire->entrepriseTierce;
        if ($entreprise === null || ! $user->can('proprietaires.update')) {
            return false;
        }

        return ! $this->estPartagee($entreprise, $proprietaire) || $user->can('contacts.update');
    }

    /**
     * D7 : modifie raison sociale, téléphone ou NIF de l'entreprise reliée, si la saisie diffère.
     * Entreprise partagée → `contacts.update` et confirmation explicite, sinon refus ; la
     * confirmation ne remplace jamais le droit.
     *
     * @param  array{raison_sociale: string, raison_sociale_saisie: string, telephone?: ?string, identifiant_fiscal?: ?string}  $identite
     * @return list<string> champs modifiés
     */
    public function modifierIdentite(Proprietaire $proprietaire, array $identite, bool $confirme, User $acteur): array
    {
        $this->assertMemeOrganisation($proprietaire->organization_id, $acteur);
        $entreprise = $proprietaire->entrepriseTierce;
        if ($entreprise === null) {
            return [];
        }

        return DB::transaction(function () use ($proprietaire, $entreprise, $identite, $confirme, $acteur) {
            EntrepriseTierce::whereKey($entreprise->id)->lockForUpdate()->first();
            $entreprise->refresh();

            $changements = $this->changements($entreprise, $identite);
            if ($changements === []) {
                return [];
            }

            $premierChamp = match (array_key_first($changements)) {
                'raison_sociale' => 'raison_sociale',
                'identifiant_fiscal' => 'identifiant_fiscal',
                default => 'telephone_entreprise',
            };
            if (! $acteur->can('proprietaires.update')) {
                throw new AuthorizationException('Vous ne pouvez pas modifier ce propriétaire.');
            }
            if ($this->estPartagee($entreprise, $proprietaire)) {
                if (! $acteur->can('contacts.update')) {
                    throw ValidationException::withMessages([$premierChamp => self::MESSAGE_DROIT_PARTAGEE]);
                }
                if (! $confirme) {
                    throw ValidationException::withMessages(['confirmer_modification_entreprise' => self::MESSAGE_CONFIRMATION]);
                }
            }

            $this->assertIdentifiantsDisponibles($entreprise, $changements, $acteur);

            try {
                $entreprise->update($changements);
            } catch (UniqueConstraintViolationException) {
                throw ValidationException::withMessages(['telephone_entreprise' => "Ce numéro vient d'être enregistré pour une autre entreprise."]);
            }

            $champs = array_values(array_diff(array_keys($changements), ['telephone_normalise']));
            $this->journal->record($entreprise, AuditEvent::UPDATED, $acteur, null, null, [
                'champs_modifies' => $champs,
                'origine' => self::ORIGINE_FORMULAIRE,
                'proprietaire_id' => $proprietaire->id,
            ]);

            return $champs;
        });
    }

    // ── Lien ────────────────────────────────────────────────────────────────

    /**
     * Pose le lien et relie le contact comme interlocuteur. Verrouille l'entreprise puis le
     * propriétaire ; refuse un propriétaire déjà relié et une entreprise qui a déjà un propriétaire.
     *
     * @return array{entreprise_creee: bool, valeurs_avant: array{entreprise_tierce_id: ?string, raison_sociale: ?string}, interlocuteur_id: string, interlocuteur_etat_avant: string, principal_pose: bool}
     */
    public function lier(Proprietaire $proprietaire, EntrepriseTierce $entreprise, User $acteur, bool $entrepriseCreee, string $origine, array $meta = []): array
    {
        $this->assertMemeOrganisation($proprietaire->organization_id, $acteur);
        if ($entreprise->organization_id !== $proprietaire->organization_id) {
            throw new AuthorizationException("Cette entreprise n'appartient pas à l'organisation du propriétaire.");
        }

        return DB::transaction(function () use ($proprietaire, $entreprise, $acteur, $entrepriseCreee, $origine, $meta) {
            EntrepriseTierce::whereKey($entreprise->id)->lockForUpdate()->first();
            /** @var Proprietaire $verrouille */
            $verrouille = Proprietaire::withTrashed()->whereKey($proprietaire->id)->lockForUpdate()->firstOrFail();

            if ($verrouille->type !== TypeProprietaire::ENTREPRISE) {
                throw ValidationException::withMessages(['type' => 'Seul un propriétaire de type entreprise se relie à une fiche Entreprise.']);
            }
            if ($verrouille->entreprise_tierce_id !== null) {
                throw ValidationException::withMessages(['entreprise_choix' => 'Ce propriétaire est déjà relié à une entreprise.']);
            }
            if (Proprietaire::where('entreprise_tierce_id', $entreprise->id)->whereKeyNot($verrouille->id)->exists()) {
                throw ValidationException::withMessages(['entreprise_choix' => "Cette entreprise est déjà propriétaire : une entreprise ne porte qu'une fonction Propriétaire."]);
            }
            $contact = $verrouille->personne;
            if ($contact === null) {
                throw ValidationException::withMessages(['telephone' => "Ce propriétaire n'a pas de contact : il ne peut pas être relié à une entreprise."]);
            }

            $avant = ['entreprise_tierce_id' => $verrouille->entreprise_tierce_id, 'raison_sociale' => $verrouille->raison_sociale];
            $verrouille->forceFill(['entreprise_tierce_id' => $entreprise->id, 'raison_sociale' => $entreprise->raison_sociale])->save();
            $proprietaire->forceFill(['entreprise_tierce_id' => $entreprise->id, 'raison_sociale' => $entreprise->raison_sociale])->syncOriginal();
            $proprietaire->setRelation('entrepriseTierce', $entreprise);

            $lien = $this->interlocuteurs->rattacherContact($entreprise, $contact, $acteur, $origine);

            $this->journal->record($verrouille, AuditEvent::UPDATED, $acteur,
                ['entreprise_tierce_id' => null],
                ['entreprise_tierce_id' => $entreprise->id],
                [
                    'origine' => $origine,
                    'entreprise_creee' => $entrepriseCreee,
                    'interlocuteur_id' => $lien['interlocuteur']->id,
                    'interlocuteur_etat_avant' => $lien['etat_avant'],
                    'principal_pose' => $lien['principal_pose'],
                    ...$meta,
                ]);

            return [
                'entreprise_creee' => $entrepriseCreee,
                'valeurs_avant' => $avant,
                'interlocuteur_id' => $lien['interlocuteur']->id,
                'interlocuteur_etat_avant' => $lien['etat_avant'],
                'principal_pose' => $lien['principal_pose'],
            ];
        });
    }

    /**
     * Retire le lien (D8 : passage en personne physique ; annulation d'une reprise). L'entreprise et
     * ses interlocuteurs sont conservés. `$valeurs` remplace des colonnes du propriétaire en même
     * temps (raison sociale d'avant lors d'une annulation).
     *
     * @param  array{raison_sociale?: ?string}  $valeurs
     */
    public function delier(Proprietaire $proprietaire, User $acteur, string $origine, array $valeurs = [], array $meta = []): void
    {
        $this->assertMemeOrganisation($proprietaire->organization_id, $acteur);
        $ancienne = $proprietaire->entreprise_tierce_id;
        if ($ancienne === null) {
            return;
        }

        $proprietaire->forceFill(['entreprise_tierce_id' => null, ...$valeurs])->save();
        $this->journal->record($proprietaire, AuditEvent::UPDATED, $acteur,
            ['entreprise_tierce_id' => $ancienne],
            ['entreprise_tierce_id' => null],
            ['origine' => $origine, ...$meta]);
    }

    /**
     * Création en arrière-plan (D3) : seulement la raison sociale, le NIF et le téléphone de
     * l'entreprise saisis. Rien n'est repris du contact — ni son téléphone (D2), ni sa localisation
     * (décision du 08/11 : l'adresse d'une personne n'est pas celle de son entreprise) — et aucune
     * synchronisation ultérieure n'existe entre les deux fiches.
     *
     * @param  array{raison_sociale: string, telephone?: ?string, identifiant_fiscal?: ?string}  $identite
     */
    public function creerEntreprise(Proprietaire $proprietaire, array $identite): EntrepriseTierce
    {
        $telephone = $this->texte($identite['telephone'] ?? null);
        $normalise = $telephone !== null ? Personne::normaliserTelephone($telephone) : '';

        try {
            return EntrepriseTierce::create([
                'organization_id' => $proprietaire->organization_id,
                'raison_sociale' => trim($identite['raison_sociale']),
                'identifiant_fiscal' => $this->texte($identite['identifiant_fiscal'] ?? null),
                'telephone' => $normalise !== '' ? $telephone : null,
                'telephone_normalise' => $normalise !== '' ? $normalise : null,
                'is_active' => true,
            ]);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['telephone_entreprise' => "Ce numéro vient d'être enregistré pour une autre entreprise. Relancez la recherche."]);
        }
    }

    // ── Interne ─────────────────────────────────────────────────────────────

    private function entrepriseChoisie(string $id, User $acteur): EntrepriseTierce
    {
        $entreprise = EntrepriseTierce::where('organization_id', $acteur->organization_id)->find($id);
        // Identifiant forgé, d'une autre organisation ou non visible : même réponse, rien n'est révélé.
        if ($entreprise === null || ! $this->visibilite->entrepriseVisible($entreprise, $acteur)) {
            throw ValidationException::withMessages(['entreprise_choix' => 'Entreprise introuvable. Relancez la recherche.']);
        }
        if (! $entreprise->is_active) {
            throw ValidationException::withMessages(['entreprise_choix' => 'Cette entreprise est désactivée : choisissez-en une autre ou créez une nouvelle entreprise.']);
        }

        return $entreprise;
    }

    /** @return array<string, ?string> colonnes de l'entreprise à modifier */
    private function changements(EntrepriseTierce $entreprise, array $identite): array
    {
        $changements = [];

        $saisie = trim((string) ($identite['raison_sociale_saisie'] ?? $identite['raison_sociale']));
        $normalisee = trim((string) $identite['raison_sociale']);
        if ($saisie !== '' && $saisie !== $entreprise->raison_sociale && $normalisee !== $entreprise->raison_sociale) {
            $changements['raison_sociale'] = $normalisee;
        }

        if (array_key_exists('telephone', $identite)) {
            $telephone = $this->texte($identite['telephone']);
            $normalise = $telephone !== null ? Personne::normaliserTelephone($telephone) : '';
            if ($normalise !== (string) $entreprise->telephone_normalise) {
                $changements['telephone'] = $normalise !== '' ? $telephone : null;
                $changements['telephone_normalise'] = $normalise !== '' ? $normalise : null;
            }
        }

        if (array_key_exists('identifiant_fiscal', $identite)) {
            $nif = $this->texte($identite['identifiant_fiscal']);
            if (self::normaliserNif($nif) !== self::normaliserNif($entreprise->identifiant_fiscal)) {
                $changements['identifiant_fiscal'] = $nif;
            }
        }

        return $changements;
    }

    /** Téléphone déjà porté par une autre entreprise → refus ; NIF d'une entreprise non visible → arrêt neutre (D6). */
    private function assertIdentifiantsDisponibles(EntrepriseTierce $entreprise, array $changements, User $acteur): void
    {
        $autres = fn () => EntrepriseTierce::where('organization_id', $entreprise->organization_id)->whereKeyNot($entreprise->id);

        if (! empty($changements['telephone_normalise'])) {
            $autre = $autres()->where('telephone_normalise', $changements['telephone_normalise'])->first();
            if ($autre !== null) {
                throw ValidationException::withMessages(['telephone_entreprise' => $this->visibilite->entrepriseVisible($autre, $acteur)
                    ? "Ce numéro est déjà celui d'une autre entreprise."
                    : MessagesContacts::NUMERO_NON_VISIBLE]);
            }
        }

        $nif = self::normaliserNif($changements['identifiant_fiscal'] ?? null);
        if ($nif !== '') {
            foreach ($autres()->whereNotNull('identifiant_fiscal')->get(['id', 'organization_id', 'identifiant_fiscal']) as $autre) {
                if (self::normaliserNif($autre->identifiant_fiscal) === $nif && ! $this->visibilite->entrepriseVisible($autre, $acteur)) {
                    throw ValidationException::withMessages(['identifiant_fiscal' => MessagesContacts::NIF_NON_VISIBLE]);
                }
            }
        }
    }

    private function assertMemeOrganisation(?string $organizationId, User $acteur): void
    {
        if ($organizationId === null || $organizationId !== $acteur->organization_id) {
            throw new AuthorizationException("Ce propriétaire n'appartient pas à votre organisation.");
        }
    }

    private function texte(mixed $valeur): ?string
    {
        $v = $valeur === null ? '' : trim((string) $valeur);

        return $v === '' ? null : $v;
    }
}
