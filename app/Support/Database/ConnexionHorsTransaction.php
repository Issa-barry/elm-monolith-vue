<?php

namespace App\Support\Database;

use Illuminate\Database\Connection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Seconde connexion à la même base, hors de la transaction en cours (ADR 0069, décision 9), pour
 * LIRE ce qui est validé à cet instant, sans verrou :
 *
 * - une lecture faite dans la transaction lit sa vue REPEATABLE READ, figée à sa première lecture ;
 * - une lecture verrouillante (FOR UPDATE) d'une plage, ou d'une ligne absente, pose des verrous
 *   d'intervalle, et deux transactions qui insèrent ensuite dans cette plage s'interbloquent.
 *
 * Jamais d'écriture par cette connexion : elle attendrait les verrous que la transaction en cours
 * tient déjà (une clé étrangère vers une ligne verrouillée, par exemple), soit 50 s d'attente puis un
 * échec, l'appelant ne pouvant pas valider avant qu'elle n'aboutisse.
 *
 * Sous SQLite (tests), une seule transaction écrit à la fois et la base en mémoire n'existe que sur
 * sa connexion : la connexion d'origine est rendue telle quelle. Hors transaction aussi.
 *
 * Conditions d'emploi (détail : ADR 0069, « Connexion hors transaction ») :
 * - toujours le serveur PRINCIPAL : la copie ne garde ni `read`, ni `write`, ni `sticky`. Un réplica
 *   en retard rendrait un solde périmé, sans erreur visible ;
 * - dérivée de la connexion de la transaction appelante : celle sur laquelle le verrou a été pris ;
 * - plusieurs instances de l'application : sans effet, la garantie repose sur les verrous de la
 *   base, jamais sur la mémoire du processus ;
 * - une connexion de plus par requête qui fait une sortie d'argent, pour le temps de la requête.
 */
final class ConnexionHorsTransaction
{
    public static function pour(Connection $connexion): Connection
    {
        if ($connexion->getDriverName() === 'sqlite' || $connexion->transactionLevel() === 0) {
            return $connexion;
        }

        $nom = $connexion->getName().'__hors_transaction';
        if (config("database.connections.{$nom}") === null) {
            // Sans `name` : Laravel la garderait, et la copie se présenterait sous le nom de la
            // connexion d'origine (une lecture par ce nom repartirait dans la transaction). Sans
            // `read`/`write`/`sticky` : la configuration d'une connexion à réplicas est déjà celle du
            // serveur principal, et doit le rester.
            config(["database.connections.{$nom}" => Arr::except($connexion->getConfig(), ['name', 'read', 'write', 'sticky'])]);
        }

        return DB::connection($nom);
    }
}
