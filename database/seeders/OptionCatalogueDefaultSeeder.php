<?php

namespace Database\Seeders;

use App\Models\OptionCatalogue;
use App\Models\Organization;
use Illuminate\Database\Seeder;

/**
 * Bibliothèque d'options système proposées par défaut à toute organisation — un socle assez
 * large pour couvrir la plupart des profils de commerce (habillement, chaussures, boissons,
 * alimentaire, électronique, construction, cosmétique...) sans forcer personne à tout créer à
 * la main. Marquées `is_system` (non supprimables, cf. OptionCatalogueController::destroy()),
 * mais leurs valeurs restent librement ajoutables/supprimables : ce sont des suggestions de
 * départ, pas une liste fermée.
 *
 * Pour les options à valeurs vraiment universelles (Couleur, Taille, Pointure, Stockage, RAM...)
 * on préremplit une liste usuelle. Pour les options dimensionnelles/métier (Longueur, Largeur,
 * Hauteur, Épaisseur, Diamètre, Capacité, Format/Dimension, Parfum/Saveur) on crée seulement
 * l'option, sans imposer de valeurs — chaque métier a ses propres graduations (un vendeur de fer
 * n'a pas les mêmes diamètres qu'un plombier).
 *
 * Idempotent (firstOrCreate par organisation+nom, par option+valeur) — peut être relancé sans
 * dupliquer, y compris sur une organisation qui a déjà ses propres options manuelles du même nom
 * (elles sont alors simplement marquées système au lieu d'être dupliquées).
 */
class OptionCatalogueDefaultSeeder extends Seeder
{
    /**
     * Couleur : valeur => hex (aide visuelle uniquement, cf. migration hex sur
     * option_catalogue_valeurs — jamais utilisé pour de la logique métier).
     */
    private const COULEURS = [
        'Noir' => '#000000',
        'Blanc' => '#FFFFFF',
        'Gris' => '#808080',
        'Argent' => '#C0C0C0',
        'Beige' => '#E8DCC8',
        'Marron' => '#7B4B2A',
        'Bronze' => '#CD7F32',
        'Rouge' => '#E53935',
        'Rose' => '#F48FB1',
        'Orange' => '#FB8C00',
        'Jaune' => '#FDD835',
        'Or' => '#D4AF37',
        'Vert' => '#43A047',
        'Bleu' => '#1E88E5',
        'Marine' => '#0D1B4C',
        'Violet' => '#8E24AA',
    ];

    /** @var array<string, array<int, string>> */
    private const OPTIONS = [
        'Couleur' => [], // cf. self::COULEURS, gérée séparément pour porter le hex
        'Taille' => ['XXS', 'XS', 'S', 'M', 'L', 'XL', 'XXL', 'XXXL'],
        'Pointure' => [], // cf. genererPointures(), demi-pointures 35 → 47
        'Genre' => ['Homme', 'Femme', 'Unisexe'],
        'Matière' => ['Coton', 'Polyester', 'Lin', 'Laine', 'Cuir', 'Similicuir', 'Bois', 'Métal', 'Plastique', 'Verre'],
        'Volume' => ['25 cl', '33 cl', '50 cl', '75 cl', '1 L', '1,5 L', '2 L', '5 L', '10 L', '20 L'],
        'Poids' => ['100 g', '250 g', '500 g', '1 kg', '2 kg', '5 kg', '10 kg', '25 kg', '50 kg'],
        'Conditionnement' => ['Unité', 'Lot', 'Paquet', 'Carton', 'Pack', 'Sac', 'Rouleau', 'Palette'],
        'Capacité' => [],
        'Longueur' => [],
        'Largeur' => [],
        'Hauteur' => [],
        'Épaisseur' => [],
        'Diamètre' => [],
        'Format / Dimension' => [],
        'Stockage' => ['64 Go', '128 Go', '256 Go', '512 Go', '1 To', '2 To'],
        'Mémoire RAM' => ['4 Go', '8 Go', '16 Go', '32 Go', '64 Go'],
        'Connectivité' => ['3G', '4G', '5G', 'Wi-Fi', 'Wi-Fi + Cellular'],
        'Coupe' => ['Slim', 'Regular', 'Oversize'],
        'Finition' => ['Mat', 'Brillant', 'Satiné'],
        'Parfum / Saveur' => [],
    ];

    public static function seedPourOrganisation(string $organizationId): void
    {
        foreach (self::OPTIONS as $nom => $valeurs) {
            $option = self::firstOrCreateOption($organizationId, $nom);

            $donnees = match ($nom) {
                'Couleur' => self::COULEURS,
                'Pointure' => array_fill_keys(self::genererPointures(), null),
                default => array_fill_keys($valeurs, null),
            };

            self::ajouterValeursManquantes($option, $donnees);
        }
    }

    /**
     * Demi-pointures de 35 à 47 (35, 35.5, 36, 36.5 ... 47) — la plupart des chausseurs
     * proposent des demis, notamment sur les pointures adultes les plus courantes.
     *
     * @return array<int, string>
     */
    private static function genererPointures(): array
    {
        $pointures = [];
        for ($p = 35; $p <= 47; $p += 0.5) {
            // "35.0" -> "35", "35.5" reste "35.5" — évite un affichage "35.0" disgracieux.
            $pointures[] = rtrim(rtrim(number_format($p, 1), '0'), '.');
        }

        return $pointures;
    }

    private static function firstOrCreateOption(string $organizationId, string $nom): OptionCatalogue
    {
        $option = OptionCatalogue::firstOrCreate(
            ['organization_id' => $organizationId, 'nom' => $nom],
            ['is_system' => true]
        );

        if (! $option->is_system) {
            $option->update(['is_system' => true]);
        }

        return $option;
    }

    /**
     * @param  array<string, string|null>  $valeursEtHex  valeur => hex (null si non applicable)
     */
    private static function ajouterValeursManquantes(OptionCatalogue $option, array $valeursEtHex): void
    {
        if (empty($valeursEtHex)) {
            return;
        }

        $position = $option->valeurs()->max('position');
        $position = $position === null ? 0 : $position + 1;

        foreach ($valeursEtHex as $valeur => $hex) {
            $existante = $option->valeurs()->where('valeur', $valeur)->first();
            if ($existante) {
                // Complète le hex d'une valeur système déjà présente (ex: créée avant l'ajout
                // de la colonne hex) sans jamais écraser un hex personnalisé déjà renseigné.
                if ($hex && ! $existante->hex) {
                    $existante->update(['hex' => $hex]);
                }

                continue;
            }

            $option->valeurs()->create(['valeur' => $valeur, 'hex' => $hex, 'position' => $position]);
            $position++;
        }
    }

    public function run(): void
    {
        $org = Organization::where('slug', 'elm')->firstOrFail();
        self::seedPourOrganisation($org->id);

        $this->command->info('✓ Bibliothèque d\'options système ('.count(self::OPTIONS).' options) prête pour « elm ».');
    }
}
