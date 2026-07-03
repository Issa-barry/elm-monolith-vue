<script setup lang="ts">
import QrCodeTicket from '@/components/print/QrCodeTicket.vue';
import { computed } from 'vue';

interface LigneCommande {
    id: string;
    produit_nom: string | null;
    quantite_demandee: number;
    quantite_chargee: number | null;
    prix_vente_snapshot: number;
    total_ligne: number;
}

interface CommandeForTicket {
    id: string;
    reference: string;
    statut_label: string;
    created_at: string;
    site_nom: string | null;
    vehicule_nom: string | null;
    client_nom: string | null;
    total_commande: number;
    created_by: string | null;
    is_brouillon: boolean;
    is_a_charger: boolean;
    lignes: LigneCommande[];
}

const props = defineProps<{
    commande: CommandeForTicket;
    orgNom: string;
    currentUser: string;
}>();

const quantiteTotale = computed(() =>
    props.commande.lignes.reduce((sum, l) => sum + l.quantite_demandee, 0),
);

const showChargee = computed(
    () => !props.commande.is_brouillon && !props.commande.is_a_charger,
);

const qrUrl = computed(
    () => `${window.location.origin}/backoffice/ventes/${props.commande.id}`,
);

function formatGNF(val: number): string {
    return new Intl.NumberFormat('fr-FR').format(Math.round(val)) + ' GNF';
}

function nowStr(): string {
    return new Date().toLocaleString('fr-FR', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}
</script>

<template>
    <div class="ticket-thermal font-mono">
        <!-- En-tête -->
        <div class="ticket-header">
            <p class="ticket-org">{{ orgNom }}</p>
            <p class="ticket-subtitle">Ticket commande</p>
            <p class="ticket-ref">{{ commande.reference }}</p>
            <p class="ticket-meta">{{ commande.created_at }}</p>
            <p class="ticket-meta">{{ commande.statut_label }}</p>
        </div>

        <div class="ticket-sep" />

        <!-- Infos commande -->
        <div class="ticket-infos">
            <div v-if="commande.site_nom" class="ticket-row">
                <span class="ticket-label">Site</span>
                <span class="ticket-value">{{ commande.site_nom }}</span>
            </div>
            <div v-if="commande.vehicule_nom" class="ticket-row">
                <span class="ticket-label">Véhicule</span>
                <span class="ticket-value">{{ commande.vehicule_nom }}</span>
            </div>
            <div v-if="commande.client_nom" class="ticket-row">
                <span class="ticket-label">Client</span>
                <span class="ticket-value">{{ commande.client_nom }}</span>
            </div>
        </div>

        <div class="ticket-sep" />

        <!-- Lignes produits -->
        <div class="ticket-produits">
            <div
                v-for="ligne in commande.lignes"
                :key="ligne.id"
                class="ticket-produit"
            >
                <p class="ticket-produit-nom">{{ ligne.produit_nom ?? '—' }}</p>
                <div class="ticket-row">
                    <span class="ticket-label"
                        >{{ ligne.quantite_demandee }} ×
                        {{ formatGNF(ligne.prix_vente_snapshot) }}</span
                    >
                    <span class="ticket-montant">{{
                        formatGNF(ligne.total_ligne)
                    }}</span>
                </div>
                <div
                    v-if="showChargee && ligne.quantite_chargee !== null"
                    class="ticket-charge"
                >
                    Chargé : {{ ligne.quantite_chargee }}
                </div>
            </div>
        </div>

        <div class="ticket-sep" />

        <!-- Totaux -->
        <div class="ticket-row ticket-qte-total">
            <span class="ticket-label">Qté totale</span>
            <span class="ticket-value">{{ quantiteTotale }} packs</span>
        </div>
        <div class="ticket-total-ligne">
            <span>TOTAL</span>
            <span>{{ formatGNF(commande.total_commande) }}</span>
        </div>

        <div class="ticket-sep" />

        <!-- QR Code -->
        <QrCodeTicket :url="qrUrl" />

        <div class="ticket-sep" />

        <!-- Footer -->
        <div class="ticket-footer">
            <p>Imprimé le {{ nowStr() }}</p>
            <p v-if="currentUser">par {{ currentUser }}</p>
        </div>
    </div>
</template>

<style scoped>
.ticket-thermal {
    font-family: 'Courier New', Courier, monospace;
    font-size: 13.5px;
    line-height: 1.75;
    color: #111;
    max-width: 100%;
}

.ticket-header {
    text-align: center;
    margin-bottom: 12px;
}
.ticket-org {
    font-size: 17px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    margin-bottom: 2px;
}
.ticket-subtitle {
    font-size: 12px;
    color: #666;
    margin-top: 2px;
}
.ticket-ref {
    font-size: 15px;
    font-weight: 700;
    letter-spacing: 0.08em;
    margin-top: 4px;
}
.ticket-meta {
    font-size: 12px;
    color: #555;
    margin-top: 1px;
}

.ticket-sep {
    border-top: 1px dashed #555;
    margin: 10px 0;
}

.ticket-infos {
    line-height: 1.9;
}

.ticket-row {
    display: flex;
    justify-content: space-between;
    align-items: baseline;
    gap: 8px;
}
.ticket-label {
    color: #555;
    white-space: nowrap;
    flex-shrink: 0;
}
.ticket-value {
    font-weight: 600;
    text-align: right;
}

.ticket-produits {
    display: flex;
    flex-direction: column;
    gap: 8px;
}
.ticket-produit-nom {
    font-size: 14px;
    font-weight: 700;
    margin-bottom: 1px;
}
.ticket-montant {
    font-weight: 700;
    text-align: right;
}
.ticket-charge {
    font-size: 11.5px;
    color: #555;
}

.ticket-qte-total {
    font-size: 13px;
    margin-bottom: 4px;
}
.ticket-total-ligne {
    display: flex;
    justify-content: space-between;
    align-items: baseline;
    font-size: 18px;
    font-weight: 800;
    border-top: 2px solid #111;
    padding-top: 6px;
    margin-top: 6px;
}

.ticket-footer {
    text-align: center;
    font-size: 11px;
    color: #666;
    line-height: 1.6;
}
</style>
