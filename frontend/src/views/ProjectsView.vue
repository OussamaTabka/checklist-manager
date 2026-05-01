<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import {
  ArrowLeft,
  ArrowRight,
  ChartColumn,
  CirclePlus,
  LoaderCircle,
  Pencil,
  Rocket,
  Search,
  Save,
  Settings,
  Sparkles,
  Trash2,
  Users,
  X,
} from 'lucide-vue-next'
import { apiRequest, withQuery } from '@/lib/api'
import {
  analyzeImportedUserStories,
} from '@/lib/projectUserStories'
import { useAuthStore } from '@/stores/auth'
import { translateCurrentPhrase } from '@/lib/runtimeTranslations'

const auth = useAuthStore()
const route = useRoute()
const router = useRouter()

const projects = ref([])
const pagination = reactive({
  current_page: 1,
  last_page: 1,
})

const users = ref([])
const listError = ref('')
const createError = ref('')
const successMessage = ref('')
const loadingProjects = ref(false)
const creating = ref(false)
const showProjectForm = ref(false)
const showAdvancedFilters = ref(false)
const importValidationLoading = ref(false)
const importedStoriesFile = ref(null)
const importedStoriesAnalysis = ref(null)
const userStoriesFileInput = ref(null)

const filters = reactive({
  name: '',
  type: '',
  category: '',
  query: '',
  creator: 'all',
})

const form = reactive({
  id: null,
  name: '',
  description: '',
  test_objectives: '',
  app_url: '',
  tester_ids: [],
})

const projectPageCopy = computed(() => {
  switch (auth.primaryRole) {
    case 'admin':
      return {
        kicker: 'Espace administrateur',
        title: 'Projets',
        description:
          'Supervisez la configuration des projets, leur périmètre fonctionnel et l’organisation globale de la plateforme.',
      }
    case 'testeur':
      return {
        kicker: 'Espace d’exécution',
        title: 'Projets assignés',
        description:
          'Consultez les projets qui vous sont assignés, comprenez leur périmètre et accédez rapidement à l’exécution des tests.',
      }
    default:
      return {
        kicker: 'Espace chef de projet',
        title: 'Projets',
        description:
          'Créez les projets, définissez leur contexte, préparez le backlog initial et assignez les testeurs.',
      }
  }
})

const creatorFilterOptions = computed(() => {
  const creators = projects.value
    .map((project) => project.creator)
    .filter((creator) => Boolean(creator?.id))

  const seen = new Set()
  return creators.filter((creator) => {
    if (seen.has(creator.id)) {
      return false
    }

    seen.add(creator.id)
    return true
  })
})

const activeFilterBadges = computed(() => {
  const badges = []

  if (filters.name.trim() !== '') {
    badges.push({ key: 'name', label: `Nom : ${filters.name.trim()}` })
  }

  if (filters.type.trim() !== '') {
    badges.push({ key: 'type', label: `Type : ${filters.type.trim()}` })
  }

  if (filters.category.trim() !== '') {
    badges.push({ key: 'category', label: `Catégorie : ${filters.category.trim()}` })
  }

  if (filters.query.trim() !== '') {
    badges.push({ key: 'query', label: `Recherche : ${filters.query.trim()}` })
  }

  if (filters.creator !== 'all') {
    const creator = creatorFilterOptions.value.find((item) => String(item.id) === String(filters.creator))
    badges.push({ key: 'creator', label: `Créateur : ${creator?.name || filters.creator}` })
  }

  return badges
})

const hasActiveFilters = computed(() => activeFilterBadges.value.length > 0)
const importedValidUserStoriesCount = computed(() => importedStoriesAnalysis.value?.validCount || 0)
const importedInvalidUserStoriesCount = computed(() => importedStoriesAnalysis.value?.invalidCount || 0)
const hasImportedUserStories = computed(() => importedValidUserStoriesCount.value >= 1)

