<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import { Archive, Braces, CheckCircle2, CirclePlus, ClipboardList, Download, ExternalLink, FileText, Layers3, Pencil, Save, Users, X } from 'lucide-vue-next'
import { apiDownload, apiRequest } from '@/lib/api'
import { useAuthStore } from '@/stores/auth'
import { useSettingsStore } from '@/stores/settings'
import { useToastStore } from '@/stores/toast'
import { useUserStoriesStore } from '@/stores/userStories'
import { localizeError, tr } from '@/lib/localization'

const route = useRoute()
const auth = useAuthStore()
const settings = useSettingsStore()
const toast = useToastStore()
const storiesStore = useUserStoriesStore()

const project = ref(null)
const loading = ref(false)
const pageError = ref('')
const actionError = ref('')
const actionSuccess = ref('')
const openStoryMenuId = ref(null)
const showProjectEditForm = ref(false)
const loadingProjectEditMeta = ref(false)
const savingProject = ref(false)
const projectFormError = ref('')
const availableTesters = ref([])
const reportModalOpen = ref(false)
const exportLoading = ref(false)
const reportModalError = ref('')
const reportOptions = ref({
  format: 'pdf',
  type: 'summary',
  include_user_stories: true,
  include_checklists: true,
  include_execution_results: true,
  include_failed_blocked: true,
  include_comments: true,
  include_history: true,
  include_automation_traces: true,
})

const storyStatusLabels = {
  backlog: 'Backlog',
  in_progress: 'In Progress',
  ready_for_test: 'Ready for Test',
  completed: 'Completed',
}

const isAdminReadonly = auth.isSystemAdmin && !auth.isProjectManager && !auth.isTester
const canExportProjectReport = computed(() => auth.isProjectManager && project.value?.created_by === auth.user?.id)
const canEditProject = computed(() => auth.canManageProjects && (auth.isAdmin || project.value?.created_by === auth.user?.id))
const projectEditForm = reactive({
  name: '',
  description: '',
  test_objectives: '',
  app_url: '',
  tester_ids: [],
})
const selectedReportType = computed(() => reportOptions.value.type)
const enabledReportSectionsCount = computed(() => [
  reportOptions.value.include_user_stories,
  reportOptions.value.include_checklists,
  reportOptions.value.include_execution_results,
  reportOptions.value.include_failed_blocked,
  reportOptions.value.include_comments,
  reportOptions.value.include_history,
  reportOptions.value.include_automation_traces,
].filter(Boolean).length)
const reportFormats = computed(() => [
  {
    value: 'pdf',
    label: 'PDF',
    description: 'Rapport lisible et pret a partager',
    icon: FileText,
    disabled: false,
    soon: false,
  },
  {
    value: 'json',
    label: 'JSON',
    description: 'Donnees structurees du projet',
    icon: Braces,
    disabled: false,
    soon: false,
  },
  {
    value: 'zip',
    label: 'ZIP',
    description: 'Rapport + traces et artefacts',
    icon: Archive,
    disabled: false,
    soon: false,
  },
])
const reportTypeLabel = computed(() => (reportOptions.value.type === 'summary' ? 'SynthÃ©tique' : 'DÃ©taillÃ©'))
const reportTypes = computed(() => [
  {
    value: 'summary',
    label: 'SynthÃ©tique',
    description: 'KPI et rÃ©sumÃ© global',
    disabled: false,
  },
  {
    value: 'detailed',
    label: 'DÃ©taillÃ©',
    description: 'DonnÃ©es complÃ¨tes et traÃ§abilitÃ©',
    disabled: false,
  },
])

function toggleStoryMenu(storyId) {
  openStoryMenuId.value = openStoryMenuId.value === storyId ? null : storyId
}

function closeStoryMenu() {
  openStoryMenuId.value = null
}

function handleWindowClick() {
  closeStoryMenu()
}

function openReportModal() {
  if (!canExportProjectReport.value) {
    return
  }

  reportModalError.value = ''
  reportModalOpen.value = true
}

function fillProjectEditForm() {
  projectEditForm.name = project.value?.name || ''
  projectEditForm.description = project.value?.description || ''
  projectEditForm.test_objectives = project.value?.test_objectives || ''
  projectEditForm.app_url = project.value?.app_url || ''
  projectEditForm.tester_ids = (project.value?.testers || []).map((tester) => Number(tester.id))
}

async function loadProjectEditMetadata() {
  if (!canEditProject.value) {
    availableTesters.value = []
    return
  }

  try {
    loadingProjectEditMeta.value = true
    const data = await apiRequest('/projects/metadata', {}, auth.token)
    availableTesters.value = Array.isArray(data?.testers) ? data.testers : []
  } catch {
    availableTesters.value = []
  } finally {
    loadingProjectEditMeta.value = false
  }
}

