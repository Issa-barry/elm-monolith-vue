<?php

namespace Tests\Feature\Documents;

use App\Enums\ModeTarification;
use App\Enums\StatutCommandeAchat;
use App\Enums\StatutFactureFournisseur;
use App\Enums\StatutFactureVente;
use App\Models\Client;
use App\Models\CommandeAchat;
use App\Models\CommandeAchatLigne;
use App\Models\CommandeVente;
use App\Models\CommandeVenteLigne;
use App\Models\EncaissementVente;
use App\Models\FactureFournisseur;
use App\Models\FactureFournisseurLigne;
use App\Models\FactureVente;
use App\Models\Fournisseur;
use App\Models\Organization;
use App\Models\Personne;
use App\Models\Site;
use App\Models\User;
use App\Models\Vehicule;
use App\Support\Documents\BonCommandeAchatDocument;
use App\Support\Documents\DocumentPdf;
use App\Support\Documents\DocumentPdfRenderer;
use App\Support\Documents\FactureAchatDocument;
use App\Support\Documents\FactureVenteDocument;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

/**
 * Modèle officiel partagé des factures et bons (docs/documents-pdf.md) : un seul composant
 * Blade, alimenté par un DocumentPdf préparé par type. Fixtures en mémoire (modèles non
 * enregistrés, relations posées) : seul le rendu est testé ici ; l'accès HTTP l'est par les tests
 * des contrôleurs.
 */
