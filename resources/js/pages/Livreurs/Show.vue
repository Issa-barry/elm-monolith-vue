<script setup lang="ts">
import EspaceLivreurView from './partials/EspaceLivreurView.vue';
import FicheLivreurView from './partials/FicheLivreurView.vue';
import type { FicheLivreur, LivreurData } from './partials/types';

// Même URL (/livreurs/{id}, cible du QR code) pour deux publics : le backoffice voit la fiche
// complète, le livreur qui consulte sa propre fiche garde ses accès rapides.
defineProps<{
    livreur: LivreurData;
    commissions_url: string;
    factures_url: string | null;
    is_staff: boolean;
    fiche: FicheLivreur | null;
}>();
</script>

<template>
    <FicheLivreurView
        v-if="is_staff && fiche"
        :livreur="livreur"
        :fiche="fiche"
    />
    <EspaceLivreurView
        v-else
        :livreur="livreur"
        :commissions_url="commissions_url"
    />
</template>