async function toggleProjectEditForm() {
  if (!project.value || !canEditProject.value) {
    return
  }

  if (showProjectEditForm.value) {
    showProjectEditForm.value = false
    projectFormError.value = ''
    return
  }

  fillProjectEditForm()
  projectFormError.value = ''
  showProjectEditForm.value = true

  if (availableTesters.value.length === 0) {
    await loadProjectEditMetadata()
  }
}

async function submitProjectEdit() {
  if (!project.value || !canEditProject.value || savingProject.value) {
    return
  }

  projectFormError.value = ''
  savingProject.value = true

  try {
    const payload = {
      name: projectEditForm.name,
      description: projectEditForm.description || null,
      test_objectives: projectEditForm.test_objectives || null,
      app_url: projectEditForm.app_url,
      tester_ids: projectEditForm.tester_ids.map((id) => Number(id)),
    }

    await apiRequest(`/projects/${project.value.id}`, { method: 'PUT', body: payload }, auth.token)
    await loadProject()
    fillProjectEditForm()
    showProjectEditForm.value = false
    toast.success('Le projet a ete mis a jour avec succes.')
  } catch (error) {
    projectFormError.value = localizeError(error, 'error_generic', settings.language)
  } finally {
    savingProject.value = false
  }
}

function closeReportModal() {
  if (exportLoading.value) {
    return
  }

  reportModalOpen.value = false
  reportModalError.value = ''
}

function selectReportFormat(format) {
  const formatOption = reportFormats.value.find((entry) => entry.value === format)
  if (!formatOption || formatOption.disabled) {
    return
  }

  reportOptions.value.format = format

  if (!reportOptions.value.type) {
    reportOptions.value.type = 'summary'
  }

  reportModalError.value = ''
}

function selectReportType(type) {
  const option = reportTypes.value.find((entry) => entry.value === type)
  if (!option || option.disabled) {
    return
  }

  reportOptions.value.type = type
  reportModalError.value = ''
}

async function exportProjectReport() {
  if (!project.value || exportLoading.value) {
    return
  }

  exportLoading.value = true
  reportModalError.value = ''

  try {
    const { blob, filename } = await apiDownload(
      `/projects/${project.value.id}/reports/export`,
      {
        query: {
          format: reportOptions.value.format,
          type: selectedReportType.value,
          include_user_stories: reportOptions.value.include_user_stories,
          include_checklists: reportOptions.value.include_checklists,
          include_execution_results: reportOptions.value.include_execution_results,
          include_failed_blocked: reportOptions.value.include_failed_blocked,
          include_comments: reportOptions.value.include_comments,
          include_history: reportOptions.value.include_history,
          include_automation_traces: reportOptions.value.include_automation_traces,
        },
        fallbackFilename: `${project.value.name || 'project'}-report.${reportOptions.value.format}`,
      },
      auth.token,
    )

    const downloadUrl = window.URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.href = downloadUrl
    link.download = filename
    document.body.appendChild(link)
    link.click()
    link.remove()
    window.URL.revokeObjectURL(downloadUrl)

    reportModalOpen.value = false
    toast.success('Rapport du projet exporte avec succes.')
  } catch (error) {
    if (error?.status === 403) {
      reportModalError.value = "Vous n'Ãªtes pas autorisÃ© Ã  exporter ce rapport."
      toast.error("Vous n'Ãªtes pas autorisÃ© Ã  exporter ce rapport.")
      return
    }

    if (error?.status === 404) {
      reportModalError.value = 'Projet introuvable.'
      toast.error('Projet introuvable.')
      return
    }

    if (reportOptions.value.format === 'zip' && (error?.status === 422 || error?.status === 501)) {
      reportModalError.value = "L'export ZIP n'est pas encore disponible."
      toast.error("L'export ZIP n'est pas encore disponible.")
      return
    }

    reportModalError.value = 'Une erreur est survenue lors de la generation du rapport.'
    toast.error('Une erreur est survenue lors de la generation du rapport.')
  } finally {
    exportLoading.value = false
  }
}

async function loadProject() {
  loading.value = true
  pageError.value = ''

  try {
    project.value = await apiRequest(`/projects/${route.params.id}`, {}, auth.token)
  } catch (error) {
    pageError.value = localizeError(error, 'error_generic', settings.language)
  } finally {
    loading.value = false
  }
}

async function loadUserStories() {
  try {
    await storiesStore.fetchStories(route.params.id)
  } catch {
    // Store error is enough for this embedded view.
  }
}

async function deleteStory(story) {
  closeStoryMenu()
  actionError.value = ''
  actionSuccess.value = ''

  if (!confirm(tr('confirm_delete_story_title', { title: story.title }, settings.language))) {
    return
  }

  try {
    await storiesStore.deleteStory(route.params.id, story.id)
    actionSuccess.value = localizeError({ message: 'La user story a ete supprimee avec succes.' }, 'error_generic', settings.language)
  } catch (error) {
    actionError.value = localizeError(error, 'error_generic', settings.language)
  }
}

watch(
  () => route.params.id,
  async (value, oldValue) => {
    if (value && value !== oldValue) {
      await Promise.all([loadProject(), loadUserStories()])
    }
  },
)

