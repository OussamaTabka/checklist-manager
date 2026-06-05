<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter, RouterLink } from 'vue-router'
import { useUserStoriesStore } from '@/stores/userStories'
import { useAuthStore } from '@/stores/auth'
import { apiRequest } from '@/lib/api'
import { localizeError, tr } from '@/lib/localization'
import { useSettingsStore } from '@/stores/settings'
import { ArrowRight, FolderKanban, Plus, Sparkles, Trash2, Zap } from 'lucide-vue-next'

const route = useRoute()
const router = useRouter()
const storiesStore = useUserStoriesStore()
const auth = useAuthStore()
const settings = useSettingsStore()

const projectId = ref(route.query.projectId || null)
const projects = ref([])
const loadingProjects = ref(false)
const search = ref('')
const statusFilter = ref('all')
const priorityFilter = ref('all')

const currentProject = computed(() => {
  return projects.value.find((project) => String(project.id) === String(projectId.value)) || null
})

const canAddStories = computed(() => auth.hasAnyRole(['admin', 'chef']))

const roleStoryCopy = computed(() => {
  switch (auth.primaryRole) {
    case 'admin':
      return {
        kicker: 'Espace administrateur',
        title: 'Pilotage des User Stories',
        description:
          'Supervisez la qualité du backlog, la structuration des besoins et la réutilisation des checklists   l'échelle de la plateforme.',
      }
    case 'testeur':
      return {
        kicker: 'Espace d'exécution',
        title: 'User Stories assignées',
        description:
          'Consultez les User Stories liées   vos projets, évaluez leur niveau de préparation et accédez au contexte nécessaire avant l'exécution des tests.',
      }
    default:
      return {
        kicker: 'Espace chef de projet',
        title: 'Backlog des User Stories',
        description:
          'Centralisez les besoins du projet, suivez leur maturité et transformez-les en checklists réutilisables sans perdre le contexte métier.',
      }
  }
})

async function loadProjects() {
  loadingProjects.value = true

  try {
    const response = await apiRequest('/projects')
    projects.value = Array.isArray(response?.data) ? response.data : []
  } catch (err) {
    projects.value = []

    if (err?.status !== 401) {
      throw err
    }
  } finally {
    loadingProjects.value = false
  }
}

