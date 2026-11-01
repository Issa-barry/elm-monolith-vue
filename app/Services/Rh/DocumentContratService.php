<?php

namespace App\Services\Rh;

use App\Enums\CategorieTypeRemuneration;
use App\Enums\ModeCalculRemuneration;
use App\Enums\NatureMontantRemuneration;
use App\Enums\PeriodiciteRemuneration;
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
 * Génération des contrats de travail (docs/rh-contrat-pdf.md, ADR 0044 et 0048). Le salarié, son
 * contrat, le PDG désigné et l'agence apportent les données, le modèle actif du type apporte le
 * texte : plus aucune saisie au moment de la génération. Le PDF est produit automatiquement à la
 * création et à chaque modification du contrat. Chaque génération crée une version : PDF stocké
 * sur le disque privé et instantané figé des valeurs utilisées. Une version signée n'est jamais
 * modifiée ni remplacée.
 */
class DocumentContratService
{
    public const DISQUE = 'contrats_rh';

    /** Lieu de signature : laissé en blanc, écrit à la main au moment de la signature. */
    public const LIEU_SIGNATURE_BLANC = '……………………';

    /** Champs du contrat repris dans le PDF : les modifier après signature appelle une nouvelle version. */
    public const CHAMPS_CONTRACTUELS = ['employe_id', 'type_contrat', 'date_debut', 'date_fin', 'salaire_base', 'periode_essai_duree', 'periode_essai_unite', 'heures_hebdomadaires', 'motif_cdd'];

    public const MESSAGE_ECHEC_TECHNIQUE = 'Le PDF n’a pas pu être produit à cause d’un problème technique. Réessayez avec « Mettre à jour le PDF » ; si le problème continue, prévenez l’administrateur.';

    public function __construct(
        private readonly ModeleContratService $modeles,
        private readonly PdgService $pdg,
    ) {}

    /**
     * Valeurs reprises du dossier RH, du contrat, du PDG et de l'agence (null = absente).
     *
     * @return array<string, string|null>
     */
    public function valeursDossier(Contrat $contrat, Organization $organisation): array
    {
        $employe = $this->employe($contrat);
        $personne = $employe?->personne;
        $nomComplet = trim(implode(' ', array_filter([$personne?->prenom, $personne?->nom])));
        $pdg = $this->pdg->actuel($contrat->organization_id);
        $site = $employe?->site;

        return [
            'employeur.nom' => $organisation->name ?: null,
            'employeur.representant_nom' => $pdg ? (PdgService::nom($pdg) ?: null) : null,
            'employeur.representant_qualite' => $pdg ? PdgService::QUALITE : null,
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
            'poste.agence' => $site?->nom,
            'poste.lieu_travail' => $site ? (collect([$site->nom, $site->ville])->filter()->unique()->implode(', ') ?: null) : null,
            'contrat.type' => match ($contrat->type_contrat) {
                TypeContrat::CDI => 'contrat à durée indéterminée',
                TypeContrat::CDD => 'contrat à durée déterminée',
            },
            'contrat.date_debut' => $contrat->date_debut?->format('d/m/Y'),
            'contrat.date_fin' => $contrat->date_fin?->format('d/m/Y'),
            'contrat.motif_cdd' => filled($contrat->motif_cdd) ? trim($contrat->motif_cdd) : null,
            'contrat.periode_essai' => self::textePeriodeEssai($contrat->periode_essai_duree, $contrat->periode_essai_unite),
            'contrat.horaires' => self::texteHeures($contrat->heures_hebdomadaires),
            'contrat.salaire_base' => $contrat->salaire_base === null ? null : DocumentPdf::montant($contrat->salaire_base),
            'document.lieu_signature' => self::LIEU_SIGNATURE_BLANC,
        ];
    }

    /** « 3 mois », « 2 semaines », « aucune » (0) ; null si la durée ou l'unité manque. */
    public static function textePeriodeEssai(?int $duree, ?string $unite): ?string
    {
        if ($duree === null) {
            return null;
        }
        if ($duree === 0) {
            return 'aucune';
        }

        return match ($unite) {
            'mois' => $duree.' mois',
            'semaine' => $duree.' '.($duree > 1 ? 'semaines' : 'semaine'),
            default => null,
        };
    }