watch(actionSuccess, (message) => {
  if (!message) {
    return
  }

  toast.success(message)
  actionSuccess.value = ''
})

onMounted(async () => {
  window.addEventListener('click', handleWindowClick)
  await Promise.all([loadProject(), loadUserStories()])
})

onBeforeUnmount(() => {
  window.removeEventListener('click', handleWindowClick)
})
</script>

<template>
  <section class="page stack">
    <p v-if="pageError" class="error">{{ pageError }}</p>
    <p v-if="loading" class="muted">Loading project...</p>

    <template v-if="project && !loading">
      <div class="project-execution-hero">
        <div class="project-execution-copy">
          <div class="project-execution-copy-top">
            <p class="project-execution-kicker">{{ isAdminReadonly ? 'Project overview' : 'Project workspace' }}</p>
          </div>
          <h1>{{ project.name }}</h1>
          <p class="project-execution-subtitle">
            {{ isAdminReadonly
              ? 'Project details and linked user stories in read-only mode.'
              : 'Project context and linked user stories in one place.' }}
          </p>

          <div class="project-meta-pills">
            <span class="project-meta-pill">
              <ClipboardList :size="14" />
              <span>{{ storiesStore.stories.length }} stor{{ storiesStore.stories.length === 1 ? 'y' : 'ies' }}</span>
            </span>
            <span class="project-meta-pill">
              <Layers3 :size="14" />
              <span>{{ (project.testers || []).length }} tester<span v-if="(project.testers || []).length !== 1">s</span></span>
            </span>
          </div>

          <div class="project-execution-app-card compact project-context-app-card">
            <div class="project-side-topline">
              <span class="project-execution-side-label">Target application</span>
            </div>
            <a
              v-if="project.app_url"
              :href="project.app_url"
              target="_blank"
              rel="noopener noreferrer"
              class="project-app-link"
            >
              <span>{{ project.app_url }}</span>
              <ExternalLink :size="14" />
            </a>
            <p v-else class="muted">No app URL configured yet.</p>
            <div class="project-side-copy">
              <p class="muted">Objectives: {{ project.test_objectives || 'No test objectives defined yet.' }}</p>
              <p class="muted">{{ project.description || 'No project description defined yet.' }}</p>
            </div>
          </div>
        </div>

        <div class="project-execution-side">
          <div class="project-execution-side-actions">
            <div v-if="canEditProject" class="project-side-actions">
              <button type="button" class="report-export-button subtle" @click="toggleProjectEditForm">
                <Pencil :size="15" />
                <span>{{ showProjectEditForm ? 'Fermer la modification' : 'Modifier le projet' }}</span>
              </button>
            </div>
            <div v-if="canExportProjectReport" class="project-side-actions">
              <button type="button" class="report-export-button subtle" @click="openReportModal">
                <Download :size="15" />
                <span>Exporter rapport</span>
              </button>
            </div>
          </div>
        </div>
      </div>

      <div class="card stack">
        <div v-if="canEditProject && showProjectEditForm" class="card stack project-edit-inline-card">
          <div class="section-header">
            <div>
              <h3>Modifier le projet</h3>
              <p class="muted">Mettez a jour les details du projet avant de continuer avec les user stories.</p>
            </div>
          </div>

          <p v-if="projectFormError" class="error">{{ projectFormError }}</p>

          <form class="project-edit-inline-form" @submit.prevent="submitProjectEdit">
            <label class="project-edit-field">
              <span>Nom du projet</span>
              <input v-model="projectEditForm.name" type="text" required />
            </label>

            <label class="project-edit-field">
              <span>URL de l application</span>
              <input v-model="projectEditForm.app_url" type="url" required />
            </label>

            <label class="project-edit-field project-edit-field-full">
              <span>Description</span>
              <textarea v-model="projectEditForm.description" rows="3"></textarea>
            </label>

            <label class="project-edit-field project-edit-field-full">
              <span>Objectifs de test</span>
              <textarea v-model="projectEditForm.test_objectives" rows="4"></textarea>
            </label>

            <div class="project-edit-field project-edit-field-full">
              <span class="project-edit-field-label">Testeurs assignes</span>
              <p v-if="loadingProjectEditMeta" class="muted">Chargement des testeurs...</p>
              <div v-else class="project-edit-testers">
                <label v-for="tester in availableTesters" :key="tester.id" class="project-edit-tester-option">
                  <input v-model="projectEditForm.tester_ids" type="checkbox" :value="Number(tester.id)" />
                  <div>
                    <strong>{{ tester.name }}</strong>
                    <span class="muted">{{ tester.email }}</span>
                  </div>
                </label>
              </div>
            </div>

            <div class="project-edit-actions">
              <button type="button" class="btn btn-secondary" @click="toggleProjectEditForm">
                Annuler
              </button>
              <button type="submit" class="btn btn-primary btn-inline-icon" :disabled="savingProject">
                <Save :size="16" />
                <span>{{ savingProject ? 'Enregistrement...' : 'Enregistrer les modifications' }}</span>
              </button>
            </div>
          </form>
        </div>

        <div class="section-header">
          <div>
            <h3>User stories</h3>
            <p class="muted">Review user stories linked to this project.</p>
          </div>
          <RouterLink
            v-if="auth.canManageStories"
            class="btn btn-primary btn-inline-icon"
            :to="{ name: 'story-create', query: { projectId: project.id } }"
          >
            <CirclePlus :size="16" />
            <span>Ajouter user story</span>
          </RouterLink>
        </div>

        <p v-if="actionError" class="error">{{ actionError }}</p>
        <p v-if="storiesStore.error" class="error">{{ storiesStore.error }}</p>
        <p v-else-if="storiesStore.loading" class="muted">Loading user stories...</p>

        <div v-else-if="storiesStore.stories.length > 0" class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Story</th>
                <th>Status</th>
                <th>Priority</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="story in storiesStore.stories" :key="story.id">
                <td>
                  <RouterLink
                    class="story-row-link"
                    :to="{ name: 'story-detail', params: { id: story.id }, query: { projectId: project.id } }"
                  >
                    <div class="stack stack-xs">
                      <strong>{{ story.title }}</strong>
                      <span class="muted">{{ story.description || 'No description' }}</span>
                    </div>
                  </RouterLink>
                </td>
                <td>{{ storyStatusLabels[story.status] || story.status }}</td>
                <td>{{ story.priority }}</td>
                <td>
                  <div v-if="auth.canManageStories" class="story-row-actions">
                    <button
                      type="button"
                      class="story-menu-trigger"
                      :aria-expanded="openStoryMenuId === story.id"
                      @click.stop="toggleStoryMenu(story.id)"
                    >
                      ...
                    </button>

                    <div v-if="openStoryMenuId === story.id" class="story-row-menu" @click.stop>
                      <RouterLink
                        class="story-row-menu-item"
                        :to="{ name: 'story-edit', params: { id: story.id }, query: { projectId: project.id } }"
                      >
                        Modifier
                      </RouterLink>
                      <button type="button" class="story-row-menu-item danger" @click="deleteStory(story)">
                        Supprimer
                      </button>
                    </div>
                  </div>
                  <span v-else class="muted">-</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <p v-else class="muted">No user stories yet. Start with stories to build your project backlog.</p>
      </div>
    </template>

    <div v-if="reportModalOpen" class="report-modal-overlay" @click.self="closeReportModal">
      <div class="card report-modal-card">
        <div class="report-modal-header">
          <div>
            <h3>Exporter le rapport projet</h3>
            <p class="muted report-modal-intro">Choisissez le format et les sections a inclure dans le rapport.</p>
          </div>
          <button type="button" class="report-modal-close" :disabled="exportLoading" @click="closeReportModal">
            <X :size="16" />
          </button>
        </div>

        <div class="report-modal-body">
          <div class="stack stack-xs report-modal-section">
            <div class="report-section-head">
              <span class="report-section-title">Format du fichier</span>
              <span class="report-section-caption">Choisissez la sortie adaptee a votre besoin</span>
            </div>
            <div class="report-format-grid">
              <button
                v-for="formatOption in reportFormats"
                :key="formatOption.value"
                type="button"
                class="report-format-card"
                :class="{
                  selected: reportOptions.format === formatOption.value,
                  disabled: formatOption.disabled,
                }"
                :disabled="formatOption.disabled"
                @click="selectReportFormat(formatOption.value)"
              >
                <div class="report-format-icon">
                  <component :is="formatOption.icon" :size="18" />
                </div>
                <div class="report-format-copy">
                  <div class="report-format-title-row">
                    <strong>{{ formatOption.label }}</strong>
                    <span v-if="formatOption.soon" class="report-soon-badge">BientÃ´t</span>
                  </div>
                  <span>{{ formatOption.description }}</span>
                </div>
                <CheckCircle2 v-if="reportOptions.format === formatOption.value" :size="16" class="report-format-check" />
              </button>
            </div>
          </div>

          <div class="stack stack-xs report-modal-section">
            <div class="report-section-head">
              <span class="report-section-title">Type de rapport</span>
              <span class="report-section-caption">Choix simple entre rÃ©sumÃ© global et vue complÃ¨te</span>
            </div>
            <div class="report-type-segmented">
              <button
                v-for="typeOption in reportTypes"
                :key="typeOption.value"
                type="button"
                class="report-type-option"
                :class="{
                  active: reportOptions.type === typeOption.value,
                  disabled: typeOption.disabled,
                }"
                :disabled="typeOption.disabled"
                @click="selectReportType(typeOption.value)"
              >
                <span>{{ typeOption.label }}</span>
                <small>{{ typeOption.description }}</small>
              </button>
            </div>
          </div>

          <div class="stack stack-xs report-modal-section">
            <div class="report-section-head">
              <span class="report-section-title">Contenu inclus</span>
              <span class="report-section-caption">Activez les sections utiles pour votre export</span>
            </div>
            <div class="report-content-groups">
              <div class="report-content-group">
                <span class="report-group-title">DonnÃ©es principales</span>
                <div class="report-options-list">
                  <label class="report-option-row">
                    <input v-model="reportOptions.include_user_stories" type="checkbox">
                    <span>User Stories</span>
                  </label>
                  <label class="report-option-row">
                    <input v-model="reportOptions.include_checklists" type="checkbox">
                    <span>Checklists</span>
                  </label>
                </div>
              </div>
              <div class="report-content-group">
                <span class="report-group-title">RÃ©sultats</span>
                <div class="report-options-list">
                  <label class="report-option-row">
                    <input v-model="reportOptions.include_execution_results" type="checkbox">
                    <span>RÃ©sultats d'exÃ©cution</span>
                  </label>
                  <label class="report-option-row">
                    <input v-model="reportOptions.include_failed_blocked" type="checkbox">
                    <span>Tests Ã©chouÃ©s / bloquÃ©s</span>
                  </label>
                </div>
              </div>
              <div class="report-content-group">
                <span class="report-group-title">TraÃ§abilitÃ©</span>
                <div class="report-options-list">
                  <label class="report-option-row">
                    <input v-model="reportOptions.include_comments" type="checkbox">
                    <span>Commentaires</span>
                  </label>
                  <label class="report-option-row">
                    <input v-model="reportOptions.include_history" type="checkbox">
                    <span>Historique</span>
                  </label>
                  <label class="report-option-row">
                    <input v-model="reportOptions.include_automation_traces" type="checkbox">
                    <span>Traces automatiques</span>
                  </label>
                </div>
              </div>
            </div>
          </div>

          <p v-if="reportModalError" class="report-modal-error">{{ reportModalError }}</p>
        </div>

        <div class="report-modal-footer">
          <div class="report-modal-summary">
            <span>{{ reportOptions.format.toUpperCase() }}</span>
            <span>Â·</span>
            <span>{{ reportTypeLabel }}</span>
            <span>Â·</span>
            <span>{{ enabledReportSectionsCount }} sections incluses</span>
          </div>
          <div class="report-modal-actions">
            <button type="button" class="ghost-button" :disabled="exportLoading" @click="closeReportModal">Annuler</button>
            <button type="button" class="report-export-button" :disabled="exportLoading" @click="exportProjectReport">
              <Download :size="16" />
              <span>{{ exportLoading ? 'GÃ©nÃ©ration...' : 'Exporter le rapport' }}</span>
            </button>
          </div>
        </div>
      </div>
    </div>
  </section>