async function resolveProjectId() {
  if (!projects.value.length) {
    await loadProjects()
  }

  if (projectId.value) {
    return projectId.value
  }

  const firstProject = projects.value[0]

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

async function selectProject(nextProjectId) {
  projectId.value = nextProjectId || null
  await router.replace({
    name: 'stories',
    query: nextProjectId ? { ...route.query, projectId: nextProjectId } : {},
  })
}

const filteredStories = computed(() => {
  return storiesStore.stories.filter((story) => {
    const matchSearch =
      !search.value ||
      story.title.toLowerCase().includes(search.value.toLowerCase()) ||
      String(story.description || '').toLowerCase().includes(search.value.toLowerCase())

    const matchStatus = statusFilter.value === 'all' || story.status === statusFilter.value
    const matchPriority = priorityFilter.value === 'all' || story.priority === priorityFilter.value

    return matchSearch && matchStatus && matchPriority
  })
})

const statusCounts = computed(() => ({
  backlog: storiesStore.stories.filter((s) => s.status === 'backlog').length,
  in_progress: storiesStore.stories.filter((s) => s.status === 'in_progress').length,
  ready_for_test: storiesStore.stories.filter((s) => s.status === 'ready_for_test').length,
  completed: storiesStore.stories.filter((s) => s.status === 'completed').length,
}))

const statusLabels = {
  backlog: 'Backlog',
  in_progress: 'En cours',
  ready_for_test: 'Prêt pour test',
  completed: 'Terminée',
}

const priorityLabels = {
  critical: 'Critique',
  high: 'Haute',
  medium: 'Moyenne',
  low: 'Basse',
}

async function loadStories() {
  try {
    const resolvedProjectId = await resolveProjectId()

    if (!resolvedProjectId) {
      storiesStore.stories = []
      return
    }

    await storiesStore.fetchStories(resolvedProjectId)
    await storiesStore.fetchGeneratorStatus(resolvedProjectId)
  } catch (err) {
    if (err?.status === 401) {
      storiesStore.stories = []
      return
    }

    throw err
  }
}

async function deleteStory(storyId, event) {
  event.stopPropagation()
  if (!confirm(tr('confirm_delete_story', {}, settings.language))) return

  try {
    if (!projectId.value) {
      return
    }

    await storiesStore.deleteStory(projectId.value, storyId)
  } catch (err) {
    alert(`${tr('error_prefix', {}, settings.language)}: ${localizeError(err, 'error_generic', settings.language)}`)
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
  <section class="page stack story-workspace">
    <div class="story-command-card">
      <div class="story-command-copy">
        <p class="story-kicker">{{ roleStoryCopy.kicker }}</p>
        <h1>{{ roleStoryCopy.title }}</h1>
        <p>{{ roleStoryCopy.description }}</p>
      </div>

      <div class="story-command-actions">
        <div class="story-project-picker">
          <label>Projet actif</label>
          <select :value="projectId || ''" @change="selectProject($event.target.value)">
            <option value="" :disabled="loadingProjects">
              {{ loadingProjects ? 'Chargement des projets...' : 'Sélectionner un projet' }}
            </option>
            <option v-for="project in projects" :key="project.id" :value="project.id">
              {{ project.name }}
            </option>
          </select>
        </div>

        <div class="story-command-buttons">
          <RouterLink
            v-if="currentProject"
            :to="{ name: 'project-detail', params: { id: currentProject.id } }"
            class="btn btn-secondary btn-sm"
          >
            <FolderKanban :size="16" />
            <span>Consulter le projet</span>
          </RouterLink>

          <RouterLink
            v-if="canAddStories && projectId"
            :to="{ name: 'story-create', query: { projectId } }"
            class="btn btn-primary btn-sm"
          >
            <Plus :size="16" />
            <span>Ajouter une User Story</span>
          </RouterLink>
        </div>
      </div>
    </div>

    <div class="story-insight-grid">
      <article class="story-insight-card story-insight-highlight">
        <div class="story-insight-top">
          <div>
            <p class="story-insight-label">Projet sélectionné</p>
            <h2>{{ currentProject?.name || 'Aucun projet sélectionné' }}</h2>
          </div>
          <span class="story-insight-chip">{{ filteredStories.length }} User Stories visibles</span>
        </div>
        <p class="story-insight-text">
          {{ currentProject?.description || 'Choisissez un projet pour centraliser la navigation, la création des User Stories et la génération de checklists.' }}
        </p>
      </article>

      <article v-if="storiesStore.generatorStatus" class="story-insight-card">
        <div class="story-generator-line">
          <Zap :size="18" class="text-brand-600" />
          <div>
            <p class="story-insight-label">Moteur de génération</p>
            <strong>{{ storiesStore.generatorStatus.current || 'Détection en cours...' }}</strong>
          </div>
        </div>
        <p class="story-insight-text">
          {{ storiesStore.generatorStatus.available?.join(', ') || 'Vérification de disponibilité...' }}
        </p>
        <div class="story-status-ready">Système prêt</div>
      </article>
    </div>

    <div class="story-filter-card">
      <div class="story-filter-main">
        <div class="field">
          <label>Recherche</label>
          <input
            v-model="search"
            type="text"
            placeholder="Rechercher par titre, description ou logique métier..."
          />
        </div>

        <div class="field">
          <label>Statut</label>
          <select v-model="statusFilter">
            <option value="all">Tous</option>
            <option value="backlog">Backlog</option>
            <option value="in_progress">En cours</option>
            <option value="ready_for_test">Prêt pour test</option>
            <option value="completed">Terminée</option>
          </select>
        </div>

        <div class="field">
          <label>Priorité</label>
          <select v-model="priorityFilter">
            <option value="all">Toutes</option>
            <option value="critical">Critique</option>
            <option value="high">Haute</option>
            <option value="medium">Moyenne</option>
            <option value="low">Basse</option>
          </select>
        </div>
      </div>
    </div>

    <div class="story-metric-grid">
      <button
        v-for="(label, statusKey) in statusLabels"
        :key="statusKey"
        class="story-metric-card"
        :class="{ 'story-metric-active': statusFilter === statusKey }"
        @click="statusFilter = statusFilter === statusKey ? 'all' : statusKey"
      >
        <span class="story-metric-label">{{ label }}</span>
        <strong>{{ statusCounts[statusKey] }}</strong>
        <span class="story-metric-link">{{ statusFilter === statusKey ? 'Réinitialiser' : 'Filtrer' }}</span>
      </button>
    </div>

    <div v-if="storiesStore.loading" class="card story-loading-card">
      <div class="animate-spin rounded-full h-8 w-8 border border-brand-500 border-t-transparent"></div>
    </div>

    <div v-else-if="filteredStories.length > 0" class="story-list-grid">
      <article
        v-for="story in filteredStories"
        :key="story.id"
        class="story-card-pro"
        :class="`story-priority-${story.priority}`"
        @click="viewDetails(story.id)"
      >
        <div class="story-card-head">
          <div>
            <p class="story-card-id">User Story #{{ story.id }}</p>
            <h3>{{ story.title }}</h3>
          </div>
          <button
            v-if="canAddStories"
            @click.stop="deleteStory(story.id, $event)"
            class="story-delete-btn"
            title="Supprimer"
          >
            <Trash2 :size="16" />
          </button>
        </div>

        <p class="story-card-text">{{ story.description || 'Aucune description fournie.' }}</p>

        <div class="story-card-meta">
          <span :class="['story-pill', `story-pill-${story.status}`]">{{ statusLabels[story.status] }}</span>
          <span :class="['story-pill', `story-priority-pill-${story.priority}`]">{{ priorityLabels[story.priority] }}</span>
          <span class="story-pill story-pill-neutral">{{ story.checklists?.length || 0 }} checklist(s)</span>
        </div>

        <div class="story-card-footer">
          <span class="story-open-link">
            Voir les détails
            <ArrowRight :size="15" />
          </span>
        </div>
      </article>
    </div>

    <div v-else class="story-empty-state">
      <Sparkles :size="28" />
      <h3>Aucune User Story trouvée</h3>
      <p>
        Commencez par sélectionner un projet, puis ajoutez des User Stories qui serviront de base   la génération intelligente de checklists.
      </p>
      <RouterLink
        v-if="canAddStories && projectId"
        :to="{ name: 'story-create', query: { projectId } }"
        class="btn btn-primary btn-sm"
      >
        <Plus :size="16" />
        <span>Créer la première User Story</span>
      </RouterLink>
    </div>
  </section>
</template>
