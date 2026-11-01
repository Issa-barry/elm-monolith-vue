<?php

namespace App\Services\ImportRh;

use App\Enums\StatutEmploye;
use App\Enums\TypeContrat;
use App\Models\Employe;
use App\Models\FonctionRh;
use App\Models\ServiceRh;
use App\Models\User;
use App\Services\ImportFlotte\Normalizers\ImportDateNormalizer;
use App\Services\ImportFlotte\Normalizers\ImportMontantNormalizer;
use App\Services\ImportFlotte\Normalizers\ImportTextNormalizer;
use App\Services\ImportFlotte\Normalizers\ReferenceValueResolver;
use App\Services\Rh\PdgService;
use App\Services\SiteScopeService;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Analyse (lecture seule) d'un fichier de mise à jour des dossiers et contrats RH — règles
 * IMPRH-* de docs/import-dossiers-rh.md :
 *
 * - une ligne par salarié EXISTANT, retrouvé par son matricule dans l'organisation et les agences
 *   d'écriture de l'acteur ; jamais de création de salarié ;
 * - liste fermée de colonnes lues (ColonnesImportRh) ; cellule vide = valeur conservée ;
 * - service et fonction : libellé ou code d'un référentiel ACTIF, jamais créés ;
 * - contrat : créé seulement si le salarié n'a pas de contrat actif ; un contrat actif existant
 *   n'est jamais modifié (différent du fichier = erreur) ;
 * - salaire : lu seulement avec `rh-remunerations.read`, pris seulement à la création du contrat.
 *   Les changements de salaire sont marqués `sensible` et aucun message d'erreur ne cite un montant ;
 * - sans PDG désigné (ADR 0048) : aucun contrat n'est créé ; la ligne n'est PAS en erreur (une
 *   erreur bloquerait tout le fichier) : le dossier est mis à jour, le contrat et son salaire sont
 *   écartés avec un avertissement.
 *
 * L'exécution (ImportRhExecutor) relance cette analyse au moment de confirmer.
 */
class ImportRhParser
{
    /** Même garde-fou que les autres imports (traitement synchrone dans la requête). */
    private const MAX_LIGNES = 500;

    private const FORMAT_DATE = 'd/m/Y';

    public const AVERTISSEMENT_SANS_PDG = 'Contrat (et son salaire) non créé : aucun PDG n’est désigné pour l’organisation (signataire des contrats). Le dossier est bien mis à jour. Désignez le PDG (RH → Employés → fiche de la personne → « Désigner comme PDG »), puis créez le contrat dans RH → Contrats ou réimportez cette ligne.';

    public function __construct(
        private readonly SiteScopeService $siteScope,
        private readonly PdgService $pdg,
    ) {}