</template>

<style>
.project-execution-hero {
  display: grid;
  grid-template-columns: minmax(0, 1.32fr) minmax(280px, 0.88fr);
  gap: 1.1rem;
  padding: 1.15rem 1.2rem;
  border-radius: 1.45rem;
  background:
    radial-gradient(circle at top right, rgba(14, 165, 233, 0.14), transparent 12rem),
    radial-gradient(circle at bottom left, rgba(16, 185, 129, 0.1), transparent 12rem),
    linear-gradient(180deg, rgba(255, 255, 255, 0.98), rgba(248, 250, 252, 0.98));
  border: 1px solid rgba(148, 163, 184, 0.2);
  box-shadow: 0 18px 36px -32px rgba(15, 23, 42, 0.38);
  align-items: stretch;
}

.project-execution-kicker {
  margin: 0;
  font-size: 0.69rem;
  font-weight: 800;
  letter-spacing: 0.16em;
  text-transform: uppercase;
  color: #0f766e;
}

.project-execution-copy-top {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 0.85rem;
}

.project-execution-copy h1 {
  margin: 0;
  font-size: clamp(1.7rem, 2.5vw, 2.3rem);
  line-height: 0.98;
  letter-spacing: -0.05em;
  max-width: 18ch;
}

.project-execution-subtitle {
  max-width: 48rem;
  margin: 0.55rem 0 0.9rem;
  font-size: 0.93rem;
  line-height: 1.55;
  color: #475569;
}

