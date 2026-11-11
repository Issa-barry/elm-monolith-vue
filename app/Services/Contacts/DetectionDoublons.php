<?php

namespace App\Services\Contacts;

use App\Models\Client;
use App\Models\EntrepriseInterlocuteur;
use App\Models\EntrepriseTierce;
use App\Models\Fournisseur;
use App\Models\Prestataire;
use App\Models\Proprietaire;

/**
 * Détection des doublons probables du référentiel Contacts (ADR 0060, lot 9a). **Lecture seule** :
 * rien n'est fusionné, rattaché ni modifié.
 *
 * - **Entreprises seulement** : même NIF, même raison sociale normalisée, même e-mail. Ce sont des
 *   rapprochements, jamais des certitudes.
 * - **Personnes jamais rapprochées** (règle du 10/11/2026) : deux numéros de téléphone différents
 *   désignent deux personnes différentes, même nom, même ville ou même e-mail compris. Deux fiches
 *   d'une même personne ne se fusionnent que sur décision manuelle (lot 9b).
 * - Chaque paire indique les fonctions des deux fiches et ce qui bloquerait sa fusion : F1 — même
 *   fonction sur les deux fiches (les fonctions ne sont jamais fusionnées).
 * - Les fiches supprimées (suppression logique) sont ignorées, comme leurs fonctions supprimées.
 */
final class DetectionDoublons
{
    public const CRITERES = [
        'identifiant_fiscal' => 'même NIF',
        'raison_sociale' => 'même raison sociale',
        'email' => 'même e-mail',
    ];

    private const FONCTIONS_ENTREPRISE = [
        'client' => Client::class,
        'fournisseur' => Fournisseur::class,
        'prestataire' => Prestataire::class,
        'proprietaire' => Proprietaire::class,
    ];

    /**
     * @return array{entreprises: list<array<string, mixed>>, resume: array<string, int>}
     */
    public function analyser(string $organizationId): array
    {
        $entreprises = $this->pairesEntreprises($organizationId);

        return [
            'entreprises' => $entreprises,
            'resume' => [
                'paires_entreprises' => count($entreprises),
                'fusionnables' => count(array_filter($entreprises, fn (array $p) => $p['blocages'] === [])),
                'bloquees_meme_fonction' => count(array_filter($entreprises, fn (array $p) => $p['blocages'] !== [])),
            ],
        ];
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
                'email' => $e->email,
                'ville' => $e->ville,
                'identifiant_fiscal' => $e->identifiant_fiscal,
                'fonctions' => $fonctions[$e->id] ?? [],
            ];

            return $this->paire($paire['criteres'], $fiche($parId[$paire['a']]), $fiche($parId[$paire['b']]));
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
                ];
            }
        }
        $interlocuteurs = EntrepriseInterlocuteur::query()->whereIn('entreprise_tierce_id', $ids)->get(['entreprise_tierce_id']);
        foreach ($interlocuteurs->countBy('entreprise_tierce_id') as $id => $nombre) {
            $resultat[$id][] = ['fonction' => 'interlocuteurs', 'id' => (string) $nombre, 'reference' => null, 'actif' => null];
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
    private function paire(array $criteres, array $a, array $b): array
    {
        $categories = fn (array $fiche) => collect($fiche['fonctions'])
            ->pluck('fonction')
            ->reject(fn (string $f) => $f === 'interlocuteurs')
            ->unique()
            ->values()
            ->all();
        $communes = array_values(array_intersect($categories($a), $categories($b)));

        return [
            'type' => 'entreprise',
            'criteres' => $criteres,
            'a' => $a,
            'b' => $b,
            'blocages' => $communes === [] ? [] : [['code' => 'meme_fonction', 'detail' => 'Même fonction sur les deux fiches : '.implode(', ', $communes)]],
        ];
    }
}
