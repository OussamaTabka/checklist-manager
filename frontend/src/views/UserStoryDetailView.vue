<script setup>
import { onMounted, computed, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useUserStoriesStore } from '@/stores/userStories'
import { useAuthStore } from '@/stores/auth'
import { ArrowLeft, Zap, Trash2, AlertCircle } from 'lucide-vue-next'

const route = useRoute()
const router = useRouter()
const storiesStore = useUserStoriesStore()
const auth = useAuthStore()

const projectId = computed(() => route.query.projectId || null)
const storyId = route.params.id

const statusLabels = {
  backlog: 'Backlog',
  in_progress: 'En Cours',
  ready_for_test: 'Prêt pour Test',
  completed: 'Complété',
}

const priorityColors = {
  critical: 'text-red-600 bg-red-50 border-red-200',
  high: 'text-orange-600 bg-orange-50 border-orange-200',
  medium: 'text-yellow-600 bg-yellow-50 border-yellow-200',
  low: 'text-green-600 bg-green-50 border-green-200',
}

const criticalities = {
  'Critical': 'bg-red-100 text-red-800',
  'High': 'bg-orange-100 text-orange-800',
  'Medium': 'bg-yellow-100 text-yellow-800',
  'Low': 'bg-green-100 text-green-800',
}

async function loadStory() {
  if (!projectId.value) {
    return
  }

  await storiesStore.fetchStory(projectId.value, storyId)
  await storiesStore.fetchGeneratorStatus(projectId.value)
}

async function generateChecklist() {
  if (!confirm('Générer une liste de vérification automatiquement avec l\'IA locale ?')) return
  if (!projectId.value) return
  
  try {
    await storiesStore.generateChecklistFromLLM(projectId.value, storyId)
    // Reload to see updated checklists
    await storiesStore.fetchStory(projectId.value, storyId)
  } catch (err) {
    alert('Erreur lors de la génération: ' + err.message)
  }
}

async function detachChecklist(checklistId) {
  if (!confirm('Détacher cette liste de vérification ?')) return
  if (!projectId.value) return
  
  try {
    await storiesStore.detachChecklist(projectId.value, storyId, checklistId)
  } catch (err) {
    alert('Erreur: ' + err.message)
  }
}

function editStory() {
  if (!projectId.value) return

  router.push({
    name: 'story-edit',
    params: { id: storyId },
    query: { projectId: projectId.value },
  })
}

function goBack() {
  router.back()
}

onMounted(() => {
  loadStory()
})
</script>

