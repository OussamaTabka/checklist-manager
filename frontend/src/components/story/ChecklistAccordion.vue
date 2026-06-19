<script setup>
import { computed, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { Ellipsis, ExternalLink, Pencil, Trash2 } from 'lucide-vue-next'

const props = defineProps({
  checklist: {
    type: Object,
    required: true,
  },
  executionTo: {
    type: Object,
    default: null,
  },
  editTo: {
    type: Object,
    default: null,
  },
  canExecute: {
    type: Boolean,
    default: false,
  },
  canManage: {
    type: Boolean,
    default: false,
  },
  highlighted: {
    type: Boolean,
    default: false,
  },
})

const emit = defineEmits(['detach'])

const expanded = ref(false)
const menuOpen = ref(false)

const itemCount = computed(() => Array.isArray(props.checklist?.items) ? props.checklist.items.length : 0)
const statusLabel = computed(() => props.checklist?.lifecycle_status || props.checklist?.status || 'associée')
const globalCriticality = computed(() => {
  const items = Array.isArray(props.checklist?.items) ? props.checklist.items : []
  const rank = { Critical: 4, High: 3, Major: 3, Medium: 2, Low: 1, Minor: 1 }
  let current = ''

  for (const item of items) {
    if (!current || (rank[item.criticality] || 0) > (rank[current] || 0)) {
      current = item.criticality || current
    }
  }

  return current || 'A definir'
})

function toggleExpanded() {
  expanded.value = !expanded.value
}

function toggleMenu() {
  menuOpen.value = !menuOpen.value
}

function requestDetach() {
  menuOpen.value = false
  emit('detach')
}
</script>

<template>
  <article
    class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm transition"
    :class="highlighted ? 'ring-2 ring-emerald-200 border-emerald-200' : ''"
  >
    <div class="space-y-4">
      <div class="flex items-start justify-between gap-4">
        <div class="min-w-0 flex-1 space-y-2">
          <div class="flex flex-wrap items-center gap-2">
            <h4 class="line-clamp-3 text-base font-semibold leading-7 text-slate-900">{{ checklist.name }}</h4>
            <span class="inline-flex rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-medium text-emerald-700">
              Associee
            </span>
          </div>
          <p class="line-clamp-3 text-sm leading-6 text-slate-600">{{ checklist.description || 'Aucune description disponible.' }}</p>
        </div>

        <div class="relative shrink-0">
          <button
            type="button"
            class="inline-flex h-10 w-10 items-center justify-center rounded-2xl border border-slate-200 bg-white text-slate-600 transition hover:border-slate-300 hover:text-slate-900"
            :aria-expanded="menuOpen"
            @click.stop="toggleMenu"
          >
            <Ellipsis :size="16" />
          </button>
          <div
            v-if="menuOpen"
            class="absolute right-0 top-[calc(100%+0.5rem)] z-20 min-w-[12rem] overflow-hidden rounded-2xl border border-slate-200 bg-white p-2 shadow-lg"
            @click.stop
          >
            <button type="button" class="story-action" @click="toggleExpanded(); menuOpen = false">
              <span>{{ expanded ? 'Masquer' : 'Voir' }}</span>
            </button>
            <RouterLink
              v-if="canManage && editTo"
              :to="editTo"
              class="story-action"
              @click="menuOpen = false"
            >
              <Pencil :size="14" />
              <span>Modifier</span>
            </RouterLink>
            <RouterLink
              v-if="canExecute && executionTo"
              :to="executionTo"
              class="story-action"
              @click="menuOpen = false"
            >
              <ExternalLink :size="14" />
              <span>Executer</span>
            </RouterLink>
            <button
              v-if="canManage"
              type="button"
              class="story-action text-rose-600"
              @click="requestDetach"
            >
              <Trash2 :size="14" />
              <span>Detacher</span>
            </button>
          </div>
        </div>
      </div>

      <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
        <div class="rounded-2xl bg-slate-50 p-3">
          <p class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">Cas de test</p>
          <p class="mt-2 text-lg font-semibold text-slate-900">{{ itemCount }}</p>
        </div>
        <div class="rounded-2xl bg-slate-50 p-3">
          <p class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">Statut</p>
          <p class="mt-2 text-sm font-semibold leading-6 text-slate-900">{{ statusLabel }}</p>
        </div>
        <div class="rounded-2xl bg-slate-50 p-3 sm:col-span-2 xl:col-span-1">
          <p class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">Criticite globale</p>
          <p class="mt-2 text-sm font-semibold leading-6 text-slate-900">{{ globalCriticality }}</p>
        </div>
      </div>
    </div>

    <div v-if="expanded" class="mt-4 grid gap-3">
      <div
        v-for="item in checklist.items || []"
        :key="item.id"
        class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3"
      >
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div class="min-w-0 flex-1">
            <p class="font-medium text-slate-900">{{ item.title }}</p>
            <p v-if="item.description" class="mt-1 text-sm leading-6 text-slate-600">{{ item.description }}</p>
          </div>
          <span class="rounded-full bg-white px-2.5 py-1 text-xs font-medium text-slate-600">
            {{ item.criticality || 'N/A' }}
          </span>
        </div>
      </div>
    </div>
  </article>
</template>

<style scoped>
.story-action {
  display: flex;
  width: 100%;
  align-items: center;
  gap: 0.6rem;
  border-radius: 1rem;
  padding: 0.8rem 0.95rem;
  text-align: left;
  font-size: 0.92rem;
  color: #334155;
  transition: background 0.2s ease, color 0.2s ease;
}

.story-action:hover {
  background: #f8fafc;
  color: #0f172a;
}
</style>
