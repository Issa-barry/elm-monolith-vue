<script setup lang="ts">
import { formatPourcentage, partEnPourcentage } from '@/lib/pourcentages';
import type {
    ActiviteVehicules,
    BlocsVehicules,
    DepensesVehicules,
    OperationVehicules,
    ParcVehicules,
} from '@/types/dashboard-vehicules';
import { computed } from 'vue';
import { montantCompact, montantGnf } from '../commissions/apollo';
import type { CarteIndicateur, LigneIndicateurs } from '../shared/indicateurs';
import LignesIndicateursApollo from '../shared/LignesIndicateursApollo.vue';
import { decimaleTexte, nombreTexte, pluriel } from './nombres';

// Cartes de Stat-Véhicules, cartes Apollo partagées avec Stat-Stock et Stat-RH
// (LignesIndicateursApollo) : quatre par ligne sur grand écran, deux sur tablette, une sur téléphone.
// - Parc : à ce jour, agence actuelle des véhicules (`vehicules.read`) ;
// - Activité : rotations de la période, agence de l'opération (ventes et/ou transferts selon les
//   droits, absente sans aucun des deux) ;
// - Dépenses : dépenses validées de la période (`depenses.read`, absente sinon).
const props = defineProps<{
    blocs: BlocsVehicules;
    parc: ParcVehicules;
    operations: OperationVehicules[];
    activite: ActiviteVehicules | null;
    depenses: DepensesVehicules | null;
}>();

function ligneParc(p: ParcVehicules): LigneIndicateurs {
    const cartes: CarteIndicateur[] = [
        {
            id: 'actifs',
            titre: 'Véhicules actifs',
            icone: 'pi-car',
            definition:
                'Véhicules actifs aujourd’hui, rattachés à une agence du filtre (agence actuelle du véhicule).',
            valeur: nombreTexte(p.actifs),
            detail: `${pluriel(p.inactifs, 'inactif')} · ${nombreTexte(p.total)} au total`,
        },
        {
            id: 'operationnels',
            titre: 'Opérationnels',
            icone: 'pi-check-circle',
            definition:
                'Véhicules actifs qui peuvent réaliser toutes les opérations de leurs usages, selon les contrôles du serveur (usage, capacités obligatoires, chauffeur actif pour la distribution).',
            valeur: nombreTexte(p.operationnels),
            detail:
                p.actifs > 0
                    ? `${partEnPourcentage(p.operationnels, p.actifs)} des véhicules actifs`
                    : 'Aucun véhicule actif',
        },
    ];

    if (p.en_mission) {
        const m = p.en_mission;
        const parts = [
            m.ventes === null ? null : `${nombreTexte(m.ventes)} en vente`,
            m.transferts === null
                ? null
                : `${nombreTexte(m.transferts)} en transfert`,
        ].filter((x): x is string => x !== null);
        cartes.push({
            id: 'en-mission',
            titre: 'En mission',
            icone: 'pi-send',
            definition: `Véhicules actifs avec ${m.ventes !== null ? 'une vente en chargement ou en livraison (jusqu’au premier encaissement)' : ''}${m.ventes !== null && m.transferts !== null ? ' ou ' : ''}${m.transferts !== null ? 'un transfert en chargement ou en transit' : ''}, dans votre périmètre.`,
            valeur: nombreTexte(m.vehicules),
            detail: parts.join(' · '),
        });
    }

    cartes.push({
        id: 'a-configurer',
        titre: 'À configurer',
        icone: 'pi-wrench',
        definition:
            'Véhicules actifs dont au moins une opération est refusée par le serveur : aucun usage, capacité obligatoire manquante, ou distribution sans chauffeur actif.',
        valeur: nombreTexte(p.a_configurer),
        ton: p.a_configurer > 0 ? 'avertissement' : 'neutre',
        detail:
            p.a_configurer > 0
                ? 'Voir les motifs ci-dessous'
                : 'Aucune configuration à reprendre',
    });

    return {
        id: 'parc',
        titre: 'Parc',
        precision: 'à ce jour',
        cartes,
    };
}

function rotationsVentes(): number {
    return props.operations
        .filter((o) => o.code !== 'transfert')
        .reduce((total, o) => total + (o.rotations ?? 0), 0);
}

function detailRotations(): string {
    const transferts =
        props.operations.find((o) => o.code === 'transfert')?.rotations ?? 0;
    if (props.blocs.ventes && props.blocs.transferts) {
        return `${pluriel(rotationsVentes(), 'vente')} · ${pluriel(transferts, 'transfert')}`;
    }

    return props.blocs.ventes
        ? `${pluriel(rotationsVentes(), 'vente')} · transferts non inclus`
        : `${pluriel(transferts, 'transfert')} · ventes non incluses`;
}

