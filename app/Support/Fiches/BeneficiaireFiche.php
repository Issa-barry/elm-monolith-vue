<?php

namespace App\Support\Fiches;

use App\Models\Livreur;
use App\Models\PaiementFiche;
use App\Models\Parrain;
use App\Models\Proprietaire;
use Illuminate\Support\Collection;

/**
 * Nom et téléphone du bénéficiaire d'une fiche de paiement, pour l'affichage et l'export. Le nom
 * enregistré dans la fiche (`beneficiaire_nom`, photographie au calcul) est conservé, sauf s'il est
 * vide ou n'est que l'identifiant technique (personne sans nom au moment du calcul) : le nom actuel
 * de la personne le remplace alors, à défaut « <Type> sans nom ». Le téléphone est toujours lu sur
 * la personne actuelle (livreur, propriétaire, parrain) ; null pour les autres bénéficiaires.
 */
final class BeneficiaireFiche
{
    private const MODELES = [
        'livreur' => Livreur::class,
        'proprietaire' => Proprietaire::class,
        'parrain' => Parrain::class,
    ];

    private const SANS_NOM = [
        'livreur' => 'Livreur sans nom',
        'proprietaire' => 'Propriétaire sans nom',
        'parrain' => 'Parrain sans nom',
    ];

    /**
     * Une requête par type de bénéficiaire présent.
     *
     * @param  Collection<int, PaiementFiche>  $fiches
     * @return array<string, array{nom: string, telephone: ?string}> clé « type:id »
     */
    public static function resoudre(Collection $fiches): array
    {
        $personnes = [];
        foreach ($fiches->groupBy('beneficiaire_type') as $type => $groupe) {
            $modele = self::MODELES[$type] ?? null;
            if ($modele === null) {
                continue;
            }
            $modele::withTrashed()->with('personne')
                ->whereIn('id', $groupe->pluck('beneficiaire_id')->unique())
                ->get()
                ->each(function ($b) use ($type, &$personnes) {
                    $personnes["{$type}:{$b->id}"] = $b;
                });
        }

        $resultat = [];
        foreach ($fiches as $fiche) {
            $cle = "{$fiche->beneficiaire_type}:{$fiche->beneficiaire_id}";
            $personne = $personnes[$cle] ?? null;
            $resultat[$cle] = [
                'nom' => self::nom($fiche, $personne?->nom_complet ?: null),
                'telephone' => $personne?->telephone ?: null,
            ];
        }

        return $resultat;
    }

    /** Nom à afficher : la photographie de la fiche, sauf si elle est vide ou n'est que l'identifiant. */
    public static function nom(PaiementFiche $fiche, ?string $nomActuel): string
    {
        $photographie = trim((string) $fiche->beneficiaire_nom);
        if ($photographie !== '' && $photographie !== $fiche->beneficiaire_id) {
            return $photographie;
        }

        return $nomActuel ?: (self::SANS_NOM[$fiche->beneficiaire_type] ?? 'Bénéficiaire sans nom');
    }

    /** Nom à enregistrer au calcul d'une fiche de parrain : jamais son identifiant technique. */
    public static function nomParrain(?Parrain $parrain): string
    {
        return ($parrain?->nom_complet ?: null) ?? self::SANS_NOM['parrain'];
    }
}
