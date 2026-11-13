<?php

namespace App\Services\Contacts;

use App\Models\EntrepriseTierce;
use App\Models\Personne;
use App\Models\User;
use App\Support\Contacts\MessagesContacts;
use App\Support\Contacts\NumeroSecondaire;
use App\Support\Contacts\PresentationContacts;

/**
 * « Rechercher » du parcours Nouveau contact (ADR 0060, lot 3, CONTACT-017). Lecture seule : rien
 * n'est créé ni rattaché ici.
 *
 * - libre : aucune fiche de ce type ne porte ce numéro dans l'organisation ;
 * - connu : une fiche visible du lecteur le porte ; elle est présentée (nom, ville, fonctions
 *   visibles, fonctions ajoutables) pour que l'utilisateur choisisse explicitement ;
 * - indisponible : une fiche le porte mais le lecteur ne peut pas la voir (parrain d'une autre
 *   agence, fonction sans permission, compte seul) : message neutre, aucune donnée (décision 1) ;
 * - secondaire (lot 9b) : numéro secondaire d'une fiche visible ; aucune création ni rattachement.
 */
final class RechercheTelephoneContact
{
    public function __construct(
        private readonly VisibiliteContacts $visibilite,
        private readonly FonctionsContactEcriture $fonctions,
    ) {}

    /**
     * @param  'personne'|'entreprise'  $type
     * @param  array{telephone: string, code_pays: string, indicatif: string, pays: string}  $numero  numéro normalisé
     * @return array<string, mixed>
     */
    public function rechercher(string $type, array $numero, User $lecteur): array
    {
        $chiffres = Personne::normaliserTelephone($numero['telephone']);
        $classe = $type === 'personne' ? Personne::class : EntrepriseTierce::class;

        $fiche = $classe::where('organization_id', $lecteur->organization_id)
            ->where('telephone_normalise', $chiffres)
            ->first();

        $base = [
            'telephone' => $numero['telephone'],
            'code_pays' => $numero['code_pays'],
            'indicatif' => $numero['indicatif'],
            'pays' => $numero['pays'],
        ];

        if (! $fiche) {
            return $this->numeroSecondaire($classe, $type, $chiffres, $lecteur, $base) ?? ['statut' => 'libre', ...$base];
        }

        $visible = $fiche instanceof Personne
            ? $this->visibilite->personneVisible($fiche, $lecteur)
            : $this->visibilite->entrepriseVisible($fiche, $lecteur);

        if (! $visible) {
            return ['statut' => 'indisponible', 'message' => MessagesContacts::NUMERO_NON_VISIBLE, ...$base];
        }

        $presentation = new PresentationContacts($lecteur->organization_id);
        if ($fiche instanceof Personne) {
            $fonctions = $this->visibilite->fonctionsDesPersonnes(collect([$fiche]), $lecteur)[$fiche->id] ?? [];
            $nom = $presentation->nomPersonne($fiche, $fonctions);
            $lien = "/backoffice/contacts/personnes/{$fiche->id}";
        } else {
            $fonctions = $this->visibilite->fonctionsDesEntreprises(collect([$fiche]), $lecteur)[$fiche->id] ?? [];
            $nom = $fiche->raison_sociale;
            $lien = "/backoffice/contacts/entreprises/{$fiche->id}";
        }

        return [
            'statut' => 'connu',
            ...$base,
            'fiche' => [
                'id' => $fiche->id,
                'type' => $type,
                'nom' => $nom,
                'ville' => $fiche->ville,
                'lien' => $lien,
                'fonctions' => $presentation->fonctions($fonctions),
                'fonctions_ajoutables' => $this->fonctions->fonctionsAjoutables($fiche, $lecteur),
            ],
            'message_autre_personne' => MessagesContacts::AUTRE_PERSONNE,
        ];
    }

