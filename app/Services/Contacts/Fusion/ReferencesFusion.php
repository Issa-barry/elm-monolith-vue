<?php

namespace App\Services\Contacts\Fusion;

use App\Models\ContactsFusion;
use App\Models\Personne;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Registre des liens vers une fiche Personne ou Entreprise que la fusion transfère (lot 9b). Aucun
 * historique métier n'y figure : ventes, factures, paiements, commissions et écritures pointent vers
 * les fonctions, jamais vers la fiche (audit du lot 9, ADR 0060) — une fonction change de fiche avec
 * tout son historique, sans être recréée ni réaffectée.
 *
 * Toute nouvelle clé étrangère vers `personnes` ou `entreprises_tierces` doit être ajoutée ici (ou
 * dans EXCLUES) : le test ReferencesFusionTest compare ce registre au schéma.
 *
 * Les lignes sont lues sans filtre de suppression logique : une fonction archivée garde son
 * historique et change de fiche comme les autres.
 *
 * @phpstan-type Reference array{table: string, colonne: string, role: string, fonction?: string, morph?: list<string>}
 */
final class ReferencesFusion
{
    /** Clés étrangères volontairement non transférées. */
    public const EXCLUES = [
        // Journal du lot 6 (reprise des propriétaires entreprises) : historique, jamais réécrit.
        'contacts_reprise_lignes.entreprise_tierce_id',
        // Lien de la fiche absorbée vers la fiche conservée (lot 9b).
        'personnes.fusionnee_dans_id',
        'entreprises_tierces.fusionnee_dans_id',
    ];

    /** @var list<Reference> */
    private const PERSONNE = [
        ['table' => 'clients', 'colonne' => 'personne_id', 'role' => 'fonction', 'fonction' => 'client'],
        ['table' => 'fournisseurs', 'colonne' => 'personne_id', 'role' => 'fonction', 'fonction' => 'fournisseur'],
        ['table' => 'prestataires', 'colonne' => 'personne_id', 'role' => 'fonction', 'fonction' => 'prestataire'],
        ['table' => 'proprietaires', 'colonne' => 'personne_id', 'role' => 'fonction', 'fonction' => 'proprietaire'],
        ['table' => 'parrains', 'colonne' => 'personne_id', 'role' => 'fonction', 'fonction' => 'parrain'],
        ['table' => 'livreurs', 'colonne' => 'personne_id', 'role' => 'fonction', 'fonction' => 'livreur'],
        ['table' => 'employes', 'colonne' => 'personne_id', 'role' => 'fonction', 'fonction' => 'employe'],
        ['table' => 'users', 'colonne' => 'personne_id', 'role' => 'compte'],
        ['table' => 'entreprise_interlocuteurs', 'colonne' => 'personne_id', 'role' => 'interlocuteur'],
        ['table' => 'entreprises_tierces', 'colonne' => 'contact_personne_id', 'role' => 'contact'],
        ['table' => 'personne_contact_categories', 'colonne' => 'personne_id', 'role' => 'categorie'],
        ['table' => 'pieces_identite', 'colonne' => 'identifiable_id', 'role' => 'piece', 'morph' => ['personne', Personne::class]],
    ];

    /** @var list<Reference> */
    private const ENTREPRISE = [
        ['table' => 'fournisseurs', 'colonne' => 'entreprise_tierce_id', 'role' => 'fonction', 'fonction' => 'fournisseur'],
        ['table' => 'prestataires', 'colonne' => 'entreprise_tierce_id', 'role' => 'fonction', 'fonction' => 'prestataire'],
        ['table' => 'proprietaires', 'colonne' => 'entreprise_tierce_id', 'role' => 'fonction', 'fonction' => 'proprietaire'],
        ['table' => 'clients', 'colonne' => 'entreprise_tierce_id', 'role' => 'fonction', 'fonction' => 'client'],
        ['table' => 'entreprise_interlocuteurs', 'colonne' => 'entreprise_tierce_id', 'role' => 'lien_interlocuteur'],
        ['table' => 'entreprise_contact_categories', 'colonne' => 'entreprise_tierce_id', 'role' => 'categorie'],
    ];

    /** @return list<Reference> */
    public static function pour(string $type): array
    {
        return $type === ContactsFusion::TYPE_PERSONNE ? self::PERSONNE : self::ENTREPRISE;
    }

    /** Table de la fiche elle-même. */
    public static function tableFiche(string $type): string
    {
        return $type === ContactsFusion::TYPE_PERSONNE ? 'personnes' : 'entreprises_tierces';
    }

    /**
     * Lignes qui pointent vers cette fiche, archivées comprises, triées par id.
     *
     * @param  Reference  $reference
     * @return Collection<int, object>
     */
    public static function lignes(array $reference, string $ficheId): Collection
    {
        return DB::table($reference['table'])
            ->where($reference['colonne'], $ficheId)
            ->when(isset($reference['morph']), fn ($q) => $q->whereIn('identifiable_type', $reference['morph']))
            ->orderBy('id')
            ->get();
    }
}