const projectMetrics = computed(() => {
  const totalProjects = projects.value.length
  const totalAssignedTesters = projects.value.reduce((count, project) => count + (project.testers?.length || 0), 0)
  const totalLinkedStories = projects.value.reduce((count, project) => count + Number(project.user_stories_count || 0), 0)
  const projectsWithUrl = projects.value.filter((project) => Boolean(project.app_url)).length

  return [
    { label: 'Projets', value: totalProjects, caption: 'Projets disponibles dans l’espace de travail' },
    { label: 'Testeurs assignés', value: totalAssignedTesters, caption: 'Capacité d’exécution mobilisée' },
    { label: 'User Stories liées', value: totalLinkedStories, caption: 'Périmètre fonctionnel déjà préparé' },
    { label: 'Environnements prêts', value: projectsWithUrl, caption: 'Projets disposant d’une URL cible' },
  ]
})

const filteredProjects = computed(() => {
  return projects.value.filter((project) => {
    const projectType = String(project.type || project.project_type || '').trim()
    const projectCategory = String(project.category || '').trim()
    const searchText = `${project.name || ''} ${project.description || ''} ${project.test_objectives || ''} ${project.app_url || ''} ${projectType} ${projectCategory}`.toLowerCase()

    const matchesName =
      filters.name.trim() === '' ||
      String(project.name || '').toLowerCase().includes(filters.name.trim().toLowerCase())
    const matchesType =
      filters.type.trim() === '' ||
      projectType.toLowerCase().includes(filters.type.trim().toLowerCase())
    const matchesCategory =
      filters.category.trim() === '' ||
      projectCategory.toLowerCase().includes(filters.category.trim().toLowerCase())
    const matchesQuery =
      filters.query.trim() === '' || searchText.includes(filters.query.trim().toLowerCase())
    const matchesCreator =
      filters.creator === 'all' ||
      String(project.creator?.id || '') === String(filters.creator)

    return matchesName && matchesType && matchesCategory && matchesQuery && matchesCreator
  })
})

async function loadProjects(page = 1) {
  loadingProjects.value = true
  listError.value = ''

  try {
    const data = await apiRequest(withQuery('/projects', { page }), {}, auth.token)
    projects.value = data.data || []
    pagination.current_page = data.current_page
    pagination.last_page = data.last_page
  } catch (error) {
    listError.value = error.data?.message || error.message
  } finally {
    loadingProjects.value = false
  }
}

async function loadProjectMetadata() {
  if (!auth.canManageProjects) {
    return
  }

  try {
    const data = await apiRequest('/projects/metadata', {}, auth.token)
    users.value = data.testers || []
  } catch (error) {
    users.value = []
    createError.value =
      error.data?.message || error.message || 'Impossible de charger les données nécessaires à la création du projet.'
  }
}

async function submitProject() {
  createError.value = ''
  successMessage.value = ''
  creating.value = true

  try {
    if (form.id) {
      const payload = {
        name: form.name,
        description: form.description || null,
        test_objectives: form.test_objectives || null,
        app_url: form.app_url,
        tester_ids: form.tester_ids.map((id) => Number(id)),
      }

      await apiRequest(`/projects/${form.id}`, { method: 'PUT', body: payload }, auth.token)
      successMessage.value = 'Le projet a été mis à jour avec succès.'
    } else {
      if (importedStoriesFile.value && !importedStoriesAnalysis.value) {
        await analyzeSelectedUserStoriesFile()
      }

      if (!hasImportedUserStories.value) {
        createError.value = 'Veuillez importer un fichier contenant au moins une User Story valide.'
        return
      }

      const payload = new FormData()
      payload.append('name', form.name)
      payload.append('description', form.description || '')
      payload.append('test_objectives', form.test_objectives || '')
      payload.append('app_url', form.app_url)

      form.tester_ids.forEach((id) => {
        payload.append('tester_ids[]', String(Number(id)))
      })

      if (importedStoriesFile.value) {
        payload.append('user_stories_file', importedStoriesFile.value)
      }

      const response = await apiRequest('/projects', { method: 'POST', body: payload }, auth.token)
      const summary = response?.user_stories_summary

      if (summary) {
        const ignoredCount = Number(summary.failed_imports || 0)
        if (ignoredCount > 0) {
          successMessage.value = `Projet créé avec succès. ${summary.total_created} User Stories ont été ajoutées et ${ignoredCount} ligne(s) ont été ignorée(s).`
        } else {
          successMessage.value = `Projet créé avec succès. ${summary.total_created} User Stories ont été ajoutées.`
        }
      } else {
        successMessage.value = 'Projet créé avec succès.'
      }

      resetForm(true)
      await loadProjects(1)
      return
    }

    resetForm(true)
    await loadProjects(1)
  } catch (error) {
    createError.value =
      error.data?.message || error.message || 'Impossible de créer le projet. Veuillez vérifier les informations saisies.'
  } finally {
    creating.value = false
  }
}

