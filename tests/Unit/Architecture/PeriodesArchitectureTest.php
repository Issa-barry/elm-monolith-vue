<?php

namespace Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Contrôle CI de la convention des périodes (ADR 0057, docs/periodes.md) : aucun contrôleur ne
 * calcule ses propres bornes de période ni ne lit lui-même des dates de filtre. Tout passe par
 * App\Support\Periodes\Periode.
 *
 * Les exceptions sont listées ci-dessous avec leur raison. Une exception devenue inutile fait aussi
 * échouer le test : la liste reste exacte.
 */
class PeriodesArchitectureTest extends TestCase
{
    /** Calcul de bornes calendaires ou fonction SQL appliquée à une colonne de date. */
    private const CALCUL_LOCAL = '/->(?:startOf|endOf)(?:Week|Month|Year|Quarter)\(|->sub(?:Week|Month|Year)s?(?:NoOverflow)?\(|->(?:whereDate|whereMonth|whereYear|whereDay)\(/';

    /** Lecture directe d'une date de filtre dans la requête. */
    private const LECTURE_DATES = '/(?:input|query|get|filled|has|only)\([^)]*[\'"](?:date_debut|date_fin|date_from|date_to)[\'"]/';

    /** @var array<string, string> fichier => raison */
    private const EXCEPTIONS_CALCUL = [
        'app/Http/Controllers/Api/Client/Commandes/IndexCommandeController.php' => 'API espace client : migration avec le lot API (décision du 04/11/2026).',
        'app/Http/Controllers/Api/Client/DepensesController.php' => 'API espace client : migration avec le lot API (décision du 04/11/2026).',
        'app/Http/Controllers/Api/Parrain/PaiementsParrainController.php' => 'API espace parrain : migration avec le lot API (décision du 04/11/2026).',
        'app/Http/Controllers/Comptabilite/CommissionProprietaireController.php' => 'Période métier : parcours des mois pour les codes de quinzaine P1/P2.',
        'app/Http/Controllers/Comptabilite/JournalFinancierController.php' => 'Période métier : mois comptable (annee/mois).',
        'app/Http/Controllers/Comptabilite/PaiementPeriodeController.php' => 'Période métier : périodes de paiement (annee/mois/quinzaine).',
        'app/Http/Controllers/Comptabilite/RemisesAgencesController.php' => 'Période métier : mois comptable des remises.',
        'app/Http/Controllers/Comptabilite/SalaireController.php' => 'Période métier : période de paie.',
        'app/Http/Controllers/Testing/CommissionE2eFixturesController.php' => 'Jeu de données des tests E2E, pas un filtre.',
    ];

    /** @var array<string, string> fichier => raison */
    private const EXCEPTIONS_LECTURE = [
        'app/Http/Controllers/Client/EarningsClientDashboardController.php' => 'Param `period` historique de ClientEarningsService, partagé avec l\'API : lot API.',
        'app/Http/Controllers/Client/VehicleBalanceClientDashboardController.php' => 'Param `period` historique de ClientEarningsService, partagé avec l\'API : lot API.',
        'app/Http/Requests/Api/Client/ActiviteMineRequest.php' => 'API espace client (contrat publié) : lot API.',
        'app/Http/Requests/Api/Client/CommandesMineRequest.php' => 'API espace client (contrat publié) : lot API.',
        'app/Http/Requests/Api/Client/CommissionsMineRequest.php' => 'API espace client (contrat publié) : lot API.',
        'app/Http/Requests/Api/Client/DepensesMineRequest.php' => 'API espace client (contrat publié) : lot API.',
        'app/Http/Requests/Api/Parrain/CommissionsParrainRequest.php' => 'API espace parrain (contrat publié) : lot API.',
        'app/Http/Requests/Api/Parrain/PaiementsParrainRequest.php' => 'API espace parrain (contrat publié) : lot API.',
    ];

    public function test_aucun_controleur_ne_calcule_ses_propres_bornes_de_periode(): void
    {
        $this->verifier(self::CALCUL_LOCAL, ['app/Http/Controllers'], self::EXCEPTIONS_CALCUL,
            'Calcul de période local (startOfMonth, subMonth, whereDate…) : passe par App\\Support\\Periodes\\Periode (depuisRequeteValidee, rapide, appliquer).');
    }

    public function test_aucun_controleur_ne_lit_lui_meme_les_dates_de_filtre(): void
    {
        $this->verifier(self::LECTURE_DATES, ['app/Http'], self::EXCEPTIONS_LECTURE,
            'Lecture directe de date_debut/date_fin : utilise Periode::depuisRequeteValidee($request), qui valide et résout la période.');
    }

    /**
     * @param  list<string>  $dossiers
     * @param  array<string, string>  $exceptions
     */
    private function verifier(string $motif, array $dossiers, array $exceptions, string $consigne): void
    {
        $fautifs = [];
        $exceptionsUtilisees = [];

        foreach ($this->fichiersPhp($dossiers) as $fichier) {
            if (preg_match($motif, (string) file_get_contents($this->racine().'/'.$fichier)) !== 1) {
                continue;
            }
            if (array_key_exists($fichier, $exceptions)) {
                $exceptionsUtilisees[] = $fichier;

                continue;
            }
            $fautifs[] = $fichier;
        }

        $this->assertSame([], $fautifs, $consigne."\nFichiers :\n- ".implode("\n- ", $fautifs));
        $this->assertSame(
            [],
            array_values(array_diff(array_keys($exceptions), $exceptionsUtilisees)),
            'Exception devenue inutile : retire-la de la liste de PeriodesArchitectureTest.',
        );
    }

    /**
     * @param  list<string>  $dossiers
     * @return list<string> chemins relatifs à la racine, séparateur /
     */
    private function fichiersPhp(array $dossiers): array
    {
        $fichiers = [];
        foreach ($dossiers as $dossier) {
            $iterateur = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->racine().'/'.$dossier));
            foreach ($iterateur as $fichier) {
                if ($fichier->isFile() && $fichier->getExtension() === 'php') {
                    $fichiers[] = str_replace('\\', '/', substr($fichier->getPathname(), strlen($this->racine()) + 1));
                }
            }
        }
        sort($fichiers);

        return $fichiers;
    }

    private function racine(): string
    {
        return dirname(__DIR__, 3);
    }
}
