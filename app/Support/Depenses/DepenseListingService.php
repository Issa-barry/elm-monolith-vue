<?php

namespace App\Support\Depenses;

use App\Enums\StatutDepense;
use App\Models\Client;
use App\Models\Depense;
use App\Models\DroitCreationDepense;
use App\Models\Employe;
use App\Models\Livreur;
use App\Models\Prestataire;
use App\Models\Proprietaire;
use App\Models\User;
use App\Models\Vehicule;
use App\Services\DroitCreationDepenseService;
use Illuminate\Database\Eloquent\Builder;

/**
 * Requête filtrée, préchargement des bénéficiaires et sérialisation d'une ligne de dépense —
 * extrait de DepenseController::buildQuery()/preloadBeneficiaires()/transformDepense(), partagé
 * à l'identique par Index/ExportCsv/Imprimer (les 3 seules actions qui listent des dépenses).
 * Le préchargement en masse (preloadBeneficiaires) évite le N+1 que produirait une résolution
 * bénéficiaire par bénéficiaire pendant la sérialisation de chaque ligne.
 */
final class DepenseListingService
{
    public function __construct(
        private readonly DroitCreationDepenseService $droitCreationDepense,
    ) {}

    public function query(array $filters, string $orgId, array $siteIds = []): Builder
    {
        $query = Depense::forOrg($orgId)
            ->orderByDesc('date_depense')
            ->orderByDesc('created_at');

        if (! empty($filters['type'])) {
            $query->whereIn('depense_type_id', (array) $filters['type']);
        }
        if (! empty($filters['statut'])) {
            $query->whereIn('statut', (array) $filters['statut']);
        }
        if (! empty($filters['categorie'])) {
            $query->whereHas('depenseType', fn ($q) => $q->whereIn('categorie', (array) $filters['categorie']));
        }
        if (! empty($siteIds)) {
            $query->whereIn('site_id', $siteIds);
        }
        if (! empty($filters['date_debut'])) {
            $query->where('date_depense', '>=', $filters['date_debut']);
        }
        if (! empty($filters['date_fin'])) {
            $query->where('date_depense', '<=', $filters['date_fin']);
        }

        if (! empty($filters['vehicule'])) {
            $likeVeh = '%'.$filters['vehicule'].'%';
            $likeImmat = '%'.preg_replace('/[\s\-]/', '', $filters['vehicule']).'%';
            $query->where('beneficiaire_type', 'vehicule')
                ->whereHas('vehiculeBeneficiaire', fn ($q) => $q
                    ->where('nom_vehicule', 'LIKE', $likeVeh)
                    ->orWhereRaw("REPLACE(REPLACE(immatriculation, '-', ''), ' ', '') LIKE ?", [$likeImmat])
                );
        }

        if (! empty($filters['search'])) {
            $like = '%'.$filters['search'].'%';
            $digits = preg_replace('/\D/', '', $filters['search']);
            $likeTel = $digits ? '%'.$digits.'%' : null;
            $likeImmat = '%'.preg_replace('/[\s\-]/', '', $filters['search']).'%';

            $query->where(function ($w) use ($like, $likeTel, $likeImmat) {
                $norm = "REPLACE(REPLACE(telephone, ' ', ''), '-', '')";

                $w->where('commentaire', 'LIKE', $like)
                    ->orWhereHas('depenseType', fn ($q) => $q->where('libelle', 'LIKE', $like))
                    ->orWhere(fn ($w2) => $w2
                        ->where('beneficiaire_type', 'vehicule')
                        ->whereHas('vehiculeBeneficiaire', fn ($q) => $q
                            ->where('nom_vehicule', 'LIKE', $like)
                            ->orWhereRaw("REPLACE(REPLACE(immatriculation, '-', ''), ' ', '') LIKE ?", [$likeImmat])
                        )
                    )
                    ->orWhere(fn ($w2) => $w2
                        ->where('beneficiaire_type', 'employe')
                        ->whereHas('employeBeneficiaire', fn ($q) => $q->whereHas('personne', function ($p) use ($like, $likeTel, $norm) {
                            $p->where('nom', 'LIKE', $like)
                                ->orWhere('prenom', 'LIKE', $like)
                                ->orWhereRaw("CONCAT(prenom, ' ', nom) LIKE ?", [$like]);
                            if ($likeTel) {
                                $p->orWhereRaw("{$norm} LIKE ?", [$likeTel]);
                            }
                        }))
                    )
                    ->orWhere(fn ($w2) => $w2
                        ->where('beneficiaire_type', 'livreur')
                        ->whereHas('livreurBeneficiaire', function ($q) use ($like, $likeTel, $norm) {
                            $q->where('livreurs.nom_complet', 'LIKE', $like)
                                ->orWhereHas('personne', function ($p) use ($like, $likeTel, $norm) {
                                    $p->where('nom', 'LIKE', $like)
                                        ->orWhere('prenom', 'LIKE', $like)
                                        ->orWhereRaw("CONCAT(prenom, ' ', nom) LIKE ?", [$like]);
                                    if ($likeTel) {
                                        $p->orWhereRaw("{$norm} LIKE ?", [$likeTel]);
                                    }
                                });
                        })
                    )
                    ->orWhere(fn ($w2) => $w2
                        ->where('beneficiaire_type', 'proprietaire')
                        ->whereHas('proprietaireBeneficiaire', fn ($q) => $q->whereHas('personne', function ($p) use ($like, $likeTel, $norm) {
                            $p->where('nom', 'LIKE', $like)
                                ->orWhere('prenom', 'LIKE', $like)
                                ->orWhereRaw("CONCAT(prenom, ' ', nom) LIKE ?", [$like]);
                            if ($likeTel) {
                                $p->orWhereRaw("{$norm} LIKE ?", [$likeTel]);
                            }
                        }))
                    )
                    ->orWhere(fn ($w2) => $w2
                        ->where('beneficiaire_type', 'prestataire')
                        ->whereHas('prestataireBeneficiaire', $this->matchPrestataireIdentite($like, $likeTel, $norm))
                    )->orWhere(fn ($w2) => $w2
                    ->where('beneficiaire_type', 'client')
                    ->whereHas('clientBeneficiaire', fn ($q) => $q
                        ->where('nom', 'LIKE', $like)
                        ->orWhere('prenom', 'LIKE', $like)
                        ->orWhereRaw("CONCAT(prenom, ' ', nom) LIKE ?", [$like])
                        ->when($likeTel, fn ($query) => $query->orWhereRaw("{$norm} LIKE ?", [$likeTel]))
                    )
                    );
            });
        }

        if (! empty($filters['concerne'])) {
            $like = '%'.$filters['concerne'].'%';
            $digits = preg_replace('/\D/', '', $filters['concerne']);
            $likeTel = $digits ? '%'.$digits.'%' : null;
            $norm = "REPLACE(REPLACE(telephone, ' ', ''), '-', '')";

            $query->where(function ($w) use ($like, $likeTel, $norm) {
                $matchPersonne = function ($q) use ($like, $likeTel, $norm) {
                    $q->whereHas('personne', function ($p) use ($like, $likeTel, $norm) {
                        $p->where('nom', 'LIKE', $like)
                            ->orWhere('prenom', 'LIKE', $like)
                            ->orWhereRaw("CONCAT(prenom, ' ', nom) LIKE ?", [$like]);
                        if ($likeTel) {
                            $p->orWhereRaw("{$norm} LIKE ?", [$likeTel]);
                        }
                    });
                };

                $w->where(fn ($w2) => $w2
                    ->where('beneficiaire_type', 'employe')
                    ->whereHas('employeBeneficiaire', $matchPersonne)
                )->orWhere(fn ($w2) => $w2
                    ->where('beneficiaire_type', 'livreur')
                    ->whereHas('livreurBeneficiaire', fn ($q) => $q
                        ->where('livreurs.nom_complet', 'LIKE', $like)
                        ->orWhere($matchPersonne))
                )->orWhere(fn ($w2) => $w2
                    ->where('beneficiaire_type', 'proprietaire')
                    ->whereHas('proprietaireBeneficiaire', $matchPersonne)
                )->orWhere(fn ($w2) => $w2
                    ->where('beneficiaire_type', 'vehicule')
                    ->whereHas('vehiculeBeneficiaire', fn ($q) => $q->where('nom_vehicule', 'LIKE', $like))
                )->orWhere(fn ($w2) => $w2
                    ->where('beneficiaire_type', 'prestataire')
                    ->whereHas('prestataireBeneficiaire', $this->matchPrestataireIdentite($like, $likeTel, $norm))
                )->orWhere(fn ($w2) => $w2
                    ->where('beneficiaire_type', 'client')
                    ->whereHas('clientBeneficiaire', fn ($q) => $q
                        ->where('nom', 'LIKE', $like)
                        ->orWhere('prenom', 'LIKE', $like)
                        ->orWhereRaw("CONCAT(prenom, ' ', nom) LIKE ?", [$like])
                        ->when($likeTel, fn ($query) => $query->orWhereRaw("{$norm} LIKE ?", [$likeTel]))
                    )
                );
            });
        }

        if (! empty($filters['telephone_concerne'])) {
            $digits = preg_replace('/\D/', '', $filters['telephone_concerne']);
            $likeTel = '%'.($digits ?: $filters['telephone_concerne']).'%';
            $query->where(function ($w) use ($likeTel) {
                $norm = "REPLACE(REPLACE(telephone, ' ', ''), '-', '')";
                $matchTel = fn ($q) => $q->whereHas('personne', fn ($p) => $p->whereRaw("{$norm} LIKE ?", [$likeTel]));

                $w->where(fn ($w2) => $w2
                    ->where('beneficiaire_type', 'employe')
                    ->whereHas('employeBeneficiaire', $matchTel)
                )->orWhere(fn ($w2) => $w2
                    ->where('beneficiaire_type', 'livreur')
                    ->whereHas('livreurBeneficiaire', $matchTel)
                )->orWhere(fn ($w2) => $w2
                    ->where('beneficiaire_type', 'proprietaire')
                    ->whereHas('proprietaireBeneficiaire', $matchTel)
                )->orWhere(fn ($w2) => $w2
                    ->where('beneficiaire_type', 'prestataire')
                    ->whereHas('prestataireBeneficiaire', fn ($q) => $q
                        ->whereHas('personne', fn ($p) => $p->whereRaw("{$norm} LIKE ?", [$likeTel]))
                        ->orWhereHas('entrepriseTierce', fn ($e) => $e->whereRaw("REPLACE(REPLACE(telephone, ' ', ''), '-', '') LIKE ?", [$likeTel]))
                    )
                )->orWhere(fn ($w2) => $w2
                    ->where('beneficiaire_type', 'client')
                    ->whereHas('clientBeneficiaire', fn ($q) => $q->whereRaw("{$norm} LIKE ?", [$likeTel]))
                );
            });
        }

        if (isset($filters['montant']) && $filters['montant'] !== '') {
            $query->where('montant', (float) $filters['montant']);
        }

        return $query;
    }