.project-meta-pills {
  display: flex;
  flex-wrap: wrap;
  gap: 0.55rem;
}

.project-context-app-card {
  margin-top: 1rem;
  max-width: 44rem;
}

.project-meta-pill {
  display: inline-flex;
  align-items: center;
  gap: 0.45rem;
  padding: 0.46rem 0.72rem;
  border-radius: 999px;
  background: rgba(255, 255, 255, 0.88);
  border: 1px solid rgba(148, 163, 184, 0.22);
  color: #0f172a;
  font-weight: 700;
  font-size: 0.83rem;
}

.project-execution-side {
  display: flex;
  min-width: 0;
  justify-content: flex-end;
  align-items: flex-start;
}

.project-execution-side-actions {
  width: 100%;
  display: flex;
  justify-content: flex-end;
  gap: 0.75rem;
}

.project-execution-app-card {
  width: 100%;
  padding: 0.92rem 1rem;
  border-radius: 1.2rem;
  background: rgba(255, 255, 255, 0.82);
  border: 1px solid rgba(148, 163, 184, 0.18);
}

.project-execution-app-card.compact {
  display: flex;
  flex-direction: column;
  gap: 0.55rem;
  justify-content: center;
}

.project-execution-side-label {
  display: block;
  margin-bottom: 0;
  font-size: 0.67rem;
  font-weight: 800;
  letter-spacing: 0.12em;
  text-transform: uppercase;
  color: #64748b;
}

