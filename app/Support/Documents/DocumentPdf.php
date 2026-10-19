<?php

namespace App\Support\Documents;

/**
 * Données d'une facture ou d'un bon, prêtes à afficher par le modèle officiel partagé
 * (composant `x-pdf.document`, docs/documents-pdf.md). Les constructeurs propres à chaque type
 * (FactureAchatDocument, BonCommandeAchatDocument, FactureVenteDocument) traduisent le métier
 * en textes déjà formatés : le modèle n'interroge aucun modèle Eloquent.
 */
final class DocumentPdf
{
    /**
     * @param  string  $type  identifiant stable du type, exposé dans le rendu (data-type)
     * @param  string  $vue  vue Blade du type, qui délègue au composant partagé
     * @param  list<string>  $lignesAgence  lignes sous le nom de l'organisation
     * @param  list<array{cle: string, valeur: string}>  $metadonnees
     * @param  list<array{titre: string, lignes: list<string>}>  $parties  fournisseur, client, livraison…
     * @param  list<array{libelle: string, numerique: bool, largeur: string}>  $colonnes  la première est la désignation
     * @param  list<array{cellules: list<string>, detail: string|null}>  $lignes
     * @param  list<array{cle: string, valeur: string}>  $totaux
     * @param  array{ton: 'ok'|'attention'|'danger', titre: string, lignes: list<string>}|null  $encadre
     * @param  string|null  $mention  mention légale propre au type, en pied de chaque page
     * @param  string|null  $infoPied  complément du pied de page (date de génération…)
     */
    public function __construct(
        public readonly string $type,
        public readonly string $vue,
        public readonly string $titre,
        public readonly string $reference,
        public readonly IdentiteOrganisation $organisation,
        public readonly array $lignesAgence,
        public readonly array $metadonnees,
        public readonly array $parties,
        public readonly array $colonnes,
        public readonly array $lignes,
        public readonly array $totaux,
        public readonly ?string $notes = null,
        public readonly ?array $encadre = null,
        public readonly ?string $filigrane = null,
        public readonly ?string $mention = null,
        public readonly ?string $infoPied = null,
    ) {}

    public function nomFichier(): string
    {
        return $this->reference.'.pdf';
    }

    /**
     * Colonnes communes à toutes les factures et à tous les bons. Largeurs fixes (DomPDF ne répartit
     * pas le reste à une colonne sans largeur) : un texte long passe à la ligne au lieu d'élargir le
     * tableau.
     *
     * @return list<array{libelle: string, numerique: bool, largeur: string}>
     */
    public static function colonnesStandard(string $designation, string $total): array
    {
        return [
            ['libelle' => $designation, 'numerique' => false, 'largeur' => '46%'],
            ['libelle' => 'Quantité', 'numerique' => true, 'largeur' => '14%'],
            ['libelle' => 'Prix unitaire', 'numerique' => true, 'largeur' => '19%'],
            ['libelle' => $total, 'numerique' => true, 'largeur' => '21%'],
        ];
    }

    public static function montant(float|int|string|null $valeur): string
    {
        return number_format((float) $valeur, 0, ',', ' ').' GNF';
    }
}
