<script setup lang="ts">
import HeadingSmall from '@/components/HeadingSmall.vue';
import CommunicationChannelToggle from '@/components/settings/CommunicationChannelToggle.vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, router, usePage } from '@inertiajs/vue3';
import { Package, Truck } from 'lucide-vue-next';
import { computed, reactive, ref } from 'vue';

interface ChannelCell {
    sms: boolean;
    whatsapp: boolean;
}

interface ClientTypeOption {
    value: string;
    label: string;
}

const props = defineProps<{
    channel_availability: { sms: boolean; whatsapp: boolean };
    client_types: ClientTypeOption[];
    ventes: {
        commande_confirmee: { livreur: ChannelCell };
        chargement_valide: {
            livreur: ChannelCell;
            client: Record<string, ChannelCell>;
        };
    };
    logistique: {
        transfert_cree: { livreur: ChannelCell };
        chargement_valide: { livreur: ChannelCell };
    };
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Parametres', href: '/settings/profile' },
    { title: 'Communications', href: '/settings/communications' },
];

const page = usePage();
const flashSuccess = computed(
    () => (page.props.flash as Record<string, string>)?.success ?? null,
);

// État local des cases à cocher — cloné depuis les props, aplati en règles
// (rules[]) uniquement au moment de l'envoi (cf. buildRulesPayload()).
const state = reactive({
    ventes: {
        commande_confirmee: {
            livreur: { ...props.ventes.commande_confirmee.livreur },
        },
        chargement_valide: {
            livreur: { ...props.ventes.chargement_valide.livreur },
            client: Object.fromEntries(
                props.client_types.map((t) => [
                    t.value,
                    { ...props.ventes.chargement_valide.client[t.value] },
                ]),
            ) as Record<string, ChannelCell>,
        },
    },
    logistique: {
        transfert_cree: {
            livreur: { ...props.logistique.transfert_cree.livreur },
        },
        chargement_valide: {
            livreur: { ...props.logistique.chargement_valide.livreur },
        },
    },
});

const processing = ref(false);

function buildRulesPayload() {
    const rules: {
        module: string;
        event: string;
        recipient_type: string;
        client_type: string | null;
        channel: string;
        enabled: boolean;
    }[] = [];

    const pushLivreur = (module: string, event: string, cell: ChannelCell) => {
        rules.push({
            module,
            event,
            recipient_type: 'livreur',
            client_type: null,
            channel: 'sms',
            enabled: cell.sms,
        });
        rules.push({
            module,
            event,
            recipient_type: 'livreur',
            client_type: null,
            channel: 'whatsapp',
            enabled: cell.whatsapp,
        });
    };

    pushLivreur(
        'ventes',
        'commande_confirmee',
        state.ventes.commande_confirmee.livreur,
    );
    pushLivreur(
        'ventes',
        'chargement_valide',
        state.ventes.chargement_valide.livreur,
    );
    pushLivreur(
        'logistique',
        'transfert_cree',
        state.logistique.transfert_cree.livreur,
    );
    pushLivreur(
        'logistique',
        'chargement_valide',
        state.logistique.chargement_valide.livreur,
    );

    for (const type of props.client_types) {
        const cell = state.ventes.chargement_valide.client[type.value];
        rules.push({
            module: 'ventes',
            event: 'chargement_valide',
            recipient_type: 'client',
            client_type: type.value,
            channel: 'sms',
            enabled: cell.sms,
        });
        rules.push({
            module: 'ventes',
            event: 'chargement_valide',
            recipient_type: 'client',
            client_type: type.value,
            channel: 'whatsapp',
            enabled: cell.whatsapp,
        });
    }

    return rules;
}