class ModeleDocumentPdfTest extends TestCase
{
    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = new Organization(['name' => 'Eau La Maman']);
    }

    private function rendre(DocumentPdf $document): string
    {
        return view($document->vue, ['document' => $document])->render();
    }

    /** @return array<string, DocumentPdf> */
    private function tousLesDocuments(): array
    {
        return [
            'facture_achat' => FactureAchatDocument::depuis($this->factureAchat(), $this->org),
            'bon_commande_achat' => BonCommandeAchatDocument::depuis($this->bonCommande(), $this->org),
            'facture_vente' => FactureVenteDocument::depuis($this->factureVente(), $this->org),
        ];
    }

    public function test_les_factures_et_bons_utilisent_le_meme_modele(): void
    {
        foreach ($this->tousLesDocuments() as $type => $document) {
            $html = $this->rendre($document);

            $this->assertSame($type, $document->type);
            $this->assertStringContainsString('data-modele="document-officiel"', $html, $type);
            $this->assertStringContainsString('data-type="'.$type.'"', $html, $type);
            $this->assertSame(1, substr_count($html, '<style>'), $type);

            // La vue du type ne fait que déléguer au composant : ni HTML ni CSS dupliqués.
            $source = File::get(resource_path('views/'.str_replace('.', '/', $document->vue).'.blade.php'));
            $this->assertStringContainsString('<x-pdf.document :document="$document" />', $source, $type);
            $this->assertStringNotContainsString('<style', $source, $type);
            $this->assertStringNotContainsString('<table', $source, $type);
        }
    }

    public function test_une_modification_du_bloc_partage_s_applique_a_tous_les_documents(): void
    {
        $dossier = storage_path('framework/testing/modele-'.uniqid());
        File::ensureDirectoryExists($dossier.'/pdf/modele');
        File::put($dossier.'/pdf/modele/pied.blade.php', '<div class="pied-page">PIED-MODIFIE {{ $document->reference }}</div>');

        try {
            View::getFinder()->prependLocation($dossier);
            View::getFinder()->flush();

            foreach ($this->tousLesDocuments() as $type => $document) {
                $this->assertStringContainsString('PIED-MODIFIE '.$document->reference, $this->rendre($document), $type);
            }
        } finally {
            File::deleteDirectory($dossier);
            View::getFinder()->setPaths(array_values(array_diff(View::getFinder()->getPaths(), [$dossier])));
            View::getFinder()->flush();
        }
    }

    public function test_la_mention_d_original_n_appartient_qu_a_la_facture_d_achat(): void
    {
        $documents = $this->tousLesDocuments();

        $this->assertStringContainsString(FactureAchatDocument::MENTION, $this->rendre($documents['facture_achat']));
        $this->assertStringNotContainsString('original du fournisseur', $this->rendre($documents['bon_commande_achat']));
        $this->assertStringNotContainsString('original du fournisseur', $this->rendre($documents['facture_vente']));
    }

    public function test_facture_d_achat_conserve_ses_donnees_et_filigranes(): void
    {
        $facture = $this->factureAchat();
        $html = $this->rendre(FactureAchatDocument::depuis($facture, $this->org));

        foreach (['FACTURE D’ACHAT', 'FAC-121026-001', 'Sans numéro', 'Topaz', '666177006, Guinée', 'Rouleau', '3 000 GNF', 'TVA (0 %)', 'TOTAL TTC', 'Agence : Agence Principale', 'BROUILLON'] as $attendu) {
            $this->assertStringContainsString($attendu, $html);
        }

        $facture->statut = StatutFactureFournisseur::VALIDEE;
        $this->assertNull(FactureAchatDocument::depuis($facture, $this->org)->filigrane);
        $facture->statut = StatutFactureFournisseur::ANNULEE;
        $this->assertSame('ANNULÉE', FactureAchatDocument::depuis($facture, $this->org)->filigrane);
    }

    public function test_bon_de_commande_conserve_snapshot_validation_et_filigranes(): void
    {
        $bon = $this->bonCommande();
        $html = $this->rendre(BonCommandeAchatDocument::depuis($bon, $this->org));
        foreach (['BON DE COMMANDE', 'BCA-181026-001', 'Agence de livraison : Kaloum', 'Payé par : Siège', 'LIVRER À', 'Émis par Issa Barry', 'Réf. ROUL-01', 'NON VALIDÉ', 'En attente de validation', 'Généré le'] as $attendu) {
            $this->assertStringContainsString($attendu, $html);
        }

        // Numéro déjà international : l'indicatif n'est pas répété (« +224 +224… »).
        $fournisseur = new Fournisseur;
        $fournisseur->setRelation('personne', (new Personne)->forceFill(['telephone' => '+224666177005', 'code_phone_pays' => '+224']));
        $fournisseur->setRelation('entrepriseTierce', null);
        $bon->setRelation('fournisseur', $fournisseur);
        $parties = BonCommandeAchatDocument::depuis($bon, $this->org)->parties;
        $this->assertSame(['Topaz', '+224 666 17 70 05'], $parties[0]['lignes']);

        $bon->forceFill(['validee_at' => Carbon::parse('2026-10-18 09:30'), 'statut' => StatutCommandeAchat::VALIDEE, 'validation_regle_snapshot' => ['role_label' => 'Directeur', 'plafond' => 500000]]);
        $bon->setRelation('valideePar', $this->utilisateur('Awa', 'Camara'));
        $valide = BonCommandeAchatDocument::depuis($bon, $this->org);
        $html = $this->rendre($valide);
        $this->assertNull($valide->filigrane);
        $this->assertStringContainsString('Bon de commande validé', $html);
        $this->assertStringContainsString('Le 18/10/2026 à 09:30 par Awa Camara — rôle Directeur, plafond 500 000 GNF', $html);

        $bon->forceFill(['statut' => StatutCommandeAchat::ANNULEE, 'motif_annulation' => 'Doublon', 'annulee_at' => Carbon::parse('2026-10-18 10:00')]);
        $annule = BonCommandeAchatDocument::depuis($bon, $this->org);
        $this->assertSame('ANNULÉ', $annule->filigrane);
        $this->assertSame(['ton' => 'danger', 'titre' => 'Bon de commande annulé', 'lignes' => ['Doublon', 'Le 18/10/2026 à 10:00']], $annule->encadre);
    }

    public function test_facture_de_vente_reprend_montants_et_quantites_du_metier_sans_tva(): void
    {
        $facture = $this->factureVente();
        $document = FactureVenteDocument::depuis($facture, $this->org);
        $html = $this->rendre($document);

        // Quantité = livrée (nette des retours) ; prix = mode de tarification figé ; total = total_ligne.
        $this->assertSame(['Pack Eau 1,5 L', '90', '4 500 GNF', '405 000 GNF'], $document->lignes[0]['cellules']);
        $this->assertSame(['Pack Eau 0,5 L', '40', '3 000 GNF', '120 000 GNF'], $document->lignes[1]['cellules']);
        $this->assertSame([
            ['cle' => 'TOTAL', 'valeur' => '525 000 GNF'],
            ['cle' => 'ENCAISSÉ', 'valeur' => '300 000 GNF'],
            ['cle' => 'DONT ACOMPTES', 'valeur' => '100 000 GNF'],
            ['cle' => 'REMBOURSÉ', 'valeur' => '50 000 GNF'],
            ['cle' => 'RESTE À PAYER', 'valeur' => '275 000 GNF'],
        ], $document->totaux);

        foreach (['FACTURE', 'CMD-181026-004', 'Partiellement payée', 'CLIENT', 'Mamadou Diallo', 'VÉHICULE', 'Thierno-Moto', 'Agence : Kaloum'] as $attendu) {
            $this->assertStringContainsString($attendu, $html);
        }
        $this->assertStringNotContainsString('TVA', $html);
        $this->assertNull($document->filigrane);
        $this->assertNull($document->mention);
        $this->assertSame('CMD-181026-004.pdf', $document->nomFichier());
    }

    public function test_facture_de_vente_prix_usine_et_quantite_chargee(): void
    {
        $facture = $this->factureVente();
        $facture->commande->mode_tarification_snapshot = ModeTarification::PRIX_USINE;
        $ligne = $facture->commande->lignes[0];
        $ligne->forceFill(['quantite_livree' => null, 'quantite_chargee' => 80, 'total_ligne' => 320000]);

        $document = FactureVenteDocument::depuis($facture, $this->org);

        $this->assertSame(['Pack Eau 1,5 L', '80', '4 000 GNF', '320 000 GNF'], $document->lignes[0]['cellules']);
    }

    public function test_facture_de_vente_payee_annulee_et_trop_percu(): void
    {
        $payee = $this->factureVente(['statut_facture' => StatutFactureVente::PAYEE, 'montant_rembourse' => 0], [['montant' => 525000, 'est_acompte' => false]]);
        $document = FactureVenteDocument::depuis($payee, $this->org);
        $this->assertNull($document->filigrane);
        $this->assertContains(['cle' => 'RESTE À PAYER', 'valeur' => '0 GNF'], $document->totaux);

        $annulee = $this->factureVente(['statut_facture' => StatutFactureVente::ANNULEE, 'montant_rembourse' => 0], []);
        $document = FactureVenteDocument::depuis($annulee, $this->org);
        $this->assertSame('ANNULÉE', $document->filigrane);
        $this->assertStringContainsString('ANNULÉE', $this->rendre($document));
        $this->assertNotContains('RESTE À PAYER', array_column($document->totaux, 'cle'));

        // Précommande préparée en moins : le client a versé plus que ce qui lui est facturé (ADR 0019).
        $tropPercu = $this->factureVente(['montant_brut' => 400000, 'montant_net' => 400000, 'montant_rembourse' => 0], [['montant' => 450000, 'est_acompte' => true]]);
        $document = FactureVenteDocument::depuis($tropPercu, $this->org);
        $this->assertContains(['cle' => 'TROP-PERÇU À RENDRE', 'valeur' => '50 000 GNF'], $document->totaux);
    }

    public function test_logo_par_defaut_ou_personnalise_identique_pour_tous_les_documents(): void
    {
        foreach ($this->tousLesDocuments() as $type => $document) {
            $this->assertStringStartsWith('data:image/svg+xml;base64,', $document->organisation->logo, $type);
        }

        Storage::fake('public');
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jD1sAAAAASUVORK5CYII=');
        Storage::disk('public')->put('organizations/logo.png', $png);
        $this->org->logo_path = 'organizations/logo.png';

        foreach ($this->tousLesDocuments() as $type => $document) {
            $this->assertStringContainsString('data:image/png;base64,'.base64_encode($png), $this->rendre($document), $type);
        }
    }

    public function test_pdf_sur_plusieurs_pages_avec_textes_longs(): void
    {
        $facture = $this->factureVente();
        $lignes = collect(range(1, 60))->map(fn (int $i) => $this->ligneVente([
            'libelle_snapshot' => $i % 10 === 0 ? str_repeat('Désignation très longue ', 12).$i : 'Pack '.$i,
            'quantite_livree' => 10, 'prix_vente_snapshot' => 1000, 'total_ligne' => 10000,
        ]));
        $facture->commande->setRelation('lignes', $lignes);
        $facture->commande->setRelation('client', new Client(['nom_complet' => str_repeat('Établissements Barry ', 10), 'adresse' => str_repeat('Quartier Madina ', 10)]));

        $pdf = DocumentPdfRenderer::pdf(FactureVenteDocument::depuis($facture, $this->org));
        $pdf->render();
        $sortie = $pdf->output();

        $this->assertStringStartsWith('%PDF-', $sortie);
        $this->assertGreaterThan(1, $pdf->getDomPDF()->getCanvas()->get_page_count());
    }

    // ── Fixtures en mémoire ──────────────────────────────────────────────────

    private function factureAchat(): FactureFournisseur
    {
        $facture = (new FactureFournisseur)->forceFill([
            'reference' => 'FAC-121026-001', 'numero_facture_fournisseur' => null, 'date_facture' => '2026-10-12',
            'taux_tva' => 0, 'montant_ht' => 3000, 'montant_tva' => 0, 'montant_ttc' => 3000,
            'statut' => StatutFactureFournisseur::BROUILLON, 'fournisseur_nom_snapshot' => 'Topaz',
        ]);
        $facture->setRelation('site', new Site(['nom' => 'Agence Principale']));
        $fournisseur = new Fournisseur;
        $fournisseur->setRelation('personne', (new Personne)->forceFill(['adresse' => '666177006', 'pays' => 'Guinée']));
        $fournisseur->setRelation('entrepriseTierce', null);
        $facture->setRelation('fournisseur', $fournisseur);
        $facture->setRelation('lignes', collect([new FactureFournisseurLigne(['libelle_snapshot' => 'Rouleau', 'qte_facturee' => 1, 'prix_unitaire' => 3000, 'total_ht' => 3000])]));

        return $facture;
    }

    private function bonCommande(): CommandeAchat
    {
        $bon = (new CommandeAchat)->forceFill([
            'reference' => 'BCA-181026-001', 'date_achat' => '2026-10-18', 'statut' => StatutCommandeAchat::A_VALIDER,
            'site_id' => 's1', 'site_payeur_id' => 's2', 'total_commande' => 30000, 'fournisseur_nom_snapshot' => 'Topaz',
        ]);
        $ligne = new CommandeAchatLigne(['libelle_snapshot' => 'Rouleau', 'reference_snapshot' => 'ROUL-01', 'qte' => 10, 'prix_achat_snapshot' => 3000, 'total_ligne' => 30000]);
        $ligne->setRelation('variante', null);
        $bon->setRelation('lignes', collect([$ligne]));
        $bon->setRelation('fournisseur', null);
        $bon->setRelation('createdBy', $this->utilisateur('Issa', 'Barry'));
        $bon->setRelation('valideePar', null);
        $bon->setRelation('site', new Site(['nom' => 'Kaloum']));
        $bon->setRelation('sitePayeur', new Site(['nom' => 'Siège']));

        return $bon;
    }

    /**
     * @param  array<string, mixed>  $attributs
     * @param  list<array{montant: int, est_acompte: bool}>|null  $encaissements
     */
    private function factureVente(array $attributs = [], ?array $encaissements = null): FactureVente
    {
        $commande = (new CommandeVente)->forceFill([
            'reference' => 'CMD-181026-004', 'mode_tarification_snapshot' => ModeTarification::PRIX_VENTE, 'est_precommande' => false,
        ]);
        $commande->setRelation('site', new Site(['nom' => 'Kaloum']));
        $commande->setRelation('client', new Client(['nom_complet' => 'Mamadou Diallo', 'telephone' => '+224622123456', 'ville' => 'Conakry']));
        $commande->setRelation('vehicule', new Vehicule(['nom_vehicule' => 'Thierno-Moto', 'immatriculation' => 'RC-1234-A']));
        $commande->setRelation('lignes', collect([
            // 100 chargés, 10 retournés : livrée 90 (CommandeVenteRetourService).
            $this->ligneVente(['libelle_snapshot' => 'Pack Eau 1,5 L', 'quantite_demandee' => 100, 'quantite_chargee' => 100, 'quantite_retournee' => 10, 'quantite_livree' => 90, 'prix_usine_snapshot' => 4000, 'prix_vente_snapshot' => 4500, 'total_ligne' => 405000]),
            $this->ligneVente(['libelle_snapshot' => 'Pack Eau 0,5 L', 'quantite_demandee' => 40, 'prix_usine_snapshot' => 2500, 'prix_vente_snapshot' => 3000, 'total_ligne' => 120000]),
        ]));

        $facture = (new FactureVente)->forceFill(array_merge([
            'reference' => 'CMD-181026-004', 'montant_brut' => 525000, 'montant_net' => 525000, 'montant_rembourse' => 50000,
            'statut_facture' => StatutFactureVente::PARTIEL, 'created_at' => '2026-10-18 08:00:00',
        ], $attributs));
        $facture->setRelation('commande', $commande);
        $facture->setRelation('site', null);
        $facture->setRelation('encaissements', collect(array_map(
            fn (array $e) => new EncaissementVente($e),
            $encaissements ?? [['montant' => 100000, 'est_acompte' => true], ['montant' => 200000, 'est_acompte' => false]],
        )));

        return $facture;
    }

    private function utilisateur(string $prenom, string $nom): User
    {
        return (new User)->setRelation('personne', (new Personne)->forceFill(['prenom' => $prenom, 'nom' => $nom]));
    }

    /** @param  array<string, mixed>  $attributs */
    private function ligneVente(array $attributs): CommandeVenteLigne
    {
        $ligne = (new CommandeVenteLigne)->forceFill($attributs);
        $ligne->setRelation('variante', null);

        return $ligne;
    }
}
