<?php

namespace App\Services\Rh;

use App\Enums\CategorieTypeRemuneration;
use App\Enums\ModeCalculRemuneration;
use App\Enums\NatureMontantRemuneration;
use App\Enums\PeriodiciteRemuneration;
use App\Enums\StatutAffectationRemuneration;
use App\Enums\StatutContrat;
use App\Enums\StatutDocumentContrat;
use App\Enums\TypeContrat;
use App\Models\AffectationRemuneration;
use App\Models\Contrat;
use App\Models\DocumentContrat;
use App\Models\Employe;
use App\Models\ModeleContrat;
use App\Models\Organization;
use App\Models\User;
use App\Support\Documents\ContratDocument;
use App\Support\Documents\DocumentPdf;
use App\Support\Documents\DocumentPdfRenderer;
use App\Support\Documents\IdentiteOrganisation;
use App\Support\Rh\Contrats\VariablesContrat;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Génération des contrats de travail (docs/rh-contrat-pdf.md, ADR 0044). Le salarié et son contrat
 * apportent les données, le modèle actif du type apporte le texte. Chaque génération crée une
 * version : PDF stocké sur le disque privé et instantané figé des valeurs utilisées. Une version
 * signée n'est jamais modifiée ni remplacée.
 */
class DocumentContratService
{
    public const DISQUE = 'contrats_rh';

    public function __construct(private readonly ModeleContratService $modeles) {}

    /**
     * Valeurs reprises du dossier RH et du contrat (null = absente du dossier).
     *
     * @return array<string, string|null>
     */
    public function valeursDossier(Contrat $contrat, Organization $organisation): array
    {
        $employe = $this->employe($contrat);
        $personne = $employe?->personne;
        $nomComplet = trim(implode(' ', array_filter([$personne?->prenom, $personne?->nom])));

        return [
            'employeur.nom' => $organisation->name ?: null,
            'salarie.civilite' => $personne?->civilite?->label(),
            'salarie.nom_complet' => $nomComplet !== '' ? $nomComplet : null,
            'salarie.prenom' => $personne?->prenom ?: null,
            'salarie.nom' => $personne?->nom ?: null,
            'salarie.matricule' => $employe?->matricule ?: null,
            'salarie.date_naissance' => $personne?->date_naissance?->format('d/m/Y'),
            'salarie.lieu_naissance' => $personne?->lieu_naissance ?: null,
            'salarie.nationalite' => $personne?->nationalite ?: null,
            'salarie.adresse' => collect([$personne?->adresse, $personne?->ville])->filter()->implode(', ') ?: null,
            'salarie.date_embauche' => $employe?->date_embauche?->format('d/m/Y'),
            'poste.fonction' => $employe?->fonction?->libelle,
            'poste.service' => $employe?->service?->libelle,
            'poste.agence' => $employe?->site?->nom,
            'contrat.type' => match ($contrat->type_contrat) {
                TypeContrat::CDI => 'contrat à durée indéterminée',
                TypeContrat::CDD => 'contrat à durée déterminée',
            },
            'contrat.date_debut' => $contrat->date_debut?->format('d/m/Y'),
            'contrat.date_fin' => $contrat->date_fin?->format('d/m/Y'),
            'contrat.salaire_base' => $contrat->salaire_base === null ? null : DocumentPdf::montant($contrat->salaire_base),
        ];
    }

    /**
     * Primes et avantages proposés au contrat : affectations validées du salarié, en cours ou à
     * venir (jamais une proposition à valider, annulée ou terminée).
     *
     * @return Collection<int, AffectationRemuneration>
     */
    public function avantagesDisponibles(Contrat $contrat): Collection
    {
        return AffectationRemuneration::with('type')
            ->where('organization_id', $contrat->organization_id)
            ->where('employe_id', $contrat->employe_id)
            ->where('statut', StatutAffectationRemuneration::VALIDEE->value)
            ->where(fn ($q) => $q->whereNull('date_fin')->orWhereDate('date_fin', '>=', today()->toDateString()))
            ->orderBy('date_debut')
            ->get();
    }

