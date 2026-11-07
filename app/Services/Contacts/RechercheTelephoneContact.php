<?php

namespace App\Services\Contacts;

use App\Models\EntrepriseTierce;
use App\Models\Personne;
use App\Models\User;
use App\Support\Contacts\MessagesContacts;
use App\Support\Contacts\PresentationContacts;

/**
 * « Rechercher » du parcours Nouveau contact (ADR 0060, lot 3, CONTACT-017). Lecture seule : rien
 * n'est créé ni rattaché ici.
 *
 * - libre : aucune fiche de ce type ne porte ce numéro dans l'organisation ;
 * - connu : une fiche visible du lecteur le porte ; elle est présentée (nom, ville, fonctions
 *   visibles, fonctions ajoutables) pour que l'utilisateur choisisse explicitement ;
 * - indisponible : une fiche le porte mais le lecteur ne peut pas la voir (parrain d'une autre
 *   agence, fonction sans permission, compte seul) : message neutre, aucune donnée (décision 1).
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
            return ['statut' => 'libre', ...$base];
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
}
