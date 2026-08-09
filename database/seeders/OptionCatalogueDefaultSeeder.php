<?php

namespace Database\Seeders;

use App\Models\OptionCatalogue;
use App\Models\Organization;
use Illuminate\Database\Seeder;

/**
 * Bibliothèque d'options système proposées par défaut à toute organisation — Couleur,
 * Taille, Pointure — avec leurs valeurs usuelles. Marquées `is_system` (non supprimables,
 * cf. OptionCatalogueController::destroy()), mais leurs valeurs restent librement
 * ajoutables/supprimables : ce sont des suggestions de départ, pas une liste fermée.
 *
 * Idempotent (firstOrCreate par organisation+nom, par option+valeur) — peut être relancé
 * sans dupliquer, y compris sur une organisation qui a déjà ses propres options manuelles
 * du même nom (elles sont alors simplement marquées système au lieu d'être dupliquées).
 */
class OptionCatalogueDefaultSeeder extends Seeder
{
    private const OPTIONS = [
        'Couleur' => ['Noir', 'Blanc', 'Bleu', 'Rouge', 'Vert', 'Gris', 'Beige', 'Jaune', 'Marron', 'Marine'],
        'Taille' => ['XS', 'S', 'M', 'L', 'XL', 'XXL'],
        'Pointure' => ['36', '37', '38', '39', '40', '41', '42', '43', '44', '45', '46'],
    ];

    public static function seedPourOrganisation(string $organizationId): void
    {
        foreach (self::OPTIONS as $nom => $valeurs) {
            $option = OptionCatalogue::firstOrCreate(
                ['organization_id' => $organizationId, 'nom' => $nom],
                ['is_system' => true]
            );

            if (! $option->is_system) {
                $option->update(['is_system' => true]);
            }

            $position = $option->valeurs()->max('position');
            $position = $position === null ? 0 : $position + 1;

            foreach ($valeurs as $valeur) {
                $existe = $option->valeurs()->where('valeur', $valeur)->exists();
                if ($existe) {
                    continue;
                }

                $option->valeurs()->create(['valeur' => $valeur, 'position' => $position]);
                $position++;
            }
        }
    }

    public function run(): void
    {
        $org = Organization::where('slug', 'elm')->firstOrFail();
        self::seedPourOrganisation($org->id);

        $this->command->info('✓ Options système (Couleur, Taille, Pointure) prêtes pour « elm ».');
    }
}