    /**
     * @return array{nb_lignes_total: int, erreur_globale: ?string, colonne_salaire_ignoree: bool, lignes: list<array>}
     */
    public function analyserFichier(string $absolutePath, User $acteur): array
    {
        $sheet = $this->trouverFeuille(IOFactory::load($absolutePath));
        if (! $sheet) {
            return $this->erreurGlobale('Fichier vide, ou aucune feuille exploitable.');
        }

        // Valeurs brutes (formatData = false) : une date saisie comme date arrive en numéro de série,
        // lu par ImportDateNormalizer, plutôt qu'en texte formaté selon les réglages du poste.
        $tableau = $sheet->toArray(null, true, false, false);
        if (count($tableau) < 1) {
            return $this->erreurGlobale('Fichier vide.');
        }

        $entetes = array_map(fn ($e) => strtolower(trim((string) $e)), array_shift($tableau));
        if (! in_array(ColonnesImportRh::MATRICULE, $entetes, true)) {
            return $this->erreurGlobale('Colonne « matricule » introuvable : utilisez le fichier « Exporter pour mise à jour ».');
        }

        $nbColonnes = count($entetes);
        $lignes = collect($tableau)
            ->map(fn ($ligne) => array_combine($entetes, array_slice(array_pad($ligne, $nbColonnes, null), 0, $nbColonnes)))
            ->filter(fn (array $ligne) => collect($ligne)->filter(fn ($v) => trim((string) $v) !== '')->isNotEmpty())
            ->values();

        if ($lignes->isEmpty()) {
            return $this->erreurGlobale('Aucune ligne à importer dans la feuille « employes ».');
        }

        if ($lignes->count() > self::MAX_LIGNES) {
            return $this->erreurGlobale(sprintf('Le fichier contient trop de lignes (%d), maximum autorisé : %d.', $lignes->count(), self::MAX_LIGNES));
        }

        $voitSalaires = $acteur->can('rh-remunerations.read');
        $colonneSalaire = in_array(ColonnesImportRh::SALAIRE, $entetes, true);

        $contexte = [
            'acteur' => $acteur,
            'lit_salaire' => $voitSalaires && $colonneSalaire,
            'peut_creer_contrat' => $acteur->can('rh-contrats.create'),
            'pdg_designe' => $this->pdg->actuel($acteur->organization_id) !== null,
            'sites_ecriture' => $this->siteScope->assignedSiteIds($acteur),
            'employes' => Employe::with(['personne', 'service', 'fonction', 'contratActif'])
                ->where('organization_id', $acteur->organization_id)
                ->get()
                ->keyBy('matricule'),
            'services' => ServiceRh::where('organization_id', $acteur->organization_id)->get(),
            'fonctions' => FonctionRh::where('organization_id', $acteur->organization_id)->get(),
            'occurrences' => $lignes->map(fn ($l) => $this->normaliserMatricule($l[ColonnesImportRh::MATRICULE] ?? null))
                ->filter()
                ->countBy(),
        ];

        $rapport = [];
        foreach ($lignes as $index => $ligne) {
            $rapport[] = $this->analyserLigne($index + 2, $ligne, $contexte);
        }

        return [
            'nb_lignes_total' => $lignes->count(),
            'erreur_globale' => null,
            // Colonne salaire présente mais non lue (permission manquante) : signalé une seule fois.
            'colonne_salaire_ignoree' => $colonneSalaire && ! $voitSalaires,
            'lignes' => $rapport,
        ];
    }

    private function analyserLigne(int $numero, array $ligne, array $contexte): array
    {
        $matricule = $this->normaliserMatricule($ligne[ColonnesImportRh::MATRICULE] ?? null);
        $resultat = [
            'numero_ligne' => $numero,
            'matricule' => $matricule,
            'employe_id' => null,
            'nom' => null,
            'statut' => 'inchange',
            'erreurs' => [],
            'avertissements' => [],
            'changements' => [],
            'mise_a_jour' => ['employe' => [], 'fonction_rh_id' => null, 'contrat' => null],
            'valeurs' => $this->valeursLues($ligne, $contexte['lit_salaire']),
        ];

        $employe = $this->resoudreEmploye($matricule, $contexte, $resultat);
        if ($employe === null) {
            return $this->conclure($resultat);
        }

        $resultat['employe_id'] = $employe->id;
        $resultat['nom'] = $employe->nom_complet;

        $dateEmbauche = $this->analyserDossier($ligne, $employe, $contexte, $resultat);
        $contratCree = $this->analyserContrat($ligne, $employe, $contexte, $dateEmbauche, $resultat);
        $contratEcarteSansPdg = in_array(self::AVERTISSEMENT_SANS_PDG, $resultat['avertissements'], true);

        // Contrat écarté faute de PDG : son salaire l'est aussi (il n'est pris qu'à la création).
        if ($contexte['lit_salaire'] && ! $contratEcarteSansPdg) {
            $this->analyserSalaire($ligne, $employe, $contratCree, $resultat);
        }

        return $this->conclure($resultat);
    }