    /** « Prime de transport (50 000 GNF par mois) » : texte repris dans le contrat. */
    public function texteAvantage(AffectationRemuneration $affectation): string
    {
        $type = $affectation->type;
        $periodicite = $type->periodicite === PeriodiciteRemuneration::PONCTUELLE ? ', versement ponctuel' : ' par mois';

        $detail = match (true) {
            $type->mode_calcul === ModeCalculRemuneration::POURCENTAGE_SALAIRE_BASE => rtrim(rtrim(number_format((float) ($affectation->taux ?? $type->taux_defaut), 2, ',', ' '), '0'), ',').' % du salaire de base'.$periodicite,
            $type->nature_montant === NatureMontantRemuneration::VARIABLE || ($affectation->montant ?? $type->montant_defaut) === null => 'montant variable fixé chaque mois',
            default => DocumentPdf::montant($affectation->montant ?? $type->montant_defaut).$periodicite,
        };

        if ($type->categorie === CategorieTypeRemuneration::AVANTAGE_NATURE) {
            $detail = 'avantage en nature, valeur estimée '.$detail;
        }
        if ($affectation->date_fin) {
            $detail .= ', jusqu’au '.$affectation->date_fin->format('d/m/Y');
        }

        return $type->libelle.' ('.$detail.')';
    }

    /**
     * Valeurs proposées pour les champs à compléter : celles de la dernière version de ce contrat,
     * sinon le représentant du dernier contrat généré dans l'organisation et l'agence du salarié.
     *
     * @return array<string, string|null>
     */
    public function champsProposes(Contrat $contrat): array
    {
        $champs = array_fill_keys(array_keys(VariablesContrat::CHAMPS_SAISIS), null);

        $precedent = DocumentContrat::where('contrat_id', $contrat->id)->orderByDesc('version')->first();
        if ($precedent) {
            return array_merge($champs, array_intersect_key($precedent->donnees['champs'] ?? [], $champs));
        }

        $dernierOrganisation = DocumentContrat::where('organization_id', $contrat->organization_id)->latest()->first();
        $site = $this->employe($contrat)?->site;

        return array_merge($champs, [
            'representant_nom' => $dernierOrganisation?->donnees['champs']['representant_nom'] ?? null,
            'representant_qualite' => $dernierOrganisation?->donnees['champs']['representant_qualite'] ?? null,
            'lieu_travail' => $site ? collect([$site->nom, $site->ville])->filter()->unique()->implode(', ') : null,
            'lieu_signature' => $site?->ville ?: null,
        ]);
    }

    /**
     * Génère une nouvelle version. Refusée sans modèle actif du type, pour un contrat qui n'est
     * plus actif, ou si une variable utilisée par le modèle est vide.
     *
     * @param  array<string, string|null>  $champs
     * @param  list<string>  $avantageIds
     */
    public function generer(Contrat $contrat, array $champs, array $avantageIds, User $auteur): DocumentContrat
    {
        $chemin = null;

        try {
            return DB::transaction(function () use ($contrat, $champs, $avantageIds, $auteur, &$chemin) {
                $contrat = Contrat::whereKey($contrat->id)->lockForUpdate()->firstOrFail();

                if ($contrat->statut_contrat !== StatutContrat::ACTIF) {
                    throw ValidationException::withMessages(['contrat' => 'Seul un contrat actif peut être généré. Les versions déjà générées restent consultables.']);
                }

                $modele = $this->modeles->actif($contrat->organization_id, $contrat->type_contrat)
                    ?? throw ValidationException::withMessages(['modele' => 'Aucun modèle de '.$contrat->type_contrat->label().' actif : un administrateur doit le relire et l’activer dans Paramètres → RH → Modèles de contrat.']);

                $organisation = Organization::findOrFail($contrat->organization_id);
                $avantages = $this->avantagesRetenus($contrat, $avantageIds);
                $version = (int) DocumentContrat::where('contrat_id', $contrat->id)->max('version') + 1;
                $instantane = $this->instantane($contrat, $organisation, $modele, $this->nettoyer($champs), $avantages, $version);

                $id = (string) Str::ulid();
                $chemin = $contrat->organization_id.'/'.$contrat->id.'/'.$id.'.pdf';
                $pdf = DocumentPdfRenderer::pdf(ContratDocument::depuis($instantane, $organisation))->output();
                Storage::disk(self::DISQUE)->put($chemin, $pdf);

                DocumentContrat::where('contrat_id', $contrat->id)
                    ->where('statut', StatutDocumentContrat::A_SIGNER->value)
                    ->update(['statut' => StatutDocumentContrat::REMPLACE->value]);

                $document = new DocumentContrat([
                    'organization_id' => $contrat->organization_id,
                    'contrat_id' => $contrat->id,
                    'version' => $version,
                    'modele_contrat_id' => $modele->id,
                    'donnees' => $instantane,
                    'fichier_path' => $chemin,
                    'empreinte' => hash('sha256', $pdf),
                    'statut' => StatutDocumentContrat::A_SIGNER,
                    'genere_par' => $auteur->id,
                ]);
                $document->id = $id;
                $document->save();

                return $document;
            });
        } catch (\Throwable $e) {
            if ($chemin !== null) {
                Storage::disk(self::DISQUE)->delete($chemin);
            }

            throw $e;
        }
    }