async function analyzeSelectedUserStoriesFile() {
  createError.value = ''
  importedStoriesAnalysis.value = null

  if (!importedStoriesFile.value) {
    return
  }

  importValidationLoading.value = true

  try {
    importedStoriesAnalysis.value = await analyzeImportedUserStories(importedStoriesFile.value)
  } catch (error) {
    createError.value = error.message || 'Impossible de lire le fichier de User Stories.'
  } finally {
    importValidationLoading.value = false
  }
}

async function onUserStoriesFileChange(event) {
  importedStoriesFile.value = event.target.files?.[0] || null
  importedStoriesAnalysis.value = null

  if (importedStoriesFile.value) {
    await analyzeSelectedUserStoriesFile()
  }
}

function clearUserStoriesFile() {
  importedStoriesFile.value = null
  importedStoriesAnalysis.value = null

  if (userStoriesFileInput.value) {
    userStoriesFileInput.value.value = ''
  }
}

function resetUserStoriesSection() {
  clearUserStoriesFile()
}

function resetForm(closeForm = false) {
  form.id = null
  form.name = ''
  form.description = ''
  form.test_objectives = ''
  form.app_url = ''
  form.tester_ids = []
  resetUserStoriesSection()

  if (closeForm) {
    showProjectForm.value = false
  }
}

function clearAllFilters() {
  filters.name = ''
  filters.type = ''
  filters.category = ''
  filters.query = ''
  filters.creator = 'all'
}

function removeFilter(key) {
  if (key === 'name') {
    filters.name = ''
    return
  }

  if (key === 'type') {
    filters.type = ''
    return
  }

  if (key === 'category') {
    filters.category = ''
    return
  }

  if (key === 'query') {
    filters.query = ''
    return
  }

  if (key === 'creator') {
    filters.creator = 'all'
  }
}

function toggleCreatorFilter(id) {
  const value = String(id)
  filters.creator = filters.creator === value ? 'all' : value
}

function getHostname(url) {
  try {
    return new URL(url).hostname
  } catch {
    return url
  }
}

function openCreateForm() {
  createError.value = ''
  successMessage.value = ''
  resetForm(false)
  showProjectForm.value = true
}

function consumeCreateQuery() {
  if (route.query.create !== '1') {
    return
  }

  const nextQuery = { ...route.query }
  delete nextQuery.create
  router.replace({ query: nextQuery })
}

function openCreateFormFromQuery() {
  if (!auth.canManageProjects || route.query.create !== '1') {
    return
  }

  openCreateForm()
  consumeCreateQuery()
}

function editProject(project) {
  createError.value = ''
  successMessage.value = ''
  resetUserStoriesSection()
  showProjectForm.value = true
  form.id = project.id
  form.name = project.name
  form.description = project.description || ''
  form.test_objectives = project.test_objectives || ''
  form.app_url = project.app_url || ''
  form.tester_ids = (project.testers || []).map((tester) => tester.id)
}

function canManageProject(project) {
  return auth.isAdmin || project.created_by === auth.user?.id
}

async function deleteProject(projectId) {
  if (!confirm(translateCurrentPhrase('Are you sure you want to delete this project?'))) {
    return
  }

  listError.value = ''

  try {
    await apiRequest(`/projects/${projectId}`, { method: 'DELETE' }, auth.token)
    successMessage.value = 'Le projet a été archivé avec succès.'
    await loadProjects(1)
  } catch (error) {
    listError.value = error.data?.message || error.message
  }
}