    private function resoudreEmploye(string $matricule, array $contexte, array &$resultat): ?Employe
    {
        if ($matricule === '') {
            $resultat['erreurs'][] = 'Matricule manquant.';

            return null;
        }

        if (($contexte['occurrences'][$matricule] ?? 0) > 1) {
            $resultat['erreurs'][] = 'Ce matricule figure sur plusieurs lignes du fichier.';

            return null;
        }

        /** @var ?Employe $employe */
        $employe = $contexte['employes']->get($matricule);
        if ($employe === null) {
            // Même message qu'il appartienne à une autre organisation ou n'existe pas : rien ne fuit.
            $resultat['erreurs'][] = 'Matricule introuvable dans votre organisation (l\'import ne crée jamais de salarié).';

            return null;
        }

        $sites = $contexte['sites_ecriture'];
        if ($sites->isNotEmpty() && ($employe->site_id === null || ! $sites->contains($employe->site_id))) {
            $resultat['erreurs'][] = 'Ce salarié ne relève pas de vos agences.';

            return null;
        }

        return $employe;
    }

    /** Date d'embauche, service, fonction. Retourne la date d'embauche applicable (fichier ou actuelle). */
    private function analyserDossier(array $ligne, Employe $employe, array $contexte, array &$resultat): ?string
    {
        $actuelle = $employe->date_embauche?->toDateString();
        [$date, $erreur] = ImportDateNormalizer::toDate($ligne[ColonnesImportRh::DATE_EMBAUCHE] ?? null);
        if ($erreur) {
            $resultat['erreurs'][] = "Date d'embauche : {$erreur}";
        } elseif ($date !== null && $date !== $actuelle) {
            $resultat['mise_a_jour']['employe']['date_embauche'] = $date;
            $this->changement($resultat, 'date_embauche', "Date d'embauche", $this->dateFr($actuelle), $this->dateFr($date));
        }

        $service = $this->resoudreReferentiel($ligne[ColonnesImportRh::SERVICE] ?? null, $contexte['services'], 'Service', $resultat);
        if ($service !== null && $service->id !== $employe->service_rh_id) {
            $resultat['mise_a_jour']['employe']['service_rh_id'] = $service->id;
            $this->changement($resultat, 'service', 'Service', $employe->service?->libelle, $service->libelle);
        }

        $fonction = $this->resoudreReferentiel($ligne[ColonnesImportRh::FONCTION] ?? null, $contexte['fonctions'], 'Fonction', $resultat);
        if ($fonction !== null && $fonction->id !== $employe->fonction_rh_id) {
            $resultat['mise_a_jour']['fonction_rh_id'] = $fonction->id;
            $this->changement($resultat, 'fonction', 'Fonction', $employe->fonction?->libelle, $fonction->libelle);
        }

        return $date ?? $actuelle;
    }

    /** Service ou fonction : libellé ou code exact d'un élément ACTIF ; jamais créé. */
    private function resoudreReferentiel(mixed $brut, Collection $elements, string $libelle, array &$resultat): ServiceRh|FonctionRh|null
    {
        $saisi = trim((string) ($brut ?? ''));
        if ($saisi === '') {
            return null;
        }

        $trouve = ReferenceValueResolver::matchExact($saisi, $elements, [fn ($e) => $e->libelle, fn ($e) => $e->code]);
        if ($trouve === null) {
            $suggestion = ReferenceValueResolver::suggestClosest($saisi, $elements->where('is_active', true), fn ($e) => $e->libelle);
            $resultat['erreurs'][] = "{$libelle} « {$saisi} » inconnu(e)".($suggestion ? " — vouliez-vous dire « {$suggestion} » ?" : ' (voir la feuille « references »).');

            return null;
        }

        if (! $trouve->is_active) {
            $resultat['erreurs'][] = "{$libelle} « {$trouve->libelle} » désactivé(e) : réactivez-le dans Paramètres → RH ou choisissez-en un autre.";

            return null;
        }

        return $trouve;
    }

