# ADR 0041 — Reprise de la transmission RH par la Comptabilité

- **Date** : 2026-10-29
- **Statut** : accepté — lot 5 livré le 2026-10-29 (non commité)
- **Périmètre** : Comptabilité → Paiement salaire, frontière RH → Comptabilité — cf.
  [paie.md](../paie.md), [transmission-paie.md](../transmission-paie.md)

## Contexte

Après le lot 4 (ADR 0040), les RH valident et transmettent chaque mois une version figée des
éléments de rémunération. La paie de la Comptabilité continuait pourtant à générer ses lignes depuis
les contrats, à la première consultation du mois : deux calculs du même salaire coexistaient, sans
les primes ni les avantages, et pouvaient diverger. Les gains saisis à la main sur l'ancien écran
Paie RH contournaient aussi le référentiel. Enfin, une dépense validée après la validation de la
paie modifiait encore la période validée (risque R8 de la conception du 26/10/2026).

## Décision

1. **Une seule source pour les gains** : les lignes d'une période de paie ne viennent que de la
   **reprise** de la version transmise du mois (`App\Services\Comptabilite\ReprisePaieService`),
   lue par `SourceDonneesPaie`. Rien n'est recalculé : par salarié, salaire de base (lignes
   « contrat »), primes et indemnités versées, et **avantages en nature à part**
   (`paie_lignes.total_avantages_nature`, jamais dans le brut ni le net). La génération depuis les
   contrats (`PaieCalculService::genererLignes`) est supprimée ; la période est créée vide.
2. La Comptabilité n'ajoute que **ses retenues** (dépenses validées : avances, retenues…), puis
   calcule le net et le reste à payer. Une variable de gain saisie à la main n'est plus acceptée ni
   comptée.
3. **Reprise** (bouton « Reprendre la transmission RH », permission existante `rh-paie.update`,
   toutes les agences) :
   - seulement la version **transmise en vigueur** du mois, celle affichée à l'écran : brouillon,
     version validée non transmise, version remplacée, version d'un autre mois ou d'une autre
     organisation refusés avec un message explicite ;
   - **une seule fois** par version (`prendreEnCharge`, mise à jour conditionnelle) ; une version
     n'alimente qu'une période (index unique `paie_periodes.transmission_paie_id`) ;
   - contenu vérifié par son **empreinte** à la lecture ; après création des lignes, contrôle de
     **concordance** (nombre de salariés, total versé, total valorisé) avec la version ;
   - **une transaction**, verrou sur l'organisation (le même que la transmission RH) puis sur la
     période : en cas d'erreur, ni ligne, ni prise en charge, ni journal ; la reprise peut être
     relancée.
4. **Nouvelle version avant validation** : la période reste sur la version reprise et l'écran
   demande de reprendre la nouvelle ; ses lignes sont alors remplacées (aucun paiement n'est
   possible avant validation). La reprise de la version précédente reste tracée (prise en charge,
   journal « remplace la version N »).
5. **Validation de la période** : refusée tant que la période ne porte pas la version transmise en
   vigueur ; même verrou d'organisation que la transmission RH (pas de transmission et de
   validation simultanées). Après validation, plus de reprise ni de nouvelle version (TRANS-015).
6. **Paiement** : inchangé (`PaiementSalaireService`, ADR 0037).
7. **Non-rétroactivité** : une période validée n'est plus jamais modifiée par une dépense. Une
   dépense validée après la validation de son mois est reprise en **régularisation** dans le
   premier mois suivant encore ouvert (libellé « (régularisation MM/AAAA) »), une seule fois.
8. **Prévision de trésorerie** (financement des agences) : tant que rien n'est repris, estimation
   fournie par les RH (`SourceDonneesPaie::estimationVersements` : version transmise, sinon calcul
   du moment), avant retenues ; elle n'écrit plus rien.
9. Journal (module `salaires`) : version reprise, nombre de salariés, totaux versé / valorisé / net,
   version remplacée. Pas de détail par salarié.

## Alternatives écartées

- **Table de rubriques côté Comptabilité** (`paie_ligne_rubriques`, conception du 26/10) : la
  version transmise est déjà une copie figée, protégée par son empreinte ; le détail par salarié est
  lu dans la version reprise. Une seconde copie n'apporterait qu'un risque d'écart.
- **Reprise automatique à la consultation** : refusée, la reprise est un acte tracé et autorisé.
- **Nouvelle permission dédiée** : `rh-paie.update` (« modifier une période de paie ») couvre déjà
  la préparation ; aucune matrice de rôle n'est modifiée.

## Conséquences

- Migration `2026_10_30_100000_add_reprise_transmission_to_paie_tables` (colonnes nullables / à 0,
  aucune donnée modifiée).
- Un mois sans transmission RH n'a plus de lignes de paie : la Comptabilité ne peut ni le valider
  ni le payer.
- Ancien écran Paie RH : lecture et paiement inchangés ; ses variables sont limitées aux retenues.
  Son retrait (ADR 0037) reste à décider.
- Une dépense **dévalidée** après la validation de sa paie n'est pas régularisée automatiquement
  (point ouvert).