function ligneActivite(a: ActiviteVehicules): LigneIndicateurs {
    const sources =
        props.blocs.ventes && props.blocs.transferts
            ? 'ventes et transferts'
            : props.blocs.ventes
              ? 'ventes seulement'
              : 'transferts seulement';

    return {
        id: 'activite',
        titre: 'Activité',
        precision: `sur la période, agence de l’opération · ${sources}`,
        cartes: [
            {
                id: 'rotations',
                titre: 'Rotations',
                icone: 'pi-sync',
                definition:
                    'Départs chargés d’un véhicule : vente au chargement validé (ou à la validation d’une vente au comptoir), transfert au départ réel. Les ventes non parties et les opérations annulées ne comptent pas.',
                valeur: nombreTexte(a.rotations),
                detail: detailRotations(),
            },
            {
                id: 'utilisation',
                titre: 'Taux d’utilisation',
                icone: 'pi-chart-pie',
                definition:
                    'Véhicules actifs du parc ayant réalisé au moins une rotation comptée sur la période ÷ véhicules actifs du parc à ce jour.',
                valeur:
                    a.taux_utilisation === null
                        ? '—'
                        : formatPourcentage(a.taux_utilisation),
                detail: `${nombreTexte(a.actifs_utilises)} sur ${pluriel(a.actifs, 'véhicule actif', 'véhicules actifs')}`,
            },
            {
                id: 'jours',
                titre: 'Jours d’activité',
                icone: 'pi-calendar',
                definition:
                    'Jours-véhicule : chaque véhicule compte une fois par jour où il a réalisé au moins une rotation, quel que soit le nombre ou le type de ses opérations ce jour-là.',
                valeur: nombreTexte(a.jours_vehicule),
                detail:
                    a.vehicules_utilises > 0
                        ? `${decimaleTexte(a.jours_vehicule / a.vehicules_utilises)} jours par véhicule sorti`
                        : 'Aucun véhicule sorti',
            },
            {
                id: 'retours',
                titre: 'Rotations avec retour',
                icone: 'pi-replay',
                definition:
                    'Rotations dont une partie du chargement est revenue (retour partiel ou total). Écart de réception d’un transfert : reçu + retourné − chargé différent de zéro.',
                valeur: nombreTexte(a.avec_retour),
                detail:
                    a.taux_retour === null
                        ? 'Aucune rotation'
                        : `${formatPourcentage(a.taux_retour)} des rotations${a.transferts_avec_ecart === null ? '' : ` · ${pluriel(a.transferts_avec_ecart, 'écart')} de réception`}`,
            },
        ],
    };
}

function detailCout(d: DepensesVehicules): string {
    if (d.rotations === null) return 'Activité non consultable';
    if (d.rotations === 0) return 'Aucune rotation sur la période';

    return `${montantGnf(d.cout_par_rotation ?? 0)} · ${pluriel(d.rotations, 'rotation')}`;
}

function ligneDepenses(d: DepensesVehicules): LigneIndicateurs {
    return {
        id: 'depenses',
        titre: 'Dépenses',
        precision: 'validées, sur la période, agence de la dépense',
        cartes: [
            {
                id: 'depenses-validees',
                titre: 'Dépenses validées',
                icone: 'pi-wallet',
                definition:
                    'Dépenses au statut Validé imputées à un véhicule (bénéficiaire Véhicule), datées dans la période, dans le périmètre des dépenses.',
                valeur: `${montantCompact(d.montant)} GNF`,
                detail: `${montantGnf(d.montant)} · ${pluriel(d.nombre, 'dépense')}`,
            },
            {
                id: 'cout-rotation',
                titre: 'Coût par rotation',
                icone: 'pi-calculator',
                definition:
                    'Dépenses validées de la période ÷ rotations de la période, dans les mêmes agences. Une dépense ne se répartit pas entre catégories de produits.',
                valeur:
                    d.cout_par_rotation === null
                        ? '—'
                        : `${montantCompact(d.cout_par_rotation)} GNF`,
                detail: detailCout(d),
            },
            {
                id: 'vehicules-depenses',
                titre: 'Véhicules concernés',
                icone: 'pi-car',
                definition:
                    'Véhicules qui ont au moins une dépense validée sur la période.',
                valeur: nombreTexte(d.vehicules),
                detail:
                    d.vehicules > 0
                        ? `${montantGnf(d.montant / d.vehicules)} en moyenne`
                        : 'Aucune dépense validée',
            },
        ],
    };
}

const lignes = computed((): LigneIndicateurs[] => {
    const resultat: LigneIndicateurs[] = [ligneParc(props.parc)];
    if (props.activite) resultat.push(ligneActivite(props.activite));
    if (props.depenses) resultat.push(ligneDepenses(props.depenses));

    return resultat;
});
</script>

<template>
    <LignesIndicateursApollo :lignes="lignes" prefixe="vehicules" />
</template>