function submit() {
    processing.value = true;
    router.put(
        '/settings/communications',
        { rules: buildRulesPayload() },
        {
            preserveScroll: true,
            onFinish: () => {
                processing.value = false;
            },
        },
    );
}
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Communications" />

        <SettingsLayout>
            <div class="space-y-6">
                <HeadingSmall
                    title="Communications"
                    description="Configurez l'envoi de SMS/WhatsApp au livreur et au client aux étapes clés d'une commande ou d'un transfert."
                />

                <!-- Ventes -->
                <div class="overflow-hidden rounded-xl border bg-card">
                    <div
                        class="flex items-center gap-2 border-b bg-muted/30 px-5 py-3"
                    >
                        <Package class="h-4 w-4 text-muted-foreground" />
                        <h3 class="text-sm font-semibold text-foreground">
                            Ventes
                        </h3>
                    </div>

                    <div class="divide-y">
                        <div class="px-5 py-4">
                            <p class="text-sm font-medium text-foreground">
                                Commande confirmée
                            </p>
                            <p
                                class="mt-0.5 mb-3 text-xs text-muted-foreground"
                            >
                                Envoyé au livreur assigné dès que la commande
                                passe de brouillon à confirmée.
                            </p>
                            <div class="flex flex-wrap items-center gap-4 pl-2">
                                <span class="text-xs text-muted-foreground"
                                    >Livreur</span
                                >
                                <CommunicationChannelToggle
                                    v-model="
                                        state.ventes.commande_confirmee.livreur
                                            .sms
                                    "
                                    label="SMS"
                                    :available="channel_availability.sms"
                                />
                                <CommunicationChannelToggle
                                    v-model="
                                        state.ventes.commande_confirmee.livreur
                                            .whatsapp
                                    "
                                    label="WhatsApp"
                                    :available="channel_availability.whatsapp"
                                />
                            </div>
                        </div>

                        <div class="px-5 py-4">
                            <p class="text-sm font-medium text-foreground">
                                Chargement validé
                            </p>
                            <p
                                class="mt-0.5 mb-3 text-xs text-muted-foreground"
                            >
                                Envoyé au livreur et/ou au client dès que le
                                chargement de la commande est validé.
                            </p>

                            <div class="flex flex-wrap items-center gap-4 pl-2">
                                <span class="text-xs text-muted-foreground"
                                    >Livreur</span
                                >
                                <CommunicationChannelToggle
                                    v-model="
                                        state.ventes.chargement_valide.livreur
                                            .sms
                                    "
                                    label="SMS"
                                    :available="channel_availability.sms"
                                />
                                <CommunicationChannelToggle
                                    v-model="
                                        state.ventes.chargement_valide.livreur
                                            .whatsapp
                                    "
                                    label="WhatsApp"
                                    :available="channel_availability.whatsapp"
                                />
                            </div>

                            <div class="mt-4 space-y-3 border-t pt-3 pl-2">
                                <p class="text-xs font-medium text-foreground">
                                    Client, par type
                                </p>
                                <div
                                    v-for="type in client_types"
                                    :key="type.value"
                                    class="flex flex-wrap items-center gap-4"
                                >
                                    <span
                                        class="w-24 shrink-0 text-xs text-muted-foreground"
                                        >{{ type.label }}</span
                                    >
                                    <CommunicationChannelToggle
                                        v-model="
                                            state.ventes.chargement_valide
                                                .client[type.value].sms
                                        "
                                        label="SMS"
                                        :available="channel_availability.sms"
                                    />
                                    <CommunicationChannelToggle
                                        v-model="
                                            state.ventes.chargement_valide
                                                .client[type.value].whatsapp
                                        "
                                        label="WhatsApp"
                                        :available="
                                            channel_availability.whatsapp
                                        "
                                    />
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Logistique / Transfert -->
                <div class="overflow-hidden rounded-xl border bg-card">
                    <div
                        class="flex items-center gap-2 border-b bg-muted/30 px-5 py-3"
                    >
                        <Truck class="h-4 w-4 text-muted-foreground" />
                        <h3 class="text-sm font-semibold text-foreground">
                            Logistique / Transfert
                        </h3>
                    </div>

                    <p class="px-5 pt-3 text-xs text-muted-foreground">
                        Un transfert logistique n'a pas de client (mouvement
                        inter-sites) — seul le livreur peut être notifié.
                    </p>

                    <div class="divide-y">
                        <div class="px-5 py-4">
                            <p class="text-sm font-medium text-foreground">
                                Transfert créé
                            </p>
                            <p
                                class="mt-0.5 mb-3 text-xs text-muted-foreground"
                            >
                                Envoyé au livreur dès la création du transfert
                                avec une équipe assignée.
                            </p>
                            <div class="flex flex-wrap items-center gap-4 pl-2">
                                <span class="text-xs text-muted-foreground"
                                    >Livreur</span
                                >
                                <CommunicationChannelToggle
                                    v-model="
                                        state.logistique.transfert_cree.livreur
                                            .sms
                                    "
                                    label="SMS"
                                    :available="channel_availability.sms"
                                />
                                <CommunicationChannelToggle
                                    v-model="
                                        state.logistique.transfert_cree.livreur
                                            .whatsapp
                                    "
                                    label="WhatsApp"
                                    :available="channel_availability.whatsapp"
                                />
                            </div>
                        </div>

                        <div class="px-5 py-4">
                            <p class="text-sm font-medium text-foreground">
                                Chargement validé
                            </p>
                            <p
                                class="mt-0.5 mb-3 text-xs text-muted-foreground"
                            >
                                Envoyé au livreur dès que le chargement du
                                transfert est validé.
                            </p>
                            <div class="flex flex-wrap items-center gap-4 pl-2">
                                <span class="text-xs text-muted-foreground"
                                    >Livreur</span
                                >
                                <CommunicationChannelToggle
                                    v-model="
                                        state.logistique.chargement_valide
                                            .livreur.sms
                                    "
                                    label="SMS"
                                    :available="channel_availability.sms"
                                />
                                <CommunicationChannelToggle
                                    v-model="
                                        state.logistique.chargement_valide
                                            .livreur.whatsapp
                                    "
                                    label="WhatsApp"
                                    :available="channel_availability.whatsapp"
                                />
                            </div>
                        </div>
                    </div>
                </div>

                <div
                    v-if="flashSuccess"
                    class="rounded-lg bg-emerald-50 px-4 py-3 text-sm text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300"
                >
                    {{ flashSuccess }}
                </div>

                <div class="flex justify-end">
                    <Button :disabled="processing" @click="submit">
                        Enregistrer
                    </Button>
                </div>
            </div>
        </SettingsLayout>
    </AppLayout>
</template>