.project-side-topline {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
}

.project-side-actions,
.project-side-actions {
  display: flex;
  align-items: center;
  justify-content: flex-end;
}

.project-side-copy {
  display: grid;
  gap: 0.28rem;
}

.project-side-copy p {
  margin: 0;
  font-size: 0.84rem;
  line-height: 1.45;
}

.project-app-link {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  font-weight: 700;
  font-size: 0.92rem;
  color: #0f172a;
  text-decoration: none;
  word-break: break-word;
}

.project-app-link:hover {
  color: #0f766e;
}

.report-export-button,
.ghost-button,
.report-modal-close {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 0.5rem;
  border-radius: 0.95rem;
  border: 1px solid rgba(15, 118, 110, 0.18);
  padding: 0.72rem 0.98rem;
  font-weight: 700;
  cursor: pointer;
  transition: transform 160ms ease, box-shadow 160ms ease, border-color 160ms ease, background 160ms ease;
}

.report-export-button {
  background: linear-gradient(135deg, #0f766e, #0f766e 42%, #0ea5e9);
  color: #fff;
  box-shadow: 0 18px 28px -24px rgba(15, 118, 110, 0.9);
}

.report-export-button:hover,
.ghost-button:hover,
.report-modal-close:hover {
  transform: translateY(-1px);
}

.report-export-button.subtle {
  padding: 0.58rem 0.8rem;
  border-radius: 0.82rem;
  background: rgba(15, 118, 110, 0.08);
  border-color: rgba(15, 118, 110, 0.18);
  box-shadow: none;
  color: #0f172a;
}

.report-export-button:disabled,
.ghost-button:disabled,
.report-modal-close:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

.ghost-button,
.report-modal-close {
  background: #fff;
  color: #0f172a;
  border-color: rgba(148, 163, 184, 0.26);
}

.report-modal-overlay {
  position: fixed;
  inset: 0;
  z-index: 70;
  display: grid;
  place-items: center;
  padding: 1rem;
  background: rgba(15, 23, 42, 0.58);
  backdrop-filter: blur(8px);
}

.report-modal-card {
  width: min(740px, 96vw);
  max-height: 88vh;
  display: flex;
  flex-direction: column;
  padding: 0;
  border-radius: 24px;
  overflow: hidden;
  background: #ffffff;
  box-shadow: 0 28px 64px -40px rgba(15, 23, 42, 0.52);
}

.report-modal-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 1rem;
  flex-shrink: 0;
  padding: 1.25rem 1.35rem 0.75rem;
  border-bottom: 1px solid rgba(226, 232, 240, 0.72);
}

.report-modal-header h3 {
  margin: 0;
  font-size: 1.25rem;
  font-weight: 800;
  color: #0f172a;
}

.report-modal-intro {
  margin: 0.32rem 0 0;
  max-width: 36rem;
  font-size: 0.88rem;
  color: #64748b;
}

.report-modal-section {
  gap: 0.75rem;
}

.report-modal-body {
  padding: 1rem 1.35rem;
  overflow-y: auto;
  display: grid;
  gap: 1.1rem;
}

.report-section-head {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 0.75rem;
  flex-wrap: wrap;
}

.report-section-caption {
  font-size: 0.8rem;
  color: #64748b;
}

.report-section-title {
  font-size: 0.85rem;
  font-weight: 700;
  color: #0f172a;
}

.report-format-grid {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 0.65rem;
}

.report-format-card {
  position: relative;
  display: grid;
  grid-template-columns: auto 1fr auto;
  align-items: start;
  gap: 0.7rem;
  width: 100%;
  min-height: 88px;
  padding: 0.82rem;
  border-radius: 1rem;
  border: 1px solid rgba(148, 163, 184, 0.2);
  background: #ffffff;
  text-align: left;
  color: #0f172a;
}

.report-format-card.selected {
  border-color: #14b8a6;
  box-shadow: 0 18px 28px -28px rgba(20, 184, 166, 0.7);
  background: #f0fdfa;
}

.report-format-card.disabled {
  opacity: 0.62;
  cursor: not-allowed;
}

.report-format-icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 2.3rem;
  height: 2.3rem;
  border-radius: 0.9rem;
  background: rgba(15, 23, 42, 0.06);
}

.report-format-copy {
  display: grid;
  gap: 0.22rem;
}

.report-format-copy span {
  font-size: 0.8rem;
  line-height: 1.4;
  color: #64748b;
}

