<script setup>
import { onMounted, computed, ref, watch } from 'vue'
import { useRoute, useRouter, RouterLink } from 'vue-router'
import { useUserStoriesStore } from '@/stores/userStories'
import { useAuthStore } from '@/stores/auth'
import { apiRequest } from '@/lib/api'
import { Plus, Trash2, Edit2, Eye, Zap } from 'lucide-vue-next'

const route = useRoute()
const router = useRouter()
const storiesStore = useUserStoriesStore()
const auth = useAuthStore()

const projectId = ref(route.query.projectId || null)
const search = ref('')
const statusFilter = ref('all')
const priorityFilter = ref('all')

async function resolveProjectId() {
  if (projectId.value) {
    return projectId.value
  }

  const response = await apiRequest('/projects')
  const projects = Array.isArray(response?.data) ? response.data : []
  const firstProject = projects[0]

  if (!firstProject?.id) {
    return null
  }

  projectId.value = String(firstProject.id)
  await router.replace({
    name: 'stories',
    query: { ...route.query, projectId: projectId.value },
  })

  return projectId.value
}

const filteredStories = computed(() => {
  return storiesStore.stories.filter(story => {
    const matchSearch = 
      !search.value ||
      story.title.toLowerCase().includes(search.value.toLowerCase()) ||
      story.description.toLowerCase().includes(search.value.toLowerCase())
    
    const matchStatus = statusFilter.value === 'all' || story.status === statusFilter.value
    const matchPriority = priorityFilter.value === 'all' || story.priority === priorityFilter.value
    
    return matchSearch && matchStatus && matchPriority
  })
})

const statusCounts = computed(() => ({
  backlog: storiesStore.stories.filter(s => s.status === 'backlog').length,
  in_progress: storiesStore.stories.filter(s => s.status === 'in_progress').length,
  ready_for_test: storiesStore.stories.filter(s => s.status === 'ready_for_test').length,
  completed: storiesStore.stories.filter(s => s.status === 'completed').length,
}))

const statusLabels = {
  backlog: 'Backlog',
  in_progress: 'En Cours',
  ready_for_test: 'Prêt pour Test',
  completed: 'Complété',
}

const priorityColors = {
  critical: 'text-red-600 bg-red-50',
  high: 'text-orange-600 bg-orange-50',
  medium: 'text-yellow-600 bg-yellow-50',
  low: 'text-green-600 bg-green-50',
}

const priorityLabels = {
  critical: 'Critique',
  high: 'Haute',
  medium: 'Moyenne',
  low: 'Basse',
}

const statusColors = {
  backlog: 'bg-gray-100 text-gray-700',
  in_progress: 'bg-blue-100 text-blue-700',
  ready_for_test: 'bg-purple-100 text-purple-700',
  completed: 'bg-green-100 text-green-700',
}

async function loadStories() {
  const resolvedProjectId = await resolveProjectId()

  if (!resolvedProjectId) {
    storiesStore.stories = []
    return
  }

  await storiesStore.fetchStories(resolvedProjectId)
  await storiesStore.fetchGeneratorStatus(resolvedProjectId)
}

async function deleteStory(storyId, e) {
  e.stopPropagation()
  if (!confirm('Êtes-vous sûr de vouloir supprimer cette story ?')) return
  
  try {
    if (!projectId.value) {
      return
    }
    await storiesStore.deleteStory(projectId.value, storyId)
  } catch (err) {
    alert('Erreur: ' + err.message)
  }
}

function viewDetails(storyId) {
  if (!projectId.value) {
    return
  }

  router.push({
    name: 'story-detail',
    params: { id: storyId },
    query: { projectId: projectId.value },
  })
}

onMounted(() => {
  loadStories()
})

watch(
  () => route.query.projectId,
  (value) => {
    projectId.value = value || null
    loadStories()
  },
)
</script>

