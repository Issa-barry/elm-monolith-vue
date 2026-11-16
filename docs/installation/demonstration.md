# Organisation et accès de démonstration

> **Statut** : en place · **Dernière vérification** : 15/11/2026 (lecture de
> `database/seeders/FelloDemoSeeder.php` et de ses seeders)

## L'organisation « Fello Demo »

Jeu de données de démonstration (boutiques, point de vente, facturation, encaissements, stock,
statistiques), **indépendant** de l'organisation « Eau la maman ». Instance publique :
https://demo.felloconsulting.fr/

```bash
php artisan db:seed --class=FelloDemoSeeder
```

- Le seeder est idempotent : il peut être relancé sans créer de doublons, sur une base déjà migrée.
- Il ne fait pas partie de `DatabaseSeeder` : il ne tourne jamais pendant un `migrate:fresh --seed`.
- **Ne jamais le lancer sur la production ni sur la formation.**

Comptes créés :

| Compte | Identifiant (téléphone) | Rôle | Agence par défaut |
|---|---|---|---|
| Admin Fello Demo | +224600000101 | `admin_entreprise` | Boutique Madina |
| Commercial Fello Demo | +224600000102 | `commerciale` | Boutique Cosa |

## Mots de passe de démonstration

**Aucun mot de passe ne figure dans la documentation.** Les identifiants de connexion sont transmis
par le responsable de l'instance de démonstration, en dehors de Git.

> ⚠️ **Mot de passe compromis.** L'ancien mot de passe de démonstration figurait dans le README :
> il reste lisible dans l'historique Git et doit être considéré comme public. Il est aussi écrit
> dans le code : constante `PASSWORD` de
> `database/seeders/Organizations/FelloDemo/FelloDemoUsersSeeder.php`.
>
> Conséquence : **relancer `FelloDemoSeeder` remet ce mot de passe compromis sur les deux comptes**
> (les comptes sont mis à jour à chaque exécution). Après tout lancement du seeder, changer à
> nouveau les mots de passe sur l'instance.

Règles :

- changer les mots de passe sur l'instance de démonstration, et ne pas réutiliser l'ancien ailleurs ;
- ne jamais écrire un mot de passe, même de démonstration, dans un fichier versionné ;
- partager les accès par un canal privé (gestionnaire de mots de passe, message direct).

Retirer le mot de passe du seeder (le lire depuis une variable d'environnement, par exemple) est une
évolution du code, à traiter séparément.

## Voir aussi

- Déploiement : [hostinger.md](../deploiement/hostinger.md)
- Sommaire : [docs/README.md](../README.md)
