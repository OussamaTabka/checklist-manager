<script setup>
import { computed, ref, watch } from 'vue'
import { apiRequest, ensureCsrfCookie } from '@/lib/api'

const props = defineProps({
  project: {
    type: Object,
    required: true,
  },
  userStory: {
    type: Object,
    required: true,
  },
  checklistId: {
    type: Number,
    required: true,
  },
  existingTestCase: {
    type: Object,
    required: false,
    default: null,
  },
})

const emit = defineEmits(['close', 'submitted'])

const form = ref({
  title: '',
  description: '',
  priority: 'Medium',
  criticality: 'Major',
})

watch(
  () => props.existingTestCase,
  (existing) => {
    if (existing) {
      form.value = {
        title: existing.title || '',
        description: existing.description || '',
        priority: existing.priority || 'Medium',
        criticality: existing.criticality || 'Major',
      }
      return
    }

    form.value = {
      title: '',
      description: '',
      priority: 'Medium',
      criticality: 'Major',
    }
  },
  { immediate: true }
)

const isEditing = computed(() => Boolean(props.existingTestCase?.id))
const submitting = ref(false)
const errors = ref({})

const clientErrors = computed(() => {
  const next = {}

  if (form.value.title.trim().length < 3) {
    next.title = 'Le titre doit contenir au moins 3 caracteres.'
  }

  if (form.value.description.trim().length < 20) {
    next.description = "La description doit contenir au moins 20 caracteres pour permettre a l'agent de generer un script pertinent."
  }

  return next
})

const canSubmit = computed(() => Object.keys(clientErrors.value).length === 0 && !submitting.value)

async function submit() {
  if (!canSubmit.value) {
    return
  }

  submitting.value = true
  errors.value = {}

  try {
    await ensureCsrfCookie()

    const payload = {
      title: form.value.title.trim(),
      description: form.value.description.trim(),
      priority: form.value.priority,
      criticality: form.value.criticality,
    }

    const path = isEditing.value
      ? `/checklists/${props.checklistId}/items/${props.existingTestCase.id}`
      : `/checklists/${props.checklistId}/items`

    const method = isEditing.value ? 'PUT' : 'POST'

    const response = await apiRequest(path, {
      method,
      body: payload,
    })

    emit('submitted', response?.data || response)
    emit('close')
  } catch (error) {
    if (error?.status === 422 && error?.data?.errors) {
      errors.value = error.data.errors
      return
    }

    errors.value = {
      _global: [error?.message || 'Une erreur est survenue.'],
    }
  } finally {
    submitting.value = false
  }
}

function close() {
  emit('close')
}
</script>

<template>
  <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 px-4" @click.self="close">
    <div class="w-full max-w-xl rounded-xl bg-white shadow-2xl" role="dialog" aria-modal="true" aria-labelledby="testcase-form-title">
      <header class="border-b border-slate-200 px-6 py-4">
        <h2 id="testcase-form-title" class="text-lg font-semibold text-slate-900">
          {{ isEditing ? 'Modifier le cas de test' : 'Nouveau cas de test' }}
        </h2>
      </header>

      <section class="border-b border-slate-100 bg-slate-50 px-6 py-3" aria-label="Contexte herite">
        <p class="mb-2 text-xs font-medium uppercase tracking-wide text-slate-500">Contexte herite</p>
        <div class="flex flex-wrap gap-2">
          <span class="inline-flex items-center gap-1.5 rounded-full bg-indigo-50 px-2.5 py-1 text-xs font-medium text-indigo-700" :title="project.app_url">
            Projet : {{ project.name }}
          </span>
          <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-medium text-emerald-700">
            User story : {{ userStory.reference }} - {{ userStory.title }}
          </span>
        </div>
        <p class="mt-2 text-xs text-slate-500">
          L'agent utilise ces informations pour generer le script. Vous n'avez plus besoin de saisir les parametres ni les resultats attendus.
        </p>
      </section>

      <form @submit.prevent="submit" class="space-y-4 px-6 py-5">
        <div>
          <label for="tc-title" class="mb-1 block text-sm font-medium text-slate-700">Titre <span class="text-rose-500">*</span></label>
          <input
            id="tc-title"
            v-model="form.title"
            type="text"
            maxlength="255"
            class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
            placeholder="Ex. TC-001 - Connexion utilisateur standard"
            required
          />
          <p v-if="errors.title?.[0]" class="mt-1 text-xs text-rose-600">{{ errors.title[0] }}</p>
        </div>

        <div>
          <label for="tc-desc" class="mb-1 block text-sm font-medium text-slate-700">
            Description (objectif fonctionnel) <span class="text-rose-500">*</span>
          </label>
          <textarea
            id="tc-desc"
            v-model="form.description"
            rows="5"
            class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
            placeholder="Decrivez ce que le test doit verifier, et non les etapes Playwright."
            required
          />
          <p v-if="clientErrors.description" class="mt-1 text-xs text-amber-600">{{ clientErrors.description }}</p>
          <p v-if="errors.description?.[0]" class="mt-1 text-xs text-rose-600">{{ errors.description[0] }}</p>
        </div>

        <div class="grid grid-cols-2 gap-4">
          <div>
            <label for="tc-priority" class="mb-1 block text-sm font-medium text-slate-700">Priorite</label>
            <select
              id="tc-priority"
              v-model="form.priority"
              class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
            >
              <option value="Low">Faible</option>
              <option value="Medium">Moyenne</option>
              <option value="High">Haute</option>
            </select>
          </div>

          <div>
            <label for="tc-criticality" class="mb-1 block text-sm font-medium text-slate-700">Criticite</label>
            <select
              id="tc-criticality"
              v-model="form.criticality"
              class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
            >
              <option value="Minor">Mineure</option>
              <option value="Major">Majeure</option>
              <option value="Critical">Critique</option>
            </select>
          </div>
        </div>

        <p v-if="errors._global?.[0]" class="rounded-md bg-rose-50 px-3 py-2 text-sm text-rose-700">
          {{ errors._global[0] }}
        </p>
      </form>

      <footer class="flex items-center justify-end gap-2 border-t border-slate-200 bg-slate-50 px-6 py-3">
        <button type="button" class="rounded-md px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-200" @click="close">
          Annuler
        </button>
        <button
          type="button"
          class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-indigo-700 disabled:cursor-not-allowed disabled:bg-slate-300"
          :disabled="!canSubmit"
          @click="submit"
        >
          <span v-if="submitting">Enregistrement...</span>
          <span v-else>{{ isEditing ? 'Mettre a jour' : 'Creer' }}</span>
        </button>
      </footer>
    </div>
  </div>
</template>