<template>
  <div class="space-y-6">
    <!-- Header -->
    <div class="flex items-center gap-4">
      <button @click="goBack" class="p-2 hover:bg-gray-100 rounded-lg transition">
        <ArrowLeft :size="20" />
      </button>
      <div class="flex-1">
        <h1 class="text-2xl font-bold">{{ storiesStore.currentStory?.title || 'Chargement...' }}</h1>
        <p class="text-gray-500 text-sm">Story ID: #{{ storyId }}</p>
      </div>
      <button
        v-if="auth.isProjectManager && storiesStore.currentStory"
        @click="editStory"
        class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition"
      >
        Éditer
      </button>
    </div>

    <!-- Loading State -->
    <div v-if="storiesStore.loading" class="flex justify-center py-12">
      <div class="animate-spin rounded-full h-8 w-8 border border-blue-500 border-t-transparent"></div>
    </div>

    <!-- Story Content -->
    <div v-else-if="storiesStore.currentStory" class="space-y-6">
      <!-- Status & Priority -->
      <div class="grid grid-cols-2 gap-4 bg-white rounded-lg shadow p-4">
        <div>
          <p class="text-sm text-gray-600 mb-2">Statut</p>
          <p class="text-lg font-semibold">{{ statusLabels[storiesStore.currentStory.status] }}</p>
        </div>
        <div>
          <p class="text-sm text-gray-600 mb-2">Priorité</p>
          <span :class="['inline-block px-3 py-1 rounded-full text-sm font-medium border', priorityColors[storiesStore.currentStory.priority]]">
            {{ storiesStore.currentStory.priority.charAt(0).toUpperCase() + storiesStore.currentStory.priority.slice(1) }}
          </span>
        </div>
      </div>

      <!-- Description -->
      <div class="bg-white rounded-lg shadow p-6">
        <h2 class="text-lg font-semibold mb-3">Description</h2>
        <p class="text-gray-700 whitespace-pre-wrap">{{ storiesStore.currentStory.description }}</p>
      </div>

      <!-- Acceptance Criteria -->
      <div class="bg-white rounded-lg shadow p-6">
        <h2 class="text-lg font-semibold mb-3">Critères d'Acceptation</h2>
        <div class="bg-gray-50 p-4 rounded-lg font-mono text-sm whitespace-pre-wrap text-gray-700">
          {{ storiesStore.currentStory.acceptance_criteria }}
        </div>
      </div>

      <!-- Generate Checklist Button -->
      <div class="bg-gradient-to-r from-blue-50 to-purple-50 rounded-lg border-2 border-blue-200 p-6">
        <div class="flex items-center justify-between">
          <div>
            <h3 class="text-lg font-semibold flex items-center gap-2 mb-2">
              <Zap :size="20" class="text-blue-600" />
              Génération Automatique d'IA
            </h3>
            <p class="text-gray-600 text-sm">
              Utilisez le modèle LLM local pour générer automatiquement une liste de vérification
              avec des cas de test basés sur les critères d'acceptation.
            </p>
            <p class="text-gray-500 text-xs mt-2">
              Générateur actif: <span class="font-semibold">{{ storiesStore.generatorStatus.current || 'Détermination...' }}</span>
            </p>
          </div>
          <button
            @click="generateChecklist"
            :disabled="storiesStore.isGenerating"
            class="px-6 py-3 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition disabled:opacity-50 disabled:cursor-not-allowed font-medium whitespace-nowrap ml-4"
          >
            {{ storiesStore.isGenerating ? 'Génération...' : 'Générer Checklist' }}
          </button>
        </div>
      </div>

      <!-- Attached Checklists -->
      <div v-if="storiesStore.currentStory.checklists?.length > 0" class="space-y-4">
        <h2 class="text-lg font-semibold">Listes de Vérification Attachées ({{ storiesStore.currentStory.checklists.length }})</h2>
        <div
          v-for="checklist in storiesStore.currentStory.checklists"
          :key="checklist.id"
          class="bg-white rounded-lg shadow p-6 border-l-4 border-blue-500"
        >
          <div class="flex items-start justify-between mb-4">
            <div class="flex-1">
              <h3 class="font-semibold text-gray-900">{{ checklist.name }}</h3>
              <p class="text-sm text-gray-600 mt-1">{{ checklist.description }}</p>
            </div>
            <button
              v-if="auth.isProjectManager"
              @click="detachChecklist(checklist.id)"
              class="p-2 text-gray-400 hover:text-red-600 transition"
              title="Détacher"
            >
              <Trash2 :size="18" />
            </button>
          </div>

          <!-- Checklist Items -->
          <div class="space-y-2 mt-4">
            <div
              v-for="item in checklist.items"
              :key="item.id"
              class="flex items-start gap-3 p-3 bg-gray-50 rounded"
            >
              <div class="flex-1">
                <p class="font-medium text-gray-900">{{ item.title }}</p>
                <p v-if="item.description" class="text-sm text-gray-600 mt-1">{{ item.description }}</p>
              </div>
              <span v-if="item.criticality" :class="['px-2 py-1 rounded text-xs font-medium whitespace-nowrap', criticalities[item.criticality]]">
                {{ item.criticality }}
              </span>
            </div>
          </div>

          <!-- Creator Badge -->
          <div v-if="checklist.is_generated_from_arxis" class="mt-4 flex items-center gap-2 text-sm text-blue-600 bg-blue-50 p-3 rounded">
            <Zap :size="16" />
            Généré automatiquement par IA
          </div>
        </div>
      </div>

      <!-- No Checklists -->
      <div v-else class="bg-gray-50 rounded-lg p-6 text-center">
        <AlertCircle :size="32" class="mx-auto text-gray-400 mb-3" />
        <p class="text-gray-600">Aucune liste de vérification attachée</p>
        <p class="text-sm text-gray-500 mt-1">Générez une checklist automatiquement avec l'IA ou attachez une existante</p>
      </div>
    </div>

    <!-- Error State -->
    <div v-else class="text-center py-12 bg-gray-50 rounded-lg">
      <p class="text-gray-500">Story non trouvée</p>
    </div>
  </div>
</template>
