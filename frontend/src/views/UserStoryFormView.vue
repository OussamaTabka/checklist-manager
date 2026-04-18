<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useUserStoriesStore } from '@/stores/userStories'
import { ArrowLeft } from 'lucide-vue-next'

const route = useRoute()
const router = useRouter()
const storiesStore = useUserStoriesStore()

const projectId = computed(() => route.query.projectId || null)
const storyId = route.params.id
const isEditing = !!storyId

const form = ref({
  title: '',
  description: '',
  acceptance_criteria: '',
  status: 'backlog',
  priority: 'medium',
})

const errors = ref({})
const submitting = ref(false)

const statusOptions = [
  { value: 'backlog', label: 'Backlog' },
  { value: 'in_progress', label: 'En Cours' },
  { value: 'ready_for_test', label: 'Prêt pour Test' },
  { value: 'completed', label: 'Complété' },
]

const priorityOptions = [
  { value: 'low', label: 'Basse' },
  { value: 'medium', label: 'Moyenne' },
  { value: 'high', label: 'Haute' },
  { value: 'critical', label: 'Critique' },
]

async function loadStory() {
  if (isEditing && projectId.value) {
    const story = await storiesStore.fetchStory(projectId.value, storyId)
    if (story) {
      form.value = {
        title: story.title,
        description: story.description,
        acceptance_criteria: story.acceptance_criteria,
        status: story.status,
        priority: story.priority,
      }
    }
  }
}

function validate() {
  errors.value = {}
  
  if (!form.value.title.trim()) {
    errors.value.title = 'Le titre est requis'
  }
  if (!form.value.description.trim()) {
    errors.value.description = 'La description est requise'
  }
  if (!form.value.acceptance_criteria.trim()) {
    errors.value.acceptance_criteria = 'Les critères d\'acceptation sont requis'
  }
  
  return Object.keys(errors.value).length === 0
}

async function submit() {
  if (!validate()) return
  if (!projectId.value) return
  
  submitting.value = true
  
  try {
    if (isEditing) {
      await storiesStore.updateStory(projectId.value, storyId, form.value)
    } else {
      await storiesStore.createStory(projectId.value, form.value)
    }
    
    router.push({
      name: 'stories',
      query: { projectId: projectId.value },
    })
  } catch (err) {
    errors.value.submit = err.message
  } finally {
    submitting.value = false
  }
}

function goBack() {
  router.back()
}

onMounted(() => {
  loadStory()
})
</script>

<template>
  <div class="max-w-2xl mx-auto">
    <!-- Header -->
    <div class="flex items-center gap-4 mb-6">
      <button
        @click="goBack"
        class="p-2 hover:bg-gray-100 rounded-lg transition"
      >
        <ArrowLeft :size="20" />
      </button>
      <div>
        <h1 class="text-2xl font-bold">{{ isEditing ? 'Éditer Story' : 'Nouvelle Story' }}</h1>
        <p class="text-gray-500 text-sm">Remplissez les détails de votre user story</p>
      </div>
    </div>

    <!-- Error Alert -->
    <div v-if="errors.submit" class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg mb-6">
      {{ errors.submit }}
    </div>

    <!-- Form -->
    <form @submit.prevent="submit" class="space-y-6 bg-white rounded-lg shadow p-6">
      <!-- Title -->
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-2">Titre de la Story *</label>
        <input
          v-model="form.title"
          type="text"
          placeholder="Ex: User can login with email"
          class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
        />
        <p v-if="errors.title" class="text-red-600 text-sm mt-1">{{ errors.title }}</p>
      </div>

      <!-- Description -->
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-2">Description *</label>
        <textarea
          v-model="form.description"
          placeholder="Décrivez la fonctionnalité en détail..."
          rows="4"
          class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
        ></textarea>
        <p v-if="errors.description" class="text-red-600 text-sm mt-1">{{ errors.description }}</p>
      </div>

      <!-- Acceptance Criteria -->
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-2">Critères d'Acceptation *</label>
        <textarea
          v-model="form.acceptance_criteria"
          placeholder="Ex: Given valid credentials, When user submits form, Then dashboard loads&#10;Given invalid credentials, When user submits form, Then error message shows"
          rows="4"
          class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 font-mono text-sm"
        ></textarea>
        <p class="text-gray-500 text-xs mt-1">Utilisez le format Given-When-Then pour de meilleurs résultats</p>
        <p v-if="errors.acceptance_criteria" class="text-red-600 text-sm mt-1">{{ errors.acceptance_criteria }}</p>
      </div>

      <!-- Status -->
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-2">Statut</label>
        <select
          v-model="form.status"
          class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
        >
          <option v-for="option in statusOptions" :key="option.value" :value="option.value">
            {{ option.label }}
          </option>
        </select>
      </div>

      <!-- Priority -->
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-2">Priorité</label>
        <select
          v-model="form.priority"
          class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
        >
          <option v-for="option in priorityOptions" :key="option.value" :value="option.value">
            {{ option.label }}
          </option>
        </select>
      </div>

      <!-- Actions -->
      <div class="flex gap-3 pt-4 border-t">
        <button
          @click="goBack"
          type="button"
          class="px-6 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 transition"
        >
          Annuler
        </button>
        <button
          type="submit"
          :disabled="submitting"
          class="px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition disabled:opacity-50 disabled:cursor-not-allowed"
        >
          {{ submitting ? 'En cours...' : isEditing ? 'Mettre à jour' : 'Créer' }}
        </button>
      </div>
    </form>
  </div>
</template>