<template>
  <div class="space-y-6">
    <!-- Header -->
    <div class="flex items-center justify-between">
      <div>
        <h2 class="text-2xl font-bold">User Stories</h2>
        <p class="text-gray-500 text-sm">Gérez vos user stories et générez automatiquement des listes de vérification</p>
      </div>
      
      <RouterLink 
        v-if="auth.isProjectManager"
        :to="{ name: 'story-create', query: { projectId } }"
        class="bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700 flex items-center gap-2 transition"
      >
        <Plus :size="18" />
        Nouvelle Story
      </RouterLink>
    </div>

    <!-- Generator Status -->
    <div v-if="storiesStore.generatorStatus" class="bg-gradient-to-r from-blue-50 to-purple-50 rounded-lg p-4 border border-blue-200">
      <div class="flex items-center justify-between">
        <div class="flex items-center gap-3">
          <Zap :size="20" class="text-blue-600" />
          <div>
            <p class="font-semibold text-gray-900">
              Générateur: <span class="text-blue-600">{{ storiesStore.generatorStatus.current || 'Détermination...' }}</span>
            </p>
            <p class="text-sm text-gray-600">
              {{ storiesStore.generatorStatus.available?.join(', ') || 'Vérification de disponibilité...' }}
            </p>
          </div>
        </div>
        <div class="text-white bg-green-500 px-3 py-1 rounded-full text-sm font-medium">
          Système Prêt ✓
        </div>
      </div>
    </div>

    <!-- Filters -->
    <div class="bg-white rounded-lg shadow p-4 flex gap-4 items-end">
      <div class="flex-1">
        <label class="block text-sm font-medium text-gray-700 mb-2">Recherche</label>
        <input 
          v-model="search"
          type="text"
          placeholder="Rechercher par titre ou description..."
          class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
        />
      </div>
      
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-2">Statut</label>
        <select 
          v-model="statusFilter"
          class="px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
        >
          <option value="all">Tous</option>
          <option value="backlog">Backlog</option>
          <option value="in_progress">En Cours</option>
          <option value="ready_for_test">Prêt pour Test</option>
          <option value="completed">Complété</option>
        </select>
      </div>
      
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-2">Priorité</label>
        <select 
          v-model="priorityFilter"
          class="px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
        >
          <option value="all">Tous</option>
          <option value="critical">Critique</option>
          <option value="high">Haute</option>
          <option value="medium">Moyenne</option>
          <option value="low">Basse</option>
        </select>
      </div>
    </div>

    <!-- Status Tabs -->
    <div class="grid grid-cols-4 gap-4">
      <button
        @click="statusFilter = s"
        v-for="(label, s) in statusLabels"
        :key="s"
        :class="{
          'bg-blue-100 text-blue-700 border-blue-300': statusFilter === s,
          'bg-white text-gray-700 border-gray-200': statusFilter !== s,
        }"
        class="p-3 rounded-lg border-2 font-medium transition hover:shadow-md text-left"
      >
        <p class="text-lg font-bold">{{ statusCounts[s] }}</p>
        <p class="text-sm">{{ label }}</p>
      </button>
    </div>

    <!-- Loading State -->
    <div v-if="storiesStore.loading" class="flex justify-center py-8">
      <div class="animate-spin rounded-full h-8 w-8 border border-blue-500 border-t-transparent"></div>
    </div>

    <!-- Stories List -->
    <div v-else-if="filteredStories.length > 0" class="space-y-3">
      <div 
        v-for="story in filteredStories"
        :key="story.id"
        @click="viewDetails(story.id)"
        class="bg-white rounded-lg shadow hover:shadow-lg transition cursor-pointer border-l-4"
        :style="{ borderColor: story.priority === 'critical' ? '#dc2626' : story.priority === 'high' ? '#ea580c' : story.priority === 'medium' ? '#eab308' : '#16a34a' }"
      >
        <div class="p-4">
          <div class="flex items-start justify-between mb-2">
            <div class="flex-1">
              <h3 class="font-semibold text-gray-900">{{ story.title }}</h3>
              <p class="text-sm text-gray-600 mt-1">{{ story.description }}</p>
            </div>
            <div class="flex items-center gap-2 ml-4">
              <button
                v-if="auth.isProjectManager"
                @click.stop="deleteStory(story.id, $event)"
                class="p-2 text-gray-400 hover:text-red-600 transition"
                title="Supprimer"
              >
                <Trash2 :size="18" />
              </button>
            </div>
          </div>
          
          <div class="flex items-center gap-3 text-sm">
            <span :class="['px-2 py-1 rounded text-xs font-medium', statusColors[story.status]]">
              {{ statusLabels[story.status] }}
            </span>
            <span :class="['px-2 py-1 rounded text-xs font-medium', priorityColors[story.priority]]">
              {{ priorityLabels[story.priority] }}
            </span>
            <span v-if="story.checklists?.length" class="text-blue-600 font-medium">
              {{ story.checklists.length }} checklist(s)
            </span>
          </div>
        </div>
      </div>
    </div>

    <!-- Empty State -->
    <div v-else class="text-center py-12 bg-gray-50 rounded-lg">
      <p class="text-gray-500 mb-4">Aucune user story trouvée</p>
      <RouterLink 
        v-if="auth.isProjectManager"
        :to="{ name: 'story-create', query: { projectId } }"
        class="text-blue-600 hover:text-blue-700 font-medium"
      >
        Créer la première story →
      </RouterLink>
    </div>
  </div>
</template>
