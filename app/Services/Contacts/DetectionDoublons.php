<?php

namespace App\Services\Contacts;

use App\Models\Client;
use App\Models\Employe;
use App\Models\EntrepriseInterlocuteur;
use App\Models\EntrepriseTierce;
use App\Models\Fournisseur;
use App\Models\Livreur;
use App\Models\Parrain;
use App\Models\Personne;
use App\Models\Prestataire;
use App\Models\Proprietaire;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Détection des doublons probables du référentiel Contacts (ADR 0060, lot 9a). **Lecture seule** :
 * rien n'est fusionné, rattaché ni modifié. Le rapport sert à mesurer le périmètre de la fusion (9b).
 *
 * - Un même téléphone ne peut pas être porté par deux fiches (index unique) : les doublons sont des
 *   rapprochements, jamais des certitudes. Personnes : même e-mail, même nom dans la même ville.
 *   Entreprises : même NIF, même raison sociale normalisée, même e-mail.
 * - Chaque paire indique les fonctions et comptes des deux fiches et ce qui bloquerait sa fusion :
 *   F1 — même fonction sur les deux fiches (les fonctions ne sont jamais fusionnées) ; F2 — un compte
 *   de chaque côté (compte unique, ADR 0055).
 * - Garde-fou ADR 0056 : quand un seul côté a un compte, les fonctions de l'autre côté qui lui
 *   seraient rattachées (donc visibles dans l'espace client) sont listées une à une.
 * - Les fiches supprimées (suppression logique) sont ignorées, comme leurs fonctions supprimées.
 */
final class DetectionDoublons
{
    public const CRITERES = [
        'email' => 'même e-mail',
        'nom_ville' => 'même nom dans la même ville',
        'identifiant_fiscal' => 'même NIF',
        'raison_sociale' => 'même raison sociale',
    ];

    /** Fonctions d'une Personne, avec la colonne de compte quand elles en portent une. */
    private const FONCTIONS_PERSONNE = [
        'client' => [Client::class, 'user_id'],
        'fournisseur' => [Fournisseur::class, null],
        'prestataire' => [Prestataire::class, 'user_id'],
        'proprietaire' => [Proprietaire::class, 'user_id'],
        'parrain' => [Parrain::class, 'user_id'],
        'livreur' => [Livreur::class, 'user_id'],
        'employe' => [Employe::class, null],
    ];

    private const FONCTIONS_ENTREPRISE = [
        'client' => Client::class,
        'fournisseur' => Fournisseur::class,
        'prestataire' => Prestataire::class,
        'proprietaire' => Proprietaire::class,
    ];

    /**
     * @return array{personnes: list<array<string, mixed>>, entreprises: list<array<string, mixed>>, resume: array<string, int>}
     */
    public function analyser(string $organizationId): array
    {
        $personnes = $this->pairesPersonnes($organizationId);
        $entreprises = $this->pairesEntreprises($organizationId);
        $toutes = [...$personnes, ...$entreprises];

        return [
            'personnes' => $personnes,
            'entreprises' => $entreprises,
            'resume' => [
                'paires_personnes' => count($personnes),
                'paires_entreprises' => count($entreprises),
                'fusionnables' => count(array_filter($toutes, fn (array $p) => $p['blocages'] === [])),
                'bloquees_meme_fonction' => count(array_filter($toutes, fn (array $p) => in_array('meme_fonction', array_column($p['blocages'], 'code'), true))),
                'bloquees_deux_comptes' => count(array_filter($toutes, fn (array $p) => in_array('deux_comptes', array_column($p['blocages'], 'code'), true))),
                'avec_rattachement' => count(array_filter($toutes, fn (array $p) => $p['rattachement'] !== null)),
            ],
        ];
    }

    /** Minuscules, sans accents ni ponctuation, espaces réduits. */
    public static function normaliser(?string $valeur): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', ' ', Str::ascii(mb_strtolower(trim((string) $valeur)))) ?? '');
    }

    // ── Personnes ───────────────────────────────────────────────────────────

    /** @return list<array<string, mixed>> */
    private function pairesPersonnes(string $organizationId): array
    {
        $fiches = Personne::query()
            ->where('organization_id', $organizationId)
            ->get(['id', 'nom', 'prenom', 'nom_complet', 'surnom', 'email', 'telephone', 'telephone_secondaire', 'ville']);

        $cles = [];
        foreach ($fiches as $p) {
            $email = mb_strtolower(trim((string) $p->email));
            if ($email !== '') {
                $cles['email'][$email][] = $p->id;
            }
            $nom = self::normaliser($this->nomSaisi($p));
            $ville = self::normaliser($p->ville);
            if ($nom !== '' && $ville !== '') {
                $cles['nom_ville']["{$nom}|{$ville}"][] = $p->id;
            }
        }

        $paires = $this->paires($cles);
        if ($paires === []) {
            return [];
        }

        $ids = array_values(array_unique(array_merge(...array_map(fn (array $p) => [$p['a'], $p['b']], $paires))));
        $parId = $fiches->whereIn('id', $ids)->keyBy('id');
        $fonctions = $this->fonctionsPersonnes($ids);
        $comptes = $this->comptesPersonnes($ids, $fonctions);

        return array_map(function (array $paire) use ($parId, $fonctions, $comptes) {
            $a = $parId[$paire['a']];
            $b = $parId[$paire['b']];

            return $this->paire('personne', $paire['criteres'],
                $this->fichePersonne($a, $fonctions[$a->id] ?? [], $comptes[$a->id] ?? []),
                $this->fichePersonne($b, $fonctions[$b->id] ?? [], $comptes[$b->id] ?? []),
            );
        }, $paires);
    }

    private function nomSaisi(Personne $p): string
    {
        $complet = trim((string) $p->getAttributes()['nom_complet']);

        return $complet !== '' ? $complet : trim("{$p->prenom} {$p->nom}");
    }

    /**
     * @param  list<array<string, mixed>>  $fonctions
     * @param  list<string>  $comptes
     */
    private function fichePersonne(Personne $p, array $fonctions, array $comptes): array
    {
        return [
            'id' => $p->id,
            'nom' => $this->nomSaisi($p) !== '' ? $this->nomSaisi($p) : (string) $p->telephone,
            'telephone' => $p->telephone,
            'telephone_secondaire' => $p->telephone_secondaire,
            'email' => $p->email,
            'ville' => $p->ville,
            'fonctions' => $fonctions,
            'comptes' => $comptes,
        ];
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, list<array{fonction: string, id: string, reference: ?string, actif: ?bool, user_id: ?string, rattachable: bool}>>
     */
    private function fonctionsPersonnes(array $ids): array
    {
        $resultat = [];
        foreach (self::FONCTIONS_PERSONNE as $fonction => [$classe, $colonneCompte]) {
            /** @var class-string<Model> $classe */
            foreach ($classe::query()->whereIn('personne_id', $ids)->get() as $f) {
                $resultat[$f->personne_id][] = [
                    'fonction' => $fonction,
                    'id' => (string) $f->getKey(),
                    'reference' => $f->getAttributes()['reference'] ?? null,
                    'actif' => array_key_exists('is_active', $f->getAttributes()) ? (bool) $f->is_active : null,
                    'user_id' => $colonneCompte !== null && $f->{$colonneCompte} !== null ? (string) $f->{$colonneCompte} : null,
                    'rattachable' => $this->rattachable($fonction, $f),
                ];
            }
        }
        foreach (EntrepriseInterlocuteur::query()->whereIn('personne_id', $ids)->get() as $i) {
            $resultat[$i->personne_id][] = [
                'fonction' => 'interlocuteur',
                'id' => (string) $i->id,
                'reference' => null,
                'actif' => (bool) $i->is_active,
                'user_id' => $i->user_id !== null ? (string) $i->user_id : null,
                'rattachable' => true,
            ];
        }

        return $resultat;
    }

    /** Fonctions qu'ADR 0056 relie d'office à un compte de la même Personne (FicheRattachable). */
    private function rattachable(string $fonction, Model $f): bool
    {
        return match ($fonction) {
            'livreur', 'proprietaire', 'parrain' => true,
            'prestataire' => $f instanceof Prestataire && $f->entreprise_tierce_id === null && $f->qualifieConsultant(),
            default => false,
        };
    }

    /**
     * Comptes d'une Personne : comptes qui la portent et comptes déjà reliés à l'une de ses fonctions
     * (même définition que le rattachement automatique, ADR 0056).
     *
     * @param  list<string>  $ids
     * @param  array<string, list<array<string, mixed>>>  $fonctions
     * @return array<string, list<string>>
     */
    private function comptesPersonnes(array $ids, array $fonctions): array
    {
        $resultat = [];
        foreach (User::query()->whereIn('personne_id', $ids)->get(['id', 'personne_id']) as $u) {
            $resultat[$u->personne_id][] = (string) $u->id;
        }
        foreach ($fonctions as $personneId => $liste) {
            foreach ($liste as $f) {
                if ($f['user_id'] !== null) {
                    $resultat[$personneId][] = $f['user_id'];
                }
            }
        }

        return array_map(fn (array $c) => array_values(array_unique($c)), $resultat);
    }

    // ── Entreprises ─────────────────────────────────────────────────────────

    /** @return list<array<string, mixed>> */
    private function pairesEntreprises(string $organizationId): array
    {
        $fiches = EntrepriseTierce::query()
            ->where('organization_id', $organizationId)
            ->get(['id', 'raison_sociale', 'identifiant_fiscal', 'email', 'telephone', 'ville']);

        $cles = [];
        foreach ($fiches as $e) {
            $nif = ProprietaireEntreprise::normaliserNif($e->identifiant_fiscal);
            if ($nif !== '') {
                $cles['identifiant_fiscal'][$nif][] = $e->id;
            }
            $raison = ProprietaireEntreprise::normaliserRaisonSociale($e->raison_sociale);
            if ($raison !== '') {
                $cles['raison_sociale'][$raison][] = $e->id;
            }
            $email = mb_strtolower(trim((string) $e->email));
            if ($email !== '') {
                $cles['email'][$email][] = $e->id;
            }
        }

        $paires = $this->paires($cles);
        if ($paires === []) {
            return [];
        }

        $ids = array_values(array_unique(array_merge(...array_map(fn (array $p) => [$p['a'], $p['b']], $paires))));
        $parId = $fiches->whereIn('id', $ids)->keyBy('id');
        $fonctions = $this->fonctionsEntreprises($ids);

        return array_map(function (array $paire) use ($parId, $fonctions) {
            $fiche = fn (EntrepriseTierce $e) => [
                'id' => $e->id,
                'nom' => $e->raison_sociale,
                'telephone' => $e->telephone,
                'telephone_secondaire' => null,
                'email' => $e->email,
                'ville' => $e->ville,
                'identifiant_fiscal' => $e->identifiant_fiscal,
                'fonctions' => $fonctions[$e->id] ?? [],
                'comptes' => [],
            ];

            return $this->paire('entreprise', $paire['criteres'], $fiche($parId[$paire['a']]), $fiche($parId[$paire['b']]));
        }, $paires);
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, list<array<string, mixed>>>
     */
    private function fonctionsEntreprises(array $ids): array
    {
        $resultat = [];
        foreach (self::FONCTIONS_ENTREPRISE as $fonction => $classe) {
            foreach ($classe::query()->whereIn('entreprise_tierce_id', $ids)->get() as $f) {
                $resultat[$f->entreprise_tierce_id][] = [
                    'fonction' => $fonction,
                    'id' => (string) $f->getKey(),
                    'reference' => $f->getAttributes()['reference'] ?? null,
                    'actif' => array_key_exists('is_active', $f->getAttributes()) ? (bool) $f->is_active : null,
                    'user_id' => null,
                    'rattachable' => false,
                ];
            }
        }
        $interlocuteurs = EntrepriseInterlocuteur::query()->whereIn('entreprise_tierce_id', $ids)->get(['entreprise_tierce_id']);
        foreach ($interlocuteurs->countBy('entreprise_tierce_id') as $id => $nombre) {
            $resultat[$id][] = ['fonction' => 'interlocuteurs', 'id' => (string) $nombre, 'reference' => null, 'actif' => null, 'user_id' => null, 'rattachable' => false];
        }

        return $resultat;
    }

    // ── Commun ──────────────────────────────────────────────────────────────

    /**
     * Paires distinctes (a < b) avec tous les critères qui les rapprochent.
     *
     * @param  array<string, array<string, list<string>>>  $cles
     * @return list<array{a: string, b: string, criteres: list<string>}>
     */
    private function paires(array $cles): array
    {
        $paires = [];
        foreach ($cles as $critere => $groupes) {
            foreach ($groupes as $ids) {
                $ids = array_values(array_unique($ids));
                sort($ids);
                $n = count($ids);
                for ($i = 0; $i < $n; $i++) {
                    for ($j = $i + 1; $j < $n; $j++) {
                        $cle = "{$ids[$i]}|{$ids[$j]}";
                        $paires[$cle] ??= ['a' => $ids[$i], 'b' => $ids[$j], 'criteres' => []];
                        $paires[$cle]['criteres'][] = $critere;
                    }
                }
            }
        }

        return array_values($paires);
    }

    /**
     * @param  list<string>  $criteres
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     */
    private function paire(string $type, array $criteres, array $a, array $b): array
    {
        $categories = fn (array $fiche) => collect($fiche['fonctions'])
            ->pluck('fonction')
            ->reject(fn (string $f) => in_array($f, ['interlocuteur', 'interlocuteurs'], true))
            ->unique()
            ->values()
            ->all();
        $communes = array_values(array_intersect($categories($a), $categories($b)));

        $blocages = [];
        if ($communes !== []) {
            $blocages[] = ['code' => 'meme_fonction', 'detail' => 'Même fonction sur les deux fiches : '.implode(', ', $communes)];
        }
        if ($a['comptes'] !== [] && $b['comptes'] !== [] && array_diff($a['comptes'], $b['comptes']) !== []) {
            $blocages[] = ['code' => 'deux_comptes', 'detail' => 'Un compte utilisateur sur chacune des deux fiches'];
        }

        return [
            'type' => $type,
            'criteres' => $criteres,
            'a' => $a,
            'b' => $b,
            'blocages' => $blocages,
            'rattachement' => $this->rattachement($a, $b),
        ];
    }

    /**
     * Garde-fou ADR 0056 : un seul côté a un compte — fonctions de l'autre côté, encore sans compte,
     * qui lui seraient rattachées après une fusion (et donc visibles dans l'espace client).
     *
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     * @return ?array{compte: string, depuis: string, fonctions: list<array{fonction: string, id: string, reference: ?string}>}
     */
    private function rattachement(array $a, array $b): ?array
    {
        if (($a['comptes'] === []) === ($b['comptes'] === [])) {
            return null;
        }
        [$avecCompte, $sansCompte] = $a['comptes'] !== [] ? [$a, $b] : [$b, $a];
        $fonctions = collect($sansCompte['fonctions'])
            ->filter(fn (array $f) => $f['rattachable'] && $f['user_id'] === null)
            ->map(fn (array $f) => ['fonction' => $f['fonction'], 'id' => $f['id'], 'reference' => $f['reference']])
            ->values()
            ->all();

        return $fonctions === [] ? null : ['compte' => $avecCompte['comptes'][0], 'depuis' => $sansCompte['id'], 'fonctions' => $fonctions];
    }
}