    /**
     * Un Prestataire porte son identité soit via Personne (physique) soit via EntrepriseTierce
     * (morale) — jamais les deux, cf. Prestataire::$appends. Les recherches "concerne"/"search"
     * doivent matcher les deux chemins, contrairement à employe/livreur/proprietaire qui n'ont
     * qu'une seule identité possible (Personne).
     */
    private function matchPrestataireIdentite(string $like, ?string $likeTel, string $norm): \Closure
    {
        return function ($q) use ($like, $likeTel, $norm) {
            $q->whereHas('personne', function ($p) use ($like, $likeTel, $norm) {
                $p->where('nom', 'LIKE', $like)
                    ->orWhere('prenom', 'LIKE', $like)
                    ->orWhereRaw("CONCAT(prenom, ' ', nom) LIKE ?", [$like]);
                if ($likeTel) {
                    $p->orWhereRaw("{$norm} LIKE ?", [$likeTel]);
                }
            })->orWhereHas('entrepriseTierce', function ($e) use ($like, $likeTel) {
                $e->where('raison_sociale', 'LIKE', $like);
                if ($likeTel) {
                    $e->orWhereRaw("REPLACE(REPLACE(telephone, ' ', ''), '-', '') LIKE ?", [$likeTel]);
                }
            });
        };
    }