    /** « 40 heures par semaine », « 37,5 heures par semaine ». */
    public static function texteHeures(string|float|null $heures): ?string
    {
        if ($heures === null || (float) $heures <= 0) {
            return null;
        }
        $nombre = rtrim(rtrim(number_format((float) $heures, 2, ',', ' '), '0'), ',');

        return $nombre.' heure'.((float) $heures >= 2 ? 's' : '').' par semaine';
    }

    /**
     * Primes et avantages repris au contrat : toutes les affectations validées du salarié, en
     * cours ou à venir (jamais une proposition à valider, annulée ou terminée).
     *
     * @return Collection<int, AffectationRemuneration>
     */
    public function avantagesDisponibles(Contrat $contrat): Collection
    {
        return AffectationRemuneration::with('type')
            ->where('organization_id', $contrat->organization_id)
            ->where('employe_id', $contrat->employe_id)
            ->valideesEnCoursOuAVenir()
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
     * Génération automatique, après la création ou la modification du contrat et par « Mettre à
     * jour le PDF ». Ne lève jamais d'exception : le contrat reste enregistré et l'erreur est
     * renvoyée en clair (null = PDF à jour).
     */
    public function genererAutomatiquement(Contrat $contrat, User $auteur, bool $apresSignature = false): ?string
    {
        try {
            $this->actualiser($contrat, $auteur, $apresSignature);

            return null;
        } catch (ValidationException $e) {
            return implode(' ', collect($e->errors())->flatten()->all());
        } catch (\Throwable $e) {
            report($e);

            return self::MESSAGE_ECHEC_TECHNIQUE;
        }
    }

    /**
     * Crée une nouvelle version seulement si le contenu a changé depuis la dernière : jamais deux
     * versions identiques, même pour deux enregistrements simultanés (verrou sur le contrat).
     * Une version signée n'est jamais remplacée ; une version suivante n'est créée que si
     * `$apresSignature` (contrat modifié ou demande explicite). Refusée sans PDG désigné, sans
     * modèle actif du type, pour un contrat qui n'est plus actif, ou si une information utilisée
     * par le modèle manque.
     */
    public function actualiser(Contrat $contrat, User $auteur, bool $apresSignature = false): ?DocumentContrat
    {
        $chemin = null;

        try {
            return DB::transaction(function () use ($contrat, $auteur, $apresSignature, &$chemin) {
                $contrat = Contrat::whereKey($contrat->id)->lockForUpdate()->firstOrFail();

                if ($contrat->statut_contrat !== StatutContrat::ACTIF) {
                    throw ValidationException::withMessages(['contrat' => 'Ce contrat n’est plus actif : son PDF n’est plus mis à jour. Les versions déjà générées restent consultables.']);
                }

                $derniere = DocumentContrat::where('contrat_id', $contrat->id)->orderByDesc('version')->first();
                $version = ($derniere?->version ?? 0) + 1;
                [$instantane, $modele, $organisation] = $this->preparer($contrat, $version);

                if ($derniere !== null && self::empreinte($derniere->donnees) === self::empreinte($instantane)) {
                    return null;
                }
                if ($derniere?->statut === StatutDocumentContrat::SIGNE && ! $apresSignature) {
                    return null;
                }

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
     * État du PDF : à jour, à mettre à jour, à compléter (ce qui manque et où le corriger), signé
     * mais différent des informations actuelles, ou contrat terminé.
     *
     * @return array{code: string, message: string}
     */
    public function etat(Contrat $contrat): array
    {
        $derniere = DocumentContrat::where('contrat_id', $contrat->id)->orderByDesc('version')->first();

        if ($contrat->statut_contrat !== StatutContrat::ACTIF) {
            return ['code' => 'inactif', 'message' => $derniere
                ? 'Ce contrat n’est plus actif : son PDF n’est plus mis à jour. Les versions générées restent consultables.'
                : 'Ce contrat n’est plus actif : aucun PDF n’a été produit.'];
        }

        try {
            [$instantane] = $this->preparer($contrat, ($derniere?->version ?? 0) + 1);
        } catch (ValidationException $e) {
            return ['code' => 'a_completer', 'message' => implode(' ', collect($e->errors())->flatten()->all())];
        }

        return match (true) {
            $derniere === null => ['code' => 'a_generer', 'message' => 'Le PDF n’a pas encore été produit : cliquez sur « Mettre à jour le PDF ».'],
            self::empreinte($derniere->donnees) === self::empreinte($instantane) => ['code' => 'a_jour', 'message' => 'Le PDF correspond aux informations actuelles du contrat.'],
            $derniere->statut === StatutDocumentContrat::SIGNE => ['code' => 'signe_different', 'message' => 'La version '.$derniere->version.' est signée et reste conservée telle quelle, mais les informations ont changé depuis. Si le contrat doit être signé à nouveau, préparez une nouvelle version.'],
            default => ['code' => 'a_mettre_a_jour', 'message' => 'Les informations ont changé depuis la dernière version : cliquez sur « Mettre à jour le PDF ».'],
        };
    }

    /**
     * Empreinte du contenu d'une version : valeurs, parties et primes reprises, sans le numéro de
     * version ni la date de génération. Deux versions de même empreinte disent la même chose.
     *
     * @param  array<string, mixed>  $instantane
     */
    public static function empreinte(array $instantane): string
    {
        $valeurs = $instantane['valeurs'] ?? [];
        unset($valeurs['document.date']);
        ksort($valeurs);

        return hash('sha256', (string) json_encode([
            $instantane['type_contrat'] ?? null,
            $instantane['modele']['id'] ?? null,
            $instantane['modele']['revision'] ?? null,
            $valeurs,
            $instantane['employeur'] ?? null,
            $instantane['salarie'] ?? null,
            $instantane['lignes_agence'] ?? null,
            array_column($instantane['avantages'] ?? [], 'texte'),
        ]));
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
     * Contenu de la prochaine version : PDG désigné, modèle actif du type et toutes les primes
     * validées. Lève une ValidationException qui dit quoi corriger et où.
     *
     * @return array{0: array<string, mixed>, 1: ModeleContrat, 2: Organization}
     */
    private function preparer(Contrat $contrat, int $version): array
    {
        // Pas de contrat sans signataire : jamais un autre salarié à la place du PDG (ADR 0048).
        $this->pdg->exiger($contrat->organization_id);

        $modele = $this->modeles->actif($contrat->organization_id, $contrat->type_contrat)
            ?? throw ValidationException::withMessages(['modele' => 'Aucun modèle de '.$contrat->type_contrat->label().' actif : un administrateur doit le relire et l’activer dans Paramètres → RH → Modèles de contrat.']);

        $organisation = Organization::findOrFail($contrat->organization_id);

        return [$this->instantane($contrat, $organisation, $modele, $this->avantagesDisponibles($contrat), $version), $modele, $organisation];
    }

    /**
     * Instantané figé : tout ce que le PDF affiche, déjà formaté. Refuse la génération si une
     * variable utilisée par le modèle est inconnue ou vide.
     *
     * @param  Collection<int, AffectationRemuneration>  $avantages
     * @return array<string, mixed>
     */
    public function instantane(Contrat $contrat, Organization $organisation, ModeleContrat $modele, Collection $avantages, int $version): array
    {
        $employe = $this->employe($contrat);
        $textesAvantages = $avantages->map(fn (AffectationRemuneration $a) => $this->texteAvantage($a))->values();

        $valeurs = $this->valeursDossier($contrat, $organisation);
        $valeurs['contrat.avantages'] = $textesAvantages->isEmpty() ? 'aucune prime ni aucun avantage' : $textesAvantages->implode(' ; ');
        $valeurs['document.date'] = now()->format('d/m/Y');

        $textes = [$modele->titre, ...collect($modele->articles)->flatMap(fn (array $a) => [$a['titre'], $a['texte']])->all()];

        $refusees = VariablesContrat::refusees($textes, $contrat->type_contrat);
        if ($refusees !== []) {
            throw ValidationException::withMessages(['modele' => 'Le modèle contient des variables non reconnues : '.implode(', ', $refusees).'. Corrigez-le dans Paramètres → RH → Modèles de contrat.']);
        }

        $manquantes = array_values(array_filter(VariablesContrat::extraire($textes), fn (string $cle) => blank($valeurs[$cle] ?? null)));
        if ($manquantes !== []) {
            throw ValidationException::withMessages(['manquantes' => VariablesContrat::messageManquantes($manquantes)]);
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
                'representant_nom' => $valeurs['employeur.representant_nom'],
                'representant_qualite' => $valeurs['employeur.representant_qualite'],
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
}