    /** Retourne true si un contrat sera créé. */
    private function analyserContrat(array $ligne, Employe $employe, array $contexte, ?string $dateEmbauche, array &$resultat): bool
    {
        $brutType = trim((string) ($ligne[ColonnesImportRh::CONTRAT_TYPE] ?? ''));
        $brutDebut = $ligne[ColonnesImportRh::CONTRAT_DATE_DEBUT] ?? null;
        $brutFin = $ligne[ColonnesImportRh::CONTRAT_DATE_FIN] ?? null;

        if ($brutType === '' && trim((string) $brutDebut) === '' && trim((string) $brutFin) === '') {
            return false;
        }

        $type = TypeContrat::tryFrom(strtolower($brutType));
        if ($type === null) {
            $resultat['erreurs'][] = $brutType === ''
                ? 'Type de contrat manquant (CDI ou CDD).'
                : "Type de contrat « {$brutType} » inconnu (CDI ou CDD).";
        }

        [$debut, $erreurDebut] = ImportDateNormalizer::toDate($brutDebut);
        [$fin, $erreurFin] = ImportDateNormalizer::toDate($brutFin);
        if ($erreurDebut) {
            $resultat['erreurs'][] = "Début du contrat : {$erreurDebut}";
        } elseif ($debut === null) {
            $resultat['erreurs'][] = 'Date de début du contrat manquante.';
        }
        if ($erreurFin) {
            $resultat['erreurs'][] = "Fin du contrat : {$erreurFin}";
        }

        if ($type === null || $debut === null || $erreurDebut || $erreurFin) {
            return false;
        }

        if ($type === TypeContrat::CDD && $fin === null) {
            $resultat['erreurs'][] = 'Un CDD doit avoir une date de fin.';

            return false;
        }
        if ($type === TypeContrat::CDI && $fin !== null) {
            $resultat['erreurs'][] = 'Un CDI n\'a pas de date de fin : videz la colonne contrat_date_fin.';

            return false;
        }
        if ($fin !== null && $fin < $debut) {
            $resultat['erreurs'][] = 'La fin du contrat précède son début.';

            return false;
        }

        $actif = $employe->contratActif;
        if ($actif !== null) {
            $identique = $actif->type_contrat === $type
                && $actif->date_debut?->toDateString() === $debut
                && $actif->date_fin?->toDateString() === $fin;

            if (! $identique) {
                $resultat['erreurs'][] = sprintf(
                    'Ce salarié a déjà un contrat actif (%s depuis le %s) différent du fichier : un contrat existant se modifie dans l\'écran Contrats, jamais par l\'import.',
                    strtoupper($actif->type_contrat->value),
                    $actif->date_debut?->format(self::FORMAT_DATE),
                );
            }

            return false;
        }

        if ($employe->statut === StatutEmploye::SORTI) {
            $resultat['erreurs'][] = 'Salarié sorti : aucun contrat actif ne peut lui être créé.';

            return false;
        }

        if (! $contexte['peut_creer_contrat']) {
            $resultat['erreurs'][] = 'Droit manquant pour créer un contrat (permission « Contrats — créer »).';

            return false;
        }

        if (! $contexte['pdg_designe']) {
            $resultat['avertissements'][] = self::AVERTISSEMENT_SANS_PDG;

            return false;
        }

        if ($dateEmbauche !== null && $debut < $dateEmbauche) {
            $resultat['avertissements'][] = 'Le contrat commence avant la date d\'embauche.';
        }

        $resultat['mise_a_jour']['contrat'] = ['type_contrat' => $type->value, 'date_debut' => $debut, 'date_fin' => $fin, 'salaire_base' => null];
        $this->changement(
            $resultat,
            'contrat',
            'Contrat',
            null,
            strtoupper($type->value).' à partir du '.$this->dateFr($debut).($fin ? ' jusqu\'au '.$this->dateFr($fin) : ''),
        );

        return true;
    }

    /** Salaire : pris à la création du contrat seulement. Aucun message ne cite de montant. */
    private function analyserSalaire(array $ligne, Employe $employe, bool $contratCree, array &$resultat): void
    {
        [$montant, $erreur] = ImportMontantNormalizer::toMontant($ligne[ColonnesImportRh::SALAIRE] ?? null);
        if ($erreur) {
            $resultat['erreurs'][] = "Salaire de base : {$erreur}";

            return;
        }
        if ($montant === null) {
            return;
        }

        if ($contratCree) {
            $resultat['mise_a_jour']['contrat']['salaire_base'] = $montant;
            $this->changement($resultat, 'salaire_base', 'Salaire de base', null, $montant, true);

            return;
        }

        $actif = $employe->contratActif;
        if ($actif === null) {
            $resultat['erreurs'][] = 'Salaire sans contrat : renseignez aussi le type et la date de début du contrat.';

            return;
        }

        // Un contrat actif existant n'est jamais modifié par l'import (décision D3), salaire compris :
        // identique = inchangé, sinon erreur.
        if ($actif->salaire_base === null) {
            $resultat['erreurs'][] = 'Le contrat actif n\'a pas de salaire : renseignez-le dans l\'écran Contrats (l\'import ne modifie jamais un contrat existant).';

            return;
        }

        if (abs((float) $actif->salaire_base - $montant) > 0.009) {
            $resultat['erreurs'][] = 'Le contrat actif a déjà un salaire différent : l\'import ne modifie jamais un contrat existant (écran Contrats).';
        }
    }