.report-format-title-row {
  display: flex;
  align-items: center;
  gap: 0.45rem;
  flex-wrap: wrap;
}

.report-soon-badge {
  display: inline-flex;
  align-items: center;
  padding: 0.18rem 0.42rem;
  border-radius: 999px;
  background: rgba(148, 163, 184, 0.16);
  color: #475569;
  font-size: 0.68rem;
  font-weight: 700;
}

.report-format-check {
  color: #0f766e;
}

.report-type-segmented {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 0.7rem;
}

.report-type-option {
  display: grid;
  gap: 0.12rem;
  align-content: center;
  min-height: 76px;
  padding: 0.78rem 0.9rem;
  border-radius: 1rem;
  border: 1px solid rgba(148, 163, 184, 0.22);
  background: #f8fafc;
  text-align: center;
  color: #0f172a;
}

.report-type-option span {
  font-weight: 700;
}

.report-type-option small {
  color: #64748b;
  line-height: 1.4;
  font-size: 0.8rem;
}

.report-type-option.active {
  border-color: #14b8a6;
  background: #ecfdf5;
  box-shadow: inset 0 0 0 1px rgba(15, 118, 110, 0.15);
}

.report-type-option.disabled {
  opacity: 0.58;
  cursor: not-allowed;
}

.report-content-groups {
  display: grid;
  gap: 0.9rem;
}

.report-content-group {
  display: grid;
  gap: 0.45rem;
  padding: 0.82rem 0.9rem;
  border-radius: 1rem;
  border: 1px solid rgba(226, 232, 240, 0.95);
  background: #fcfcfd;
}

.report-group-title {
  font-size: 0.82rem;
  font-weight: 700;
  color: #64748b;
}

.report-options-list {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 0.45rem 0.9rem;
}

.report-option-row {
  display: flex;
  align-items: center;
  gap: 0.55rem;
  min-height: 34px;
  color: #0f172a;
  font-size: 0.9rem;
}

.report-option-row input {
  width: 1rem;
  height: 1rem;
  accent-color: #0f766e;
}

.report-modal-error {
  margin: 0;
  padding: 0.85rem 0.95rem;
  border-radius: 1rem;
  border: 1px solid rgba(248, 113, 113, 0.28);
  background: rgba(254, 242, 242, 0.96);
  color: #b91c1c;
  font-size: 0.9rem;
}

.report-modal-footer {
  flex-shrink: 0;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
  padding: 1rem 1.35rem;
  border-top: 1px solid rgba(226, 232, 240, 0.9);
  background: rgba(255, 255, 255, 0.96);
}

.report-modal-summary {
  display: flex;
  align-items: center;
  gap: 0.42rem;
  flex-wrap: wrap;
  font-size: 0.86rem;
  font-weight: 700;
  color: #334155;
}

.report-modal-actions {
  display: flex;
  justify-content: flex-end;
  gap: 0.8rem;
  flex-shrink: 0;
}

.report-modal-actions .report-export-button {
  border: 0;
  padding: 0.8rem 1.1rem;
  font-weight: 800;
}

.project-edit-inline-card {
  margin-bottom: 1rem;
  border: 1px solid rgba(148, 163, 184, 0.18);
  background: linear-gradient(180deg, rgba(255, 255, 255, 0.98), rgba(248, 250, 252, 0.98));
}

.project-edit-inline-form {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 1rem;
}

.project-edit-field {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}

.project-edit-field-full {
  grid-column: 1 / -1;
}

.project-edit-field span,
.project-edit-field-label {
  font-size: 0.92rem;
  font-weight: 700;
  color: #0f172a;
}

.project-edit-field input,
.project-edit-field textarea {
  width: 100%;
  border: 1px solid rgba(148, 163, 184, 0.3);
  border-radius: 0.9rem;
  background: #fff;
  color: #0f172a;
  padding: 0.85rem 0.95rem;
  outline: none;
  transition: border-color 0.18s ease, box-shadow 0.18s ease;
}

.project-edit-field textarea {
  resize: vertical;
}

.project-edit-field input:focus,
.project-edit-field textarea:focus {
  border-color: rgba(14, 165, 233, 0.9);
  box-shadow: 0 0 0 4px rgba(14, 165, 233, 0.12);
}

.project-edit-testers {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 0.75rem;
}

.project-edit-tester-option {
  display: flex;
  gap: 0.75rem;
  align-items: flex-start;
  padding: 0.85rem 0.95rem;
  border-radius: 1rem;
  border: 1px solid rgba(148, 163, 184, 0.22);
  background: rgba(255, 255, 255, 0.94);
}

.project-edit-tester-option input {
  margin-top: 0.2rem;
}

.project-edit-tester-option strong {
  display: block;
  color: #0f172a;
}

.project-edit-actions {
  grid-column: 1 / -1;
  display: flex;
  justify-content: flex-end;
  gap: 0.8rem;
  padding-top: 0.25rem;
}

.story-row-link {
  color: inherit;
  text-decoration: none;
}

.story-row-link strong {
  color: #0f172a;
}