    /**
     * Enregistre la signature (papier) de la version en cours : elle est alors figée. Seule la
     * dernière version, encore à signer, peut l'être.
     */
    public function enregistrerSignature(DocumentContrat $document, CarbonInterface $signeLe, User $auteur): DocumentContrat
    {
        return DB::transaction(function () use ($document, $signeLe, $auteur) {
            $document = DocumentContrat::whereKey($document->id)->lockForUpdate()->firstOrFail();
            $derniere = (int) DocumentContrat::where('contrat_id', $document->contrat_id)->max('version');

            if ($document->statut !== StatutDocumentContrat::A_SIGNER || $document->version !== $derniere) {
                throw ValidationException::withMessages(['signe_le' => 'Seule la dernière version, encore à signer, peut être marquée comme signée.']);
            }
            if ($signeLe->isAfter(today()) || $signeLe->isBefore($document->created_at->copy()->startOfDay())) {
                throw ValidationException::withMessages(['signe_le' => 'La date de signature doit être comprise entre la date de génération ('.$document->created_at->format('d/m/Y').') et aujourd’hui.']);
            }

            $document->update([
                'statut' => StatutDocumentContrat::SIGNE,
                'signe_le' => $signeLe->toDateString(),
                'signature_enregistree_par' => $auteur->id,
                'signature_enregistree_le' => now(),
            ]);

            return $document;
        });
    }

    /** Contenu du PDF stocké ; reconstruit depuis l'instantané si le fichier a disparu du disque. */
    public function contenu(DocumentContrat $document): string
    {
        $disque = Storage::disk(self::DISQUE);
        if ($disque->exists($document->fichier_path)) {
            return $disque->get($document->fichier_path);
        }

        report(new \RuntimeException("Fichier du contrat généré {$document->id} absent : reconstruit depuis l'instantané."));

        return DocumentPdfRenderer::pdf(ContratDocument::depuis($document->donnees, Organization::findOrFail($document->organization_id)))->output();
    }

