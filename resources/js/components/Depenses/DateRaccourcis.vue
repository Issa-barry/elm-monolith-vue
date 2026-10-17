<script setup lang="ts">
/**
 * Raccourcis « Aujourd'hui » / « Hier » sous le champ Date d'une dépense. Une dépense a toujours
 * une date précise : pas de raccourci de période (semaine…), le calendrier reste disponible pour
 * toute autre date.
 */
const date = defineModel<string>({ required: true });

function jourLocal(decalage: number): string {
    const d = new Date();
    d.setDate(d.getDate() - decalage);
    const mm = String(d.getMonth() + 1).padStart(2, '0');
    const jj = String(d.getDate()).padStart(2, '0');

    return `${d.getFullYear()}-${mm}-${jj}`;
}

const raccourcis = [
    { label: "Aujourd'hui", decalage: 0 },
    { label: 'Hier', decalage: 1 },
];
</script>

<template>
    <div class="mt-1.5 flex gap-1.5" data-testid="date-raccourcis">
        <button
            v-for="r in raccourcis"
            :key="r.decalage"
            type="button"
            class="rounded-md border px-2 py-0.5 text-xs transition-colors hover:bg-muted"
            :class="
                date === jourLocal(r.decalage)
                    ? 'border-primary font-medium text-primary'
                    : 'text-muted-foreground'
            "
            :aria-pressed="date === jourLocal(r.decalage)"
            @click="date = jourLocal(r.decalage)"
        >
            {{ r.label }}
        </button>
    </div>
</template>
