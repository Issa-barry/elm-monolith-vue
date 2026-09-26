# Cartes de synthèse et informations contextuelles

Référence visuelle : **Comptabilité → Trésorerie → Supports de trésorerie**.
Les pages Supports de trésorerie, Ma situation et Rapport d'activité utilisent
les mêmes composants, sans dupliquer leur présentation.

## Carte de synthèse

Utiliser `resources/js/components/KpiCard.vue` :

```vue
<KpiCard
    title="Solde total"
    :value="formatQuantite(solde)"
    unit="GNF"
    detail="2 caisses dédiées"
/>
```

- `value` est une valeur déjà formatée ; `unit` est affichée séparément et plus petite.
- `detail` est facultatif : une information courte, pas un paragraphe métier.
- `warning` donne une couleur d'avertissement au détail.
- `as="button"` rend la carte interactive ; `active` souligne la sélection.
  La page fournit le clic et les attributs d'accessibilité adaptés au parcours.
- Le slot `indicator` permet d'ajouter un chevron, par exemple dans un onglet.
- `horizontal` place le détail à côté du montant sur un écran suffisamment large.

Conserver Poppins, les tailles, les espacements et l'ombre définis dans le composant.
Adapter le nombre de colonnes à la largeur du contenu plutôt que de réduire les chiffres.
Le nombre reste insécable ; GNF peut passer à la ligne.

```vue
<div class="@container">
    <div class="grid grid-cols-1 gap-7 @[40rem]:grid-cols-2 @[70rem]:grid-cols-4">
        <KpiCard v-for="item in indicateurs" :key="item.id"
            :title="item.titre" :value="item.valeur" :unit="item.unite" />
    </div>
</div>
```

Pour cinq cartes monétaires, les rapports utilisent trois colonnes dès 70 rem
et cinq dès 90 rem de largeur disponible.

## Bouton d'information

Utiliser `resources/js/components/InfoTooltip.vue` à côté du titre :

```vue
<h2 class="flex items-center gap-2 text-base font-semibold">
    Solde total
    <InfoTooltip label="Comprendre le solde total">
        Le solde actuel est calculé à partir du grand livre.
    </InfoTooltip>
</h2>
```

Le composant fournit le ⓘ bleu et l'infobulle au survol ou au focus clavier.
Le libellé accessible est obligatoire. Le slot accepte aussi un contenu structuré.
Conserver les informations indispensables à l'interprétation des montants visibles
dans la page, par exemple « Situation actuelle · Toutes dates » pour les dettes.
Ne pas imbriquer ce bouton dans une carte rendue elle-même comme un bouton.