onMounted(async () => {
  await Promise.all([loadProjects(), loadProjectMetadata()])
  openCreateFormFromQuery()
})

watch(
  () => route.query.create,
  () => {
    openCreateFormFromQuery()
  },
)
</script>

<template>
  <section class="page stack">
    <div class="dashboard-command">
      <div>
        <p class="dashboard-eyebrow">{{ projectPageCopy.kicker }}</p>
        <h1 class="page-title-icon">
          <Rocket :size="30" :stroke-width="2.3" />
          <span>{{ projectPageCopy.title }}</span>
        </h1>
        <p class="muted page-subtitle">{{ projectPageCopy.description }}</p>
      </div>
      <div class="dashboard-command-actions">
        <button v-if="auth.canManageProjects" class="btn btn-primary create-project-btn" type="button" @click="openCreateForm">
          <CirclePlus :size="16" />
          <span>Créer un projet</span>
        </button>
      </div>
    </div>

    <div class="story-detail-metrics">
      <article v-for="metric in projectMetrics" :key="metric.label" class="story-detail-metric">
        <span>{{ metric.label }}</span>
        <strong>{{ metric.value }}</strong>
        <p class="muted">{{ metric.caption }}</p>
      </article>
    </div>

    <div class="card stack stack-gap-sm">
      <div class="search-top-row">
        <div class="search-input-wrap">
          <Search :size="18" :stroke-width="2.1" />
          <input v-model="filters.query" placeholder="Rechercher un projet..." class="search-input" />
        </div>
      </div>

      <div class="actions actions-between">
        <button class="btn btn-secondary btn-sm" type="button" @click="showAdvancedFilters = !showAdvancedFilters">
          <span>{{ showAdvancedFilters ? 'Masquer les filtres' : 'Afficher les filtres' }}</span>
        </button>

        <button v-if="hasActiveFilters" class="btn btn-secondary btn-sm" type="button" @click="clearAllFilters">
          Réinitialiser
        </button>
      </div>

      <div v-if="showAdvancedFilters" class="stack advanced-filters-stack">
        <div class="grid filters-grid">
          <div class="field">
            <label class="field-label-strong">Nom</label>
            <input v-model="filters.name" placeholder="Filtrer par nom de projet" />
          </div>

          <div class="field">
            <label class="field-label-strong">Type</label>
            <input v-model="filters.type" placeholder="Filtrer par type" />
          </div>

          <div class="field">
            <label class="field-label-strong">Catégorie</label>
            <input v-model="filters.category" placeholder="Filtrer par catégorie" />
          </div>
        </div>

        <div class="field field-tight">
          <label class="field-label-strong">Créateur</label>
          <div class="chip-row">
            <button type="button" class="filter-chip" :class="{ active: filters.creator === 'all' }" @click="filters.creator = 'all'">
              Tous
            </button>
            <button
              v-for="creator in creatorFilterOptions"
              :key="creator.id"
              type="button"
              class="filter-chip"
              :class="{ active: filters.creator === String(creator.id) }"
              @click="toggleCreatorFilter(creator.id)"
            >
              {{ creator.name }}
            </button>
          </div>
        </div>
      </div>

      <div v-if="hasActiveFilters" class="active-filters-row">
        <span class="muted active-filters-label">Filtres appliqués :</span>
        <div class="chip-row">
          <span v-for="badge in activeFilterBadges" :key="badge.key" class="applied-chip">
            {{ badge.label }}
            <button type="button" @click="removeFilter(badge.key)">
              <X :size="12" />
            </button>
          </span>
        </div>
      </div>
    </div>

    <div v-if="auth.canManageProjects && showProjectForm" class="card stack">
      <div class="section-divider">
        <h2 class="section-heading-with-icon">
          <Pencil v-if="form.id" :size="20" :stroke-width="2.2" />
          <CirclePlus v-else :size="20" :stroke-width="2.2" />
          <span>{{ form.id ? 'Modifier le projet' : 'Créer un projet' }}</span>
        </h2>
      </div>

      <p v-if="createError" class="error" data-testid="projects-msg-error">{{ createError }}</p>
      <p v-if="successMessage" class="success" data-testid="projects-msg-success">{{ successMessage }}</p>

      <form class="stack" @submit.prevent="submitProject" data-testid="projects-form">
        <div class="form-grid-two">
          <div class="field">
            <label class="field-label-strong">Nom du projet</label>
            <input v-model="form.name" required placeholder="ex. : Phase 1 de test API" data-testid="projects-input-name" />
          </div>
          <div class="field">
            <label class="field-label-strong">URL de l’application</label>
            <input v-model="form.app_url" type="url" placeholder="https://example.com" required data-testid="projects-input-app-url" />
          </div>
        </div>

        <div class="field">
          <label class="field-label-strong">Description</label>
          <textarea v-model="form.description" rows="3" placeholder="Décrivez le contexte, le périmètre et les enjeux métier du projet..." />
        </div>

        <div class="field">
          <label class="field-label-strong">Objectifs de test</label>
          <textarea v-model="form.test_objectives" rows="3" placeholder="Précisez les objectifs QA, les risques à couvrir et les résultats attendus..." />
        </div>

        <div class="card project-setup-section">
          <div class="section-divider">
            <h3 class="section-heading-with-icon-sm">
              <Settings :size="18" :stroke-width="2.2" />
              <span>Équipe assignée</span>
            </h3>
          </div>

          <div class="field">
            <label class="label-with-icon">
              <Users :size="18" :stroke-width="2.1" />
              Assigner les testeurs
            </label>
            <p class="muted helper-text">
              Sélectionnez les testeurs responsables de la préparation des checklists et de l’exécution des tests.
            </p>
            <div v-if="users.length === 0" class="muted empty-state-box">
              Aucun testeur disponible pour le moment.
            </div>
            <div v-else class="selection-grid">
              <label v-for="user in users" :key="user.id" class="selection-card selection-card-green">
                <input type="checkbox" :value="user.id" v-model="form.tester_ids" class="selection-check" />
                <div class="selection-body">
                  <div class="selection-title">{{ user.name }}</div>
                  <div class="muted selection-meta">{{ user.email }}</div>
                </div>
              </label>
            </div>
          </div>
        </div>

        <div v-if="!form.id" class="card project-setup-section stack">
          <div class="section-divider">
            <h3 class="section-heading-with-icon-sm">
              <CirclePlus :size="18" :stroke-width="2.2" />
              <span>Backlog initial du projet</span>
            </h3>
          </div>

          <p class="muted helper-text">
            Importez un fichier contenant les User Stories initiales du projet afin de définir le périmètre fonctionnel du backlog dès la création.
          </p>

          <div class="story-source-summary">
            <span class="mini-chip">{{ importedValidUserStoriesCount }} User Story{{ importedValidUserStoriesCount > 1 ? 'ies' : 'y' }} importée{{ importedValidUserStoriesCount > 1 ? 's' : '' }}</span>
            <span v-if="importedInvalidUserStoriesCount > 0" class="mini-chip mini-chip-warn">
              {{ importedInvalidUserStoriesCount }} ligne(s) ignorée(s)
            </span>
          </div>

          <div class="card stack stack-gap-sm">
            <div class="section-divider">
              <h4 class="section-heading-with-icon-sm">
                <Sparkles :size="16" :stroke-width="2.1" />
                <span>Import depuis un fichier</span>
              </h4>
            </div>

            <p class="muted helper-text">
              Importez un fichier CSV, XLSX ou JSON contenant plusieurs User Stories structurées.
            </p>

            <div class="field">
              <label class="field-label-strong">Fichier de User Stories</label>
              <input ref="userStoriesFileInput" type="file" accept=".csv,.xlsx,.json" @change="onUserStoriesFileChange" />
              <p class="muted helper-text">
                Formats acceptés : CSV, XLSX ou JSON. Cette option est recommandée pour importer rapidement un backlog volumineux.
              </p>
            </div>

            <div v-if="importValidationLoading" class="muted">Analyse du fichier en cours...</div>

            <div v-if="importedStoriesFile" class="story-import-summary">
              <p><strong>{{ importedStoriesFile.name }}</strong></p>
              <p class="muted">
                {{ importedValidUserStoriesCount }} User Story{{ importedValidUserStoriesCount > 1 ? 'ies' : 'y' }} importée{{ importedValidUserStoriesCount > 1 ? 's' : '' }}
                <span v-if="importedInvalidUserStoriesCount > 0"> • {{ importedInvalidUserStoriesCount }} ligne(s) ignorée(s)</span>
              </p>
              <button type="button" class="btn btn-secondary btn-sm" @click="clearUserStoriesFile">
                <X :size="14" />
                <span>Retirer le fichier</span>
              </button>
            </div>

            <div v-if="importedStoriesAnalysis?.errors?.length" class="stack stack-gap-sm">
              <p class="muted">Lignes non importées :</p>
              <div v-for="error in importedStoriesAnalysis.errors.slice(0, 5)" :key="`${error.row}-${error.message}`" class="error-row">
                Ligne {{ error.row }} : {{ error.message }}
              </div>
            </div>
          </div>
        </div>

        <div class="form-actions-row">
          <button class="btn btn-primary btn-min-wide" type="submit" :disabled="creating" data-testid="projects-btn-submit">
            <LoaderCircle v-if="creating" :size="16" class="spin" />
            <Save v-else-if="form.id" :size="16" />
            <Sparkles v-else :size="16" />
            <span>{{ creating ? (form.id ? 'Mise à jour...' : 'Création...') : (form.id ? 'Enregistrer les modifications' : 'Créer le projet') }}</span>
          </button>
          <button type="button" class="btn btn-secondary btn-inline-icon" @click="resetForm(true)">
            <X :size="16" />
            <span>Fermer</span>
          </button>
        </div>
      </form>
    </div>

    <div class="card stack section-space-xl">
      <div class="section-divider">
        <h2 class="section-heading-with-icon">
          <ChartColumn :size="20" :stroke-width="2.2" />
          <span>Liste des projets</span>
        </h2>
      </div>

      <p class="muted">
        Ouvrez un projet pour accéder à son espace de suivi, consulter les versions d’exécution et lancer les tests sur les éléments exécutables.
      </p>

      <p v-if="listError" class="error" data-testid="projects-msg-error-list">{{ listError }}</p>
      <p v-if="loadingProjects" class="muted">Chargement des projets...</p>

      <div v-if="!loadingProjects && filteredProjects.length > 0" class="projects-grid" data-testid="projects-table">
        <article v-for="project in filteredProjects" :key="project.id" class="project-card">
          <div class="project-card-head">
            <div>
              <RouterLink :to="{ name: 'project-detail', params: { id: project.id } }" class="project-title-link">
                {{ project.name }}
              </RouterLink>
              <p class="muted meta-line">#{{ project.id }} • {{ project.creator?.name || 'Créateur inconnu' }}</p>
            </div>
          </div>

          <p class="muted description-fixed">{{ project.description || 'Aucun contexte projet renseigné.' }}</p>

          <div class="project-meta-row">
            <span class="mini-label">Backlog</span>
            <div class="chip-row">
              <span class="mini-chip">{{ project.user_stories_count || 0 }} User Story{{ (project.user_stories_count || 0) > 1 ? 'ies' : '' }}</span>
              <span class="mini-chip">{{ project.versions_count || 0 }} version(s) d’exécution</span>
            </div>
          </div>

          <div class="project-meta-row">
            <span class="mini-label">Objectifs</span>
            <div class="chip-row">
              <span class="muted">{{ project.test_objectives || 'Aucun objectif de test défini pour le moment.' }}</span>
            </div>
          </div>

          <div class="project-meta-row">
            <span class="mini-label">Testeurs</span>
            <div class="chip-row">
              <span v-for="tester in project.testers || []" :key="tester.id" class="mini-chip tester-chip">{{ tester.name }}</span>
              <span v-if="!project.testers || project.testers.length === 0" class="muted">Aucun testeur assigné</span>
            </div>
          </div>

          <div class="project-card-footer">
            <a v-if="project.app_url" :href="project.app_url" target="_blank" rel="noopener noreferrer" class="muted app-url-text">
              {{ getHostname(project.app_url) }}
            </a>
            <span v-else class="muted app-url-text">Aucune URL renseignée</span>

            <div class="actions">
              <button v-if="canManageProject(project)" class="btn btn-secondary btn-sm" @click="editProject(project)">
                <Pencil :size="14" />
                <span>Modifier</span>
              </button>
              <button v-if="canManageProject(project)" class="btn btn-danger btn-sm" @click="deleteProject(project.id)">
                <Trash2 :size="14" />
                <span>Archiver</span>
              </button>
            </div>
          </div>
        </article>
      </div>

      <div v-if="!loadingProjects && filteredProjects.length === 0" class="card empty-dashed-card">
        <p class="muted">Aucun projet ne correspond aux filtres sélectionnés.</p>
      </div>

      <div class="pagination pagination-centered">
        <button class="btn btn-secondary btn-sm btn-nav-icon" :disabled="pagination.current_page <= 1" @click="loadProjects(pagination.current_page - 1)" title="Aller à la page précédente">
          <ArrowLeft :size="14" />
          <span>Précédent</span>
        </button>
        <span class="muted pagination-text">Page {{ pagination.current_page }} sur {{ pagination.last_page }}</span>
        <button class="btn btn-secondary btn-sm btn-nav-icon" :disabled="pagination.current_page >= pagination.last_page" @click="loadProjects(pagination.current_page + 1)" title="Aller à la page suivante">
          <span>Suivant</span>
          <ArrowRight :size="14" />
        </button>
      </div>
    </div>
  </section>