    /**
     * Instantané figé : tout ce que le PDF affiche, déjà formaté. Refuse la génération si une
     * variable utilisée par le modèle est inconnue ou vide.
     *
     * @param  array<string, string|null>  $champs
     * @param  Collection<int, AffectationRemuneration>  $avantages
     * @return array<string, mixed>
     */
    public function instantane(Contrat $contrat, Organization $organisation, ModeleContrat $modele, array $champs, Collection $avantages, int $version): array
    {
        $employe = $this->employe($contrat);
        $textesAvantages = $avantages->map(fn (AffectationRemuneration $a) => $this->texteAvantage($a))->values();

        $valeurs = $this->valeursDossier($contrat, $organisation);
        foreach (VariablesContrat::CHAMPS_SAISIS as $champ => $cle) {
            $valeurs[$cle] = $champs[$champ] ?? null;
        }
        $valeurs['contrat.avantages'] = $textesAvantages->isEmpty() ? 'aucun' : $textesAvantages->implode(' ; ');
        $valeurs['document.date'] = now()->format('d/m/Y');

        $textes = [$modele->titre, ...collect($modele->articles)->flatMap(fn (array $a) => [$a['titre'], $a['texte']])->all()];

        $refusees = VariablesContrat::refusees($textes, $contrat->type_contrat);
        if ($refusees !== []) {
            throw ValidationException::withMessages(['modele' => 'Le modèle contient des variables non reconnues : '.implode(', ', $refusees).'. Corrigez-le dans Paramètres → RH → Modèles de contrat.']);
        }

        $manquantes = array_values(array_filter(VariablesContrat::extraire($textes), fn (string $cle) => blank($valeurs[$cle] ?? null)));
        if ($manquantes !== []) {
            throw ValidationException::withMessages(['manquantes' => 'Informations manquantes : '.implode(', ', array_map(VariablesContrat::libelle(...), $manquantes)).'.']);
        }

        $articles = collect($modele->articles)->map(fn (array $article) => [
            'titre' => VariablesContrat::remplacer($article['titre'], $valeurs),
            'paragraphes' => array_values(array_filter(array_map('trim', preg_split('/\R\s*\R/u', VariablesContrat::remplacer($article['texte'], $valeurs)) ?: []), fn ($p) => $p !== '')),
        ])->all();

        $personne = $employe?->personne;
        $nom = trim(implode(' ', array_filter([$personne?->civilite?->label(), $valeurs['salarie.nom_complet']])));
        $naissance = $personne?->date_naissance
            ? 'Né(e) le '.$personne->date_naissance->format('d/m/Y').($personne->lieu_naissance ? ' à '.$personne->lieu_naissance : '')
            : null;
        $matricule = preg_replace('/[^A-Za-z0-9]/', '', (string) $employe?->matricule) ?: strtoupper(substr((string) $contrat->id, -8));

        return [
            'reference' => 'CTR-'.$matricule.($contrat->date_debut ? '-'.$contrat->date_debut->format('Ymd') : '').'-V'.$version,
            'version' => $version,
            'type_contrat' => $contrat->type_contrat->value,
            'type_libelle' => $contrat->type_contrat->label(),
            'intitule' => VariablesContrat::remplacer($modele->titre, $valeurs),
            'genere_le' => now()->format('d/m/Y à H:i'),
            'modele' => ['id' => $modele->id, 'titre' => $modele->titre, 'revision' => $modele->revision],
            'employeur' => [
                'nom' => (string) $organisation->name,
                'representant_nom' => $champs['representant_nom'] ?? null,
                'representant_qualite' => $champs['representant_qualite'] ?? null,
            ],
            'salarie' => [
                'nom' => $nom,
                'matricule' => $employe?->matricule,
                'lignes' => array_values(array_filter([
                    $nom,
                    'Matricule : '.($employe?->matricule ?: '—'),
                    $naissance,
                    $valeurs['salarie.nationalite'] ? 'Nationalité : '.$valeurs['salarie.nationalite'] : null,
                    $valeurs['salarie.adresse'] ? 'Adresse : '.$valeurs['salarie.adresse'] : null,
                ])),
            ],
            'lignes_agence' => IdentiteOrganisation::lignesAgence($employe?->site),
            'valeurs' => $valeurs,
            'champs' => $champs,
            'avantages' => $avantages->map(fn (AffectationRemuneration $a) => ['affectation_id' => $a->id, 'texte' => $this->texteAvantage($a)])->values()->all(),
            'articles' => $articles,
        ];
    }

    /** Employé du contrat, même supprimé depuis (soft delete), dans la même organisation. */
    public function employe(Contrat $contrat): ?Employe
    {
        if (! $contrat->relationLoaded('employe') || $contrat->employe === null) {
            $contrat->setRelation('employe', Employe::withTrashed()
                ->where('organization_id', $contrat->organization_id)
                ->find($contrat->employe_id));
        }
        $contrat->employe?->loadMissing(['personne', 'fonction', 'service', 'site']);

        return $contrat->employe;
    }

    /**
     * @param  list<string>  $ids
     * @return Collection<int, AffectationRemuneration>
     */
    private function avantagesRetenus(Contrat $contrat, array $ids): Collection
    {
        $ids = array_values(array_unique($ids));
        $retenus = $this->avantagesDisponibles($contrat)->whereIn('id', $ids)->values();

        if ($retenus->count() !== count($ids)) {
            throw ValidationException::withMessages(['avantages' => 'Seuls les primes et avantages validés du salarié, en cours ou à venir, peuvent figurer au contrat.']);
        }

        return $retenus;
    }

    /**
     * @param  array<string, string|null>  $champs
     * @return array<string, string|null>
     */
    private function nettoyer(array $champs): array
    {
        $propres = [];
        foreach (array_keys(VariablesContrat::CHAMPS_SAISIS) as $champ) {
            $valeur = trim((string) ($champs[$champ] ?? ''));
            $propres[$champ] = $valeur === '' ? null : $valeur;
        }

        return $propres;
    }
}