    public function transform(Depense $d, array $labelCache, array $vehiculeInfoCache, ?User $user = null, ?DroitCreationDepense $droitValidation = null): array
    {
        $categorie = $d->depenseType?->categorie;
        $cacheKey = "{$d->beneficiaire_type}:{$d->beneficiaire_id}";

        $vehiculeNom = null;
        $concerneReelLabel = $labelCache[$cacheKey] ?? null;
        $impactMessage = $categorie?->impactMessage() ?? '';

        if ($d->beneficiaire_type === 'vehicule' && $d->beneficiaire_id) {
            $vehiculeNom = $labelCache[$cacheKey] ?? null;
            $extra = $vehiculeInfoCache[$d->beneficiaire_id] ?? null;
            if ($extra) {
                $concerneReelLabel = $extra['concerne_reel_label'];
                $impactMessage = $extra['impact_message'];
            }
        }

        $beneficiaireTelephone = null;
        $vehiculeImmatriculation = null;

        if ($d->beneficiaire_type === 'vehicule' && $d->beneficiaire_id) {
            $extra = $vehiculeInfoCache[$d->beneficiaire_id] ?? null;
            if ($extra) {
                $vehiculeImmatriculation = $extra['immatriculation'] ?? null;
                $beneficiaireTelephone = $extra['telephone'] ?? null;
            }
        } else {
            $beneficiaireTelephone = $labelCache["tel:{$d->beneficiaire_type}:{$d->beneficiaire_id}"] ?? null;
        }

        return [
            'id' => $d->id,
            'montant' => (float) $d->montant,
            'date_depense' => $d->date_depense->toDateString(),
            'statut' => $d->statut->value,
            'statut_label' => $d->statut->label(),
            'commentaire' => $d->commentaire,
            'type' => $d->depenseType ? [
                'id' => $d->depenseType->id,
                'libelle' => $d->depenseType->libelle,
                'categorie' => $categorie?->value,
                'categorie_label' => $categorie?->label(),
                'impact_message' => $impactMessage,
                'commentaire_obligatoire' => $d->depenseType->commentaire_obligatoire,
                'justificatif_obligatoire' => $d->depenseType->justificatif_obligatoire,
            ] : null,
            'beneficiaire_type' => $d->beneficiaire_type,
            'beneficiaire_id' => $d->beneficiaire_id,
            'beneficiaire_label' => $concerneReelLabel,
            'beneficiaire_telephone' => $beneficiaireTelephone,
            'vehicule_nom' => $vehiculeNom,
            'vehicule_id' => ($d->beneficiaire_type === 'vehicule') ? $d->beneficiaire_id : null,
            'vehicule_immatriculation' => $vehiculeImmatriculation,
            'site' => $d->site ? ['id' => $d->site->id, 'nom' => $d->site->nom] : null,
            'user' => ['id' => $d->user->id, 'name' => $d->user->name],
            'validateur' => $d->validateur ? ['id' => $d->validateur->id, 'name' => $d->validateur->name] : null,
            'can_valider' => $user && $d->statut === StatutDepense::SOUMIS
                && $this->droitCreationDepense->peutValiderSurSite($user, $droitValidation, $d->site_id)
                && $this->droitCreationDepense->peutValiderMontant($user, $droitValidation, (float) $d->montant),
        ];
    }