    /**
     * Lot 9b (décision 3) : numéro secondaire d'une fiche — reconnu comme tel, présenté avec un lien
     * vers la fiche, sans fonction ajoutable ni rattachement automatique ; message neutre si la fiche
     * n'est pas visible.
     *
     * @param  class-string<Personne|EntrepriseTierce>  $classe
     * @return array<string, mixed>|null
     */
    private function numeroSecondaire(string $classe, string $type, string $chiffres, User $lecteur, array $base): ?array
    {
        $fiche = $classe::where('organization_id', $lecteur->organization_id)->where('telephone_secondaire_normalise', $chiffres)->first();
        if (! $fiche) {
            return null;
        }

        $visible = $fiche instanceof Personne
            ? $this->visibilite->personneVisible($fiche, $lecteur)
            : $this->visibilite->entrepriseVisible($fiche, $lecteur);
        if (! $visible) {
            return ['statut' => 'indisponible', 'message' => MessagesContacts::NUMERO_NON_VISIBLE, ...$base];
        }

        $presentation = new PresentationContacts($lecteur->organization_id);
        $dossier = $fiche instanceof Personne ? 'personnes' : 'entreprises';
        $nom = $fiche instanceof Personne
            ? $presentation->nomPersonne($fiche, $this->visibilite->fonctionsDesPersonnes(collect([$fiche]), $lecteur)[$fiche->id] ?? [])
            : $fiche->raison_sociale;

        return [
            'statut' => 'secondaire',
            'message' => MessagesContacts::NUMERO_SECONDAIRE,
            ...$base,
            'fiche' => ['id' => $fiche->id, 'type' => $type, 'nom' => $nom, 'ville' => $fiche->ville, 'lien' => "/backoffice/contacts/{$dossier}/{$fiche->id}"],
        ];
    }

    /**
     * « Rechercher » de l'ajout d'un interlocuteur (lot 4). Mêmes règles que rechercher() : une
     * personne non visible du lecteur ne renvoie qu'un message neutre (décision 1 du lot 4).
     * Statuts : libre, connu, deja_interlocuteur, indisponible.
     *
     * @param  array{telephone: string, code_pays: string, indicatif: string, pays: string}  $numero
     * @return array<string, mixed>
     */
    public function rechercherInterlocuteur(EntrepriseTierce $entreprise, array $numero, User $lecteur): array
    {
        $base = ['telephone' => $numero['telephone'], 'indicatif' => $numero['indicatif']];
        $personne = Personne::where('organization_id', $entreprise->organization_id)
            ->where('telephone_normalise', Personne::normaliserTelephone($numero['telephone']))
            ->first();

        if (! $personne) {
            // CONTACT-063 : numéro secondaire d'une fiche — aucune nouvelle personne avec lui.
            $secondaire = NumeroSecondaire::fiche(Personne::class, $entreprise->organization_id, Personne::normaliserTelephone($numero['telephone']));

            return $secondaire !== null
                ? ['statut' => 'indisponible', 'message' => NumeroSecondaire::message($secondaire, $lecteur), ...$base]
                : ['statut' => 'libre', ...$base];
        }
        if (! $this->visibilite->personneVisible($personne, $lecteur)) {
            return ['statut' => 'indisponible', 'message' => MessagesContacts::NUMERO_NON_VISIBLE, ...$base];
        }

        $presentation = new PresentationContacts($lecteur->organization_id);
        $fonctions = $this->visibilite->fonctionsDesPersonnes(collect([$personne]), $lecteur)[$personne->id] ?? [];
        $lien = $entreprise->interlocuteurs()->where('personne_id', $personne->id)->first();

        return [
            'statut' => $lien ? 'deja_interlocuteur' : 'connu',
            ...$base,
            'personne' => [
                'id' => $personne->id,
                'nom' => $presentation->nomPersonne($personne, $fonctions),
                'ville' => $personne->ville,
                'fonctions' => $presentation->fonctions($fonctions),
            ],
            'lien_actif' => $lien?->is_active,
            'message_autre_personne' => MessagesContacts::AUTRE_PERSONNE,
        ];
    }
}