    private function changement(array &$resultat, string $champ, string $libelle, mixed $avant, mixed $apres, bool $sensible = false): void
    {
        $resultat['changements'][] = compact('champ', 'libelle', 'avant', 'apres', 'sensible');
    }

    private function conclure(array $resultat): array
    {
        $resultat['statut'] = match (true) {
            $resultat['erreurs'] !== [] => 'erreur',
            $resultat['changements'] !== [] => 'mise_a_jour',
            default => 'inchange',
        };

        return $resultat;
    }

    /**
     * Valeurs lues, pour le fichier d'erreurs. Le salaire n'y figure que s'il est lu (permission) ;
     * il est marqué sensible par sa clé et masqué à l'affichage sans la permission.
     */
    private function valeursLues(array $ligne, bool $litSalaire): array
    {
        $colonnes = [ColonnesImportRh::MATRICULE, ColonnesImportRh::DATE_EMBAUCHE, ColonnesImportRh::SERVICE, ColonnesImportRh::FONCTION, ...ColonnesImportRh::CONTRAT];
        $valeurs = [];
        foreach ($colonnes as $colonne) {
            $valeurs[$colonne] = $this->texteCellule($colonne, $ligne[$colonne] ?? null);
        }
        if ($litSalaire) {
            $valeurs[ColonnesImportRh::SALAIRE] = trim((string) ($ligne[ColonnesImportRh::SALAIRE] ?? ''));
        }

        return $valeurs;
    }

    private function texteCellule(string $colonne, mixed $valeur): string
    {
        if (in_array($colonne, [ColonnesImportRh::DATE_EMBAUCHE, ColonnesImportRh::CONTRAT_DATE_DEBUT, ColonnesImportRh::CONTRAT_DATE_FIN], true)) {
            [$date] = ImportDateNormalizer::toDate($valeur);
            if ($date !== null) {
                return $this->dateFr($date);
            }
        }
        if ($colonne === ColonnesImportRh::MATRICULE) {
            return $this->normaliserMatricule($valeur);
        }

        return trim((string) ($valeur ?? ''));
    }

    /** Matricule sur 6 chiffres : un matricule devenu nombre dans Excel (4213) retrouve ses zéros. */
    private function normaliserMatricule(mixed $valeur): string
    {
        $brut = trim((string) ($valeur ?? ''));
        if (is_float($valeur) && floor($valeur) === $valeur) {
            $brut = (string) (int) $valeur;
        }

        return ctype_digit($brut) && strlen($brut) < 6 ? str_pad($brut, 6, '0', STR_PAD_LEFT) : $brut;
    }

    private function dateFr(?string $iso): ?string
    {
        return $iso ? \DateTime::createFromFormat('!Y-m-d', $iso)->format(self::FORMAT_DATE) : null;
    }

    private function trouverFeuille(Spreadsheet $spreadsheet): ?Worksheet
    {
        $cible = ImportTextNormalizer::normalize(ColonnesImportRh::FEUILLE);
        foreach ($spreadsheet->getAllSheets() as $sheet) {
            if (ImportTextNormalizer::normalize($sheet->getTitle()) === $cible) {
                return $sheet;
            }
        }

        return $spreadsheet->getSheetCount() > 0 ? $spreadsheet->getSheet(0) : null;
    }

    private function erreurGlobale(string $message): array
    {
        return ['nb_lignes_total' => 0, 'erreur_globale' => $message, 'colonne_salaire_ignoree' => false, 'lignes' => []];
    }
}