</template>

<style>
.story-detail-metrics {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
  gap: 0.9rem;
}

.story-detail-metric {
  padding: 1rem 1.05rem;
  border-radius: 1.15rem;
  background: linear-gradient(180deg, rgba(255, 255, 255, 0.98), rgba(248, 250, 252, 0.96));
  border: 1px solid rgba(148, 163, 184, 0.18);
  box-shadow: 0 16px 30px -28px rgba(15, 23, 42, 0.28);
}

.story-detail-metric span {
  display: block;
  margin-bottom: 0.35rem;
  font-size: 0.75rem;
  letter-spacing: 0.12em;
  text-transform: uppercase;
  color: #64748b;
  font-weight: 800;
}

.story-detail-metric strong {
  font-size: 1.25rem;
  color: #0f172a;
}

.story-detail-metric p {
  margin: 0.45rem 0 0;
}

.story-source-summary,
.manual-story-card-head {
  display: flex;
  gap: 0.75rem;
  flex-wrap: wrap;
  justify-content: space-between;
  align-items: flex-start;
}

.manual-story-card,
.story-import-summary {
  padding: 1rem;
  border: 1px solid rgba(148, 163, 184, 0.22);
  border-radius: 1rem;
  background: rgba(248, 250, 252, 0.7);
}

.manual-story-card p,
.story-import-summary p {
  margin: 0.25rem 0 0;
}

.mini-chip-warn {
  background: #fff7ed;
  color: #9a3412;
}

.error-row {
  padding: 0.7rem 0.85rem;
  border-radius: 0.85rem;
  background: #fff1f2;
  color: #be123c;
  border: 1px solid #fecdd3;
}

.dark .story-detail-metric {
  background: rgba(15, 23, 42, 0.92);
  border-color: rgba(51, 65, 85, 0.9);
}

.dark .story-detail-metric span {
  color: #94a3b8;
}

.dark .story-detail-metric strong {
  color: #f8fafc;
}

@media (max-width: 720px) {
  .story-source-summary,
  .manual-story-card-head {
    flex-direction: column;
  }
}
</style>