.story-row-link:hover strong {
  color: #0f766e;
}

.story-row-actions {
  position: relative;
}

.story-menu-trigger {
  width: 2.2rem;
  height: 2.2rem;
  border-radius: 0.8rem;
  border: 1px solid rgba(148, 163, 184, 0.24);
  background: #fff;
  color: #334155;
}

.story-row-menu {
  position: absolute;
  top: calc(100% + 0.35rem);
  right: 0;
  width: 11rem;
  z-index: 30;
  border-radius: 0.9rem;
  border: 1px solid rgba(148, 163, 184, 0.26);
  background: #fff;
  box-shadow: 0 22px 38px -28px rgba(15, 23, 42, 0.5);
  overflow: hidden;
}

.story-row-menu-item {
  width: 100%;
  display: block;
  text-align: left;
  border: 0;
  background: transparent;
  padding: 0.7rem 0.85rem;
  color: #0f172a;
  text-decoration: none;
  cursor: pointer;
}

.story-row-menu-item:hover {
  background: #f8fafc;
}

.story-row-menu-item.danger {
  color: #dc2626;
}


.dark .project-execution-subtitle,
.dark .project-execution-side-label,
.dark .report-section-caption,
.dark .report-group-title,
.dark .project-side-copy p,
.dark .project-edit-field .muted,
.dark .report-type-option small,
.dark .report-format-copy span,
.dark .report-modal-summary {
  color: #94a3b8;
}

.dark .report-section-title,
.dark .report-modal-header h3 {
  color: #f8fafc;
}

.dark .project-meta-pill,
.dark .project-execution-app-card,
.dark .project-edit-inline-card,
.dark .ghost-button,
.dark .report-format-card,
.dark .report-type-option,
.dark .report-modal-close,
.dark .report-modal-card,
.dark .story-menu-trigger,
.dark .story-row-menu {
  background: rgba(15, 23, 42, 0.9);
  border-color: rgba(51, 65, 85, 0.9);
  color: #e2e8f0;
}

.dark .project-app-link,
.dark .project-edit-field span,
.dark .project-edit-field-label,
.dark .story-row-link {
  color: #f8fafc;
}

.dark .project-edit-field input,
.dark .project-edit-field textarea,
.dark .project-edit-tester-option {
  background: rgba(15, 23, 42, 0.88);
  border-color: rgba(51, 65, 85, 0.9);
  color: #e2e8f0;
}

.dark .report-export-button.subtle {
  background: rgba(14, 116, 144, 0.16);
  color: #e2e8f0;
}

.dark .story-row-link:hover strong {
  color: #bae6fd;
}

.dark .story-row-menu-item {
  color: #e2e8f0;
}

.dark .story-row-menu-item:hover {
  background: rgba(30, 41, 59, 0.9);
}

.dark .report-format-card.selected,
.dark .report-type-option.active {
  border-color: rgba(20, 184, 166, 0.45);
  background: rgba(15, 23, 42, 0.98);
}

.dark .report-format-icon {
  background: rgba(148, 163, 184, 0.12);
}

.dark .report-type-icon {
  background: rgba(148, 163, 184, 0.12);
}

.dark .report-format-check {
  color: #2dd4bf;
}

.dark .report-soon-badge {
  background: rgba(148, 163, 184, 0.18);
  color: #cbd5e1;
}

.dark .report-modal-error {
  background: rgba(127, 29, 29, 0.22);
  border-color: rgba(248, 113, 113, 0.2);
  color: #fecaca;
}

.dark .report-content-group {
  background: rgba(15, 23, 42, 0.72);
  border-color: rgba(51, 65, 85, 0.9);
}

.dark .report-modal-header,
.dark .report-modal-footer {
  border-color: rgba(51, 65, 85, 0.85);
  background: rgba(15, 23, 42, 0.94);
}

.dark .story-row-menu-item.danger {
  color: #fca5a5;
}

@media (max-width: 960px) {
  .project-execution-hero {
    grid-template-columns: 1fr;
  }

  .project-execution-copy-top,
  .project-side-topline,
  .report-modal-footer {
    align-items: flex-start;
    flex-direction: column;
  }

  .project-side-actions {
    width: 100%;
    justify-content: flex-start;
  }

  .project-context-app-card {
    max-width: 100%;
  }

  .project-edit-inline-form,
  .project-edit-testers {
    grid-template-columns: 1fr;
  }

  .project-edit-actions {
    flex-direction: column-reverse;
  }

  .project-edit-actions .btn {
    width: 100%;
  }

  .report-format-grid,
  .report-type-segmented,
  .report-options-list {
    grid-template-columns: 1fr;
  }

  .report-modal-actions {
    width: 100%;
    flex-direction: column-reverse;
  }

  .report-modal-actions .report-export-button,
  .report-modal-actions .ghost-button {
    width: 100%;
  }

  .report-modal-card {
    width: min(100%, 96vw);
    max-height: 90vh;
    overflow-y: auto;
  }
}
</style>
