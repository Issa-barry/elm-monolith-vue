<?php

namespace App\Services;

use App\Enums\CategorieJournal;
use App\Enums\SensJournal;
use App\Models\CashbackVersement;
use App\Models\Depense;
use App\Models\EncaissementVente;
use App\Models\JournalTresorerie;
use App\Models\PaiementFichePaiement;

class JournalTresorerieService
{
    public static function enregistrerEncaissement(EncaissementVente $enc): void
    {
        $facture = $enc->facture;
        if (! $facture) {
            return;
        }

        JournalTresorerie::create([
            'organization_id' => $facture->organization_id,
            'site_id' => $facture->site_id,
            'date_operation' => $enc->date_encaissement,
            'sens' => SensJournal::ENTREE->value,
            'categorie' => CategorieJournal::VENTE->value,
            'libelle' => 'Encaissement facture '.($facture->reference ?? $facture->id),
            'reference' => $facture->reference,
            'montant' => (float) $enc->montant,
            'source_type' => EncaissementVente::class,
            'source_id' => $enc->id,
            'created_by' => $enc->created_by,
        ]);
    }

    public static function enregistrerPaiementFiche(PaiementFichePaiement $paiement): void
    {
        $fiche = $paiement->fiche;
        if (! $fiche) {
            return;
        }

        $categorie = match ($fiche->beneficiaire_type) {
            'livreur' => CategorieJournal::COMMISSION_LOGISTIQUE->value,
            'proprietaire' => CategorieJournal::PROPRIETAIRE->value,
            'salarie' => CategorieJournal::SALAIRE->value,
            default => CategorieJournal::AJUSTEMENT->value,
        };

        JournalTresorerie::create([
            'organization_id' => $paiement->organization_id,
            'site_id' => $paiement->site_id,
            'date_operation' => $paiement->date_paiement,
            'sens' => SensJournal::SORTIE->value,
            'categorie' => $categorie,
            'libelle' => 'Paiement fiche '.$fiche->reference.' — '.$fiche->beneficiaire_nom,
            'reference' => $fiche->reference,
            'montant' => (float) $paiement->montant,
            'source_type' => PaiementFichePaiement::class,
            'source_id' => $paiement->id,
            'created_by' => $paiement->created_by,
        ]);
    }

    public static function enregistrerCashback(CashbackVersement $versement): void
    {
        $transaction = $versement->transaction;
        if (! $transaction) {
            return;
        }

        JournalTresorerie::create([
            'organization_id' => $transaction->organization_id,
            'site_id' => $transaction->site_id ?? null,
            'date_operation' => $versement->date_versement,
            'sens' => SensJournal::SORTIE->value,
            'categorie' => CategorieJournal::CASHBACK->value,
            'libelle' => 'Versement cashback',
            'reference' => null,
            'montant' => (float) $versement->montant,
            'source_type' => CashbackVersement::class,
            'source_id' => $versement->id,
            'created_by' => $versement->created_by,
        ]);
    }

    public static function enregistrerDepenseInterne(Depense $depense): void
    {
        JournalTresorerie::create([
            'organization_id' => $depense->organization_id,
            'site_id' => $depense->site_id,
            'date_operation' => $depense->date_depense,
            'sens' => SensJournal::SORTIE->value,
            'categorie' => CategorieJournal::DEPENSE_INTERNE->value,
            'libelle' => $depense->depenseType?->libelle ?? 'Dépense interne',
            'reference' => null,
            'montant' => (float) $depense->montant,
            'source_type' => Depense::class,
            'source_id' => $depense->id,
            'created_by' => $depense->user_id,
        ]);
    }
}
