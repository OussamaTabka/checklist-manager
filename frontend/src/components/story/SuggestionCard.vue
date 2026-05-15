<script setup>
const props = defineProps({
  title: {
    type: String,
    default: '',
  },
  description: {
    type: String,
    default: '',
  },
  itemCount: {
    type: Number,
    default: 0,
  },
  scoreLabel: {
    type: String,
    default: '',
  },
  statusLabel: {
    type: String,
    default: 'Suggestion',
  },
  criticalityLabel: {
    type: String,
    default: '',
  },
  canCreateDraft: {
    type: Boolean,
    default: false,
  },
  actionsDisabled: {
    type: Boolean,
    default: false,
  },
  loading: {
    type: Boolean,
    default: false,
  },
})

defineEmits(['view-details', 'adapt', 'create-draft'])

const fallbackDescription = 'Aucune description disponible.'
</script>

<template>
  <article class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm transition">
    <div class="space-y-4">
      <div class="min-w-0 space-y-2">
        <div class="flex flex-wrap items-center gap-2">
          <h4 class="line-clamp-3 text-base font-semibold leading-7 text-slate-900">{{ title }}</h4>
          <span class="inline-flex rounded-full bg-sky-50 px-2.5 py-1 text-xs font-medium text-sky-700">
            Suggestion
          </span>
        </div>
        <p class="line-clamp-3 text-sm leading-6 text-slate-600">
          {{ description || fallbackDescription }}
        </p>
        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
          <div v-if="scoreLabel" class="rounded-2xl bg-slate-50 p-3">
            <p class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">Score de similarite</p>
            <p class="mt-2 text-lg font-semibold text-slate-900">{{ scoreLabel }}</p>
          </div>
          <div class="rounded-2xl bg-slate-50 p-3">
            <p class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">Cas de test</p>
            <p class="mt-2 text-lg font-semibold text-slate-900">{{ itemCount }}</p>
          </div>
          <div class="rounded-2xl bg-slate-50 p-3">
            <p class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">Statut</p>
            <p class="mt-2 text-sm font-semibold leading-6 text-slate-900">{{ statusLabel }}</p>
          </div>
          <div
            v-if="criticalityLabel"
            class="rounded-2xl bg-slate-50 p-3 sm:col-span-2 xl:col-span-1"
          >
            <p class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">Criticite globale</p>
            <p class="mt-2 text-sm font-semibold leading-6 text-slate-900">{{ criticalityLabel }}</p>
          </div>
        </div>
      </div>

      <div class="flex flex-wrap items-center gap-2 border-t border-slate-100 pt-1">
        <button type="button" class="btn btn-secondary btn-sm" :disabled="loading || actionsDisabled" @click="$emit('view-details')">
          {{ loading ? 'Chargement...' : 'Voir details' }}
        </button>
        <button type="button" class="btn btn-secondary btn-sm" :disabled="loading || actionsDisabled" @click="$emit('adapt')">
          Adapter
        </button>
        <button
          v-if="canCreateDraft"
          type="button"
          class="btn btn-primary btn-sm"
          :disabled="loading || actionsDisabled"
          @click="$emit('create-draft')"
        >
          Creer un brouillon
        </button>
      </div>
    </div>
  </article>
</template>