    public function preloadBeneficiaires(array $depenses): array
    {
        $labelCache = [];
        $vehiculeInfoCache = [];

        $byType = collect($depenses)
            ->filter(fn ($d) => $d->beneficiaire_type && $d->beneficiaire_id)
            ->groupBy('beneficiaire_type');

        foreach ($byType as $type => $items) {
            $ids = $items->pluck('beneficiaire_id')->unique()->values()->all();

            if ($type === 'vehicule') {
                $models = Vehicule::with(['proprietaire:id,personne_id', 'proprietaire.personne'])
                    ->findMany($ids, ['id', 'nom_vehicule', 'immatriculation', 'proprietaire_id']);

                foreach ($models as $model) {
                    $labelCache["vehicule:{$model->id}"] = $model->nom_vehicule;

                    if ($model->proprietaire_id) {
                        $propNom = trim("{$model->proprietaire->prenom} {$model->proprietaire->nom}");
                        $vehiculeInfoCache[$model->id] = [
                            'concerne_reel_label' => $propNom,
                            'impact_message' => "Cette dépense sera déduite de la commission de {$propNom}.",
                            'immatriculation' => $model->immatriculation,
                            'telephone' => $model->proprietaire->telephone,
                        ];
                    } else {
                        $vehiculeInfoCache[$model->id] = [
                            'concerne_reel_label' => 'Agence ELM',
                            'impact_message' => 'Ce véhicule est interne ELM. La dépense sera comptabilisée comme charge entreprise.',
                            'immatriculation' => $model->immatriculation,
                            'telephone' => null,
                        ];
                    }
                }
            } else {
                // Identité civile portée par Personne (nom/prenom/telephone) — nom_complet
                // reste une colonne propre au livreur, utilisée en repli par libelleAffichage().
                // Prestataire est à part : identité physique (Personne) OU morale
                // (EntrepriseTierce), jamais une colonne directe — d'où fields=null (toutes
                // colonnes) et les accesseurs nom_complet/phone plutôt que nom/prenom/telephone.
                $fields = match (true) {
                    $type === 'livreur' => ['id', 'personne_id', 'nom_complet'],
                    $type === 'prestataire' => null,
                    $type === 'client' => ['id', 'nom', 'prenom', 'nom_complet', 'telephone'],
                    default => ['id', 'personne_id'],
                };

                $models = match ($type) {
                    'employe' => Employe::with('personne')->findMany($ids, $fields),
                    'livreur' => Livreur::with('personne')->findMany($ids, $fields),
                    'proprietaire' => Proprietaire::with('personne')->findMany($ids, $fields),
                    'prestataire' => Prestataire::with(['personne', 'entrepriseTierce'])->findMany($ids),
                    'client' => Client::findMany($ids, $fields),
                    default => collect(),
                };

                foreach ($models as $model) {
                    if ($type === 'prestataire') {
                        $labelCache["prestataire:{$model->id}"] = $model->nom_complet;
                        $labelCache["tel:prestataire:{$model->id}"] = $model->phone;

                        continue;
                    }

                    if ($type === 'client') {
                        $labelCache["client:{$model->id}"] = $model->nom_complet;
                        $labelCache["tel:client:{$model->id}"] = $model->telephone;

                        continue;
                    }

                    $labelCache["{$type}:{$model->id}"] = $type === 'livreur'
                        ? $model->libelleAffichage()
                        : trim("{$model->prenom} {$model->nom}");
                    $labelCache["tel:{$type}:{$model->id}"] = $model->telephone ?? null;
                }
            }
        }

        return [$labelCache, $vehiculeInfoCache];
    }
}
