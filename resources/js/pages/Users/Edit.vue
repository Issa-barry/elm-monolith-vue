<script setup lang="ts">
import { Spinner } from '@/components/ui/spinner';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Save } from 'lucide-vue-next';
import { watch } from 'vue';
import UserForm from './partials/UserForm.vue';

interface RoleOption {
    value: string;
    label: string;
}

interface SiteOption {
    value: number;
    label: string;
}

interface UserData {
    id: number;
    prenom: string;
    nom: string;
    email: string;
    telephone: string | null;
    code_pays: string | null;
    ville: string | null;
    adresse: string | null;
    role: string;
    site_id: number | null;
    is_active: boolean;
    matricule: string | null;
}

const props = defineProps<{
    user: UserData;
    roles: RoleOption[];
    sites: SiteOption[];
    is_me: boolean;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/backoffice/dashboard' },
    { title: 'Comptes', href: '/backoffice/comptes' },
    {
        title: `${props.user.prenom} ${props.user.nom}`,
        href: `/backoffice/users/${props.user.id}`,
    },
    { title: 'Modifier', href: '#' },
];

// Retour vers la fiche agent, point d'entrée de la consultation.
const ficheHref = `/backoffice/users/${props.user.id}`;

const DIAL_MAP: Record<string, string> = {
    GN: '+224',
    GW: '+245',
    SN: '+221',
    ML: '+223',
    CI: '+225',
    LR: '+231',
    SL: '+232',
    FR: '+33',
    CN: '+86',
    AE: '+971',
    IN: '+91',
};

function localDigits(
    tel: string | null,
    codePays: string | null,
): string | null {
    if (!tel) return null;
    const dial = codePays ? DIAL_MAP[codePays] : null;
    if (dial && tel.startsWith(dial)) return tel.slice(dial.length);
    return tel;
}

const resolvedCodePays = props.user.code_pays ?? 'GN';

// ── Formulaire informations ───────────────────────────────────────────────────
const infoForm = useForm({
    prenom: props.user.prenom,
    nom: props.user.nom,
    email: props.user.email,
    telephone: localDigits(props.user.telephone, resolvedCodePays),
    code_pays: resolvedCodePays as string | null,
    code_phone_pays: DIAL_MAP[resolvedCodePays] ?? ('+224' as string | null),
    ville: props.user.ville,
    adresse: props.user.adresse,
    role: props.user.role,
    site_id: props.user.site_id,
    // Champs attendus par UserForm (partagé avec la création), jamais affichés ni pris en compte
    // en modification : seul le titulaire change son mot de passe (ADR 0015).
    password: '',
    password_confirmation: '',
    is_active: props.user.is_active,
});

watch(
    () => props.user,
    (user) => {
        const codePays = user.code_pays ?? 'GN';
        infoForm.prenom = user.prenom;
        infoForm.nom = user.nom;
        infoForm.email = user.email;
        infoForm.telephone = localDigits(user.telephone, codePays);
        infoForm.code_pays = codePays;
        infoForm.code_phone_pays = DIAL_MAP[codePays] ?? '+224';
        infoForm.ville = user.ville;
        infoForm.adresse = user.adresse;
        infoForm.role = user.role;
        infoForm.site_id = user.site_id;
        infoForm.is_active = user.is_active;
        infoForm.clearErrors();
    },
    { deep: true },
);

function submitInfo() {
    infoForm.put(`/backoffice/users/${props.user.id}`);
}
</script>

<template>
    <Head>
        <title>Modifier — {{ user.prenom }} {{ user.nom }}</title>
    </Head>
    <AppLayout :breadcrumbs="breadcrumbs" :hide-mobile-header="true">
        <!-- Header mobile -->
        <div
            class="sticky top-0 z-20 border-b border-border/60 bg-background/95 backdrop-blur-sm sm:hidden"
        >
            <div class="relative flex items-center justify-center px-4 py-3">
                <Link
                    :href="ficheHref"
                    class="absolute left-4 inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-muted text-muted-foreground transition-transform active:scale-95"
                >
                    <ArrowLeft class="h-4 w-4" />
                </Link>
                <div class="text-center">
                    <h1 class="text-[17px] leading-tight font-semibold">
                        Modifier
                    </h1>
                    <p class="text-[11px] text-muted-foreground">
                        {{ user.prenom }} {{ user.nom }}
                    </p>
                </div>
            </div>
        </div>

        <div class="pb-6 sm:p-6">
            <!-- Titre desktop -->
            <div class="hidden px-6 pt-6 pb-0 sm:block">
                <div class="mb-6 flex items-center gap-3">
                    <Link
                        :href="ficheHref"
                        class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-muted text-muted-foreground hover:bg-muted/80"
                        aria-label="Retour à la fiche"
                        data-testid="user-edit-back"
                    >
                        <ArrowLeft class="h-4 w-4" />
                    </Link>
                    <div>
                        <h1 class="text-2xl font-semibold tracking-tight">
                            Modifier le compte
                        </h1>
                        <p
                            class="mt-1 flex items-center gap-2 text-sm font-medium text-muted-foreground"
                        >
                            {{ user.prenom }} {{ user.nom }}
                            <span
                                v-if="user.matricule"
                                class="rounded bg-muted px-2 py-0.5 font-mono text-[11px] text-muted-foreground"
                                >{{ user.matricule }}</span
                            >
                            <span
                                v-if="is_me"
                                class="rounded bg-muted px-1.5 py-0.5 text-[10px]"
                                >Moi</span
                            >
                        </p>
                    </div>
                </div>
            </div>

            <div class="px-4 sm:px-6">
                <UserForm
                    :form="infoForm"
                    :errors="infoForm.errors"
                    :processing="infoForm.processing"
                    :roles="roles"
                    :sites="sites"
                    :is-edit="true"
                    :show-password="false"
                    :back-href="ficheHref"
                    @submit="submitInfo"
                    @update:form="Object.assign(infoForm, $event)"
                    @clear-error="infoForm.clearErrors($event as any)"
                />
            </div>
        </div>

        <!-- Footer mobile -->
        <div
            class="fixed right-0 bottom-0 left-0 z-30 border-t border-border/60 bg-background/95 px-4 py-3 backdrop-blur-sm sm:hidden"
        >
            <button
                type="submit"
                form="user-form"
                :disabled="infoForm.processing"
                class="flex w-full items-center justify-center gap-2 rounded-xl bg-primary py-3 text-sm font-semibold text-primary-foreground shadow-sm transition-transform active:scale-[0.98] disabled:opacity-60"
            >
                <Spinner v-if="infoForm.processing" class="h-4 w-4" />
                <Save v-else class="h-4 w-4" />
                {{ infoForm.processing ? 'Enregistrement…' : 'Enregistrer' }}
            </button>
        </div>
    </AppLayout>
</template>
