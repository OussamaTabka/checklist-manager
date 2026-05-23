<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { useUserStoriesStore } from '@/stores/userStories'
import { useAuthStore } from '@/stores/auth'
import { useSettingsStore } from '@/stores/settings'
import { useToastStore } from '@/stores/toast'
import { apiRequest } from '@/lib/api'
import { localizeError, localizeMessage, tr } from '@/lib/localization'
import { AlertCircle, ArrowLeft, Ellipsis, FileText, FlaskConical, Link, Plus, Sparkles, Trash2, WandSparkles, Zap } from 'lucide-vue-next'
import StoryTabs from '@/components/story/StoryTabs.vue'
import SuggestionCard from '@/components/story/SuggestionCard.vue'
import ChecklistAccordion from '@/components/story/ChecklistAccordion.vue'
import WorkflowStepper from '@/components/story/WorkflowStepper.vue'

const route = useRoute()
const router = useRouter()
const storiesStore = useUserStoriesStore()
const auth = useAuthStore()
const settingsStore = useSettingsStore()
const toast = useToastStore()
const projectSummary = ref(null)

const projectId = computed(() => route.query.projectId || null)
const storyId = route.params.id
const currentLanguage = computed(() => settingsStore.language || 'fr')

const statusLabels = {
  backlog: 'Backlog',
  in_progress: 'En cours',
  ready_for_test: 'Prêt pour test',
  completed: 'Terminée',
}

const priorityColors = {
  critical: 'text-red-600 bg-red-50 border-red-200',
  high: 'text-orange-600 bg-orange-50 border-orange-200',
  medium: 'text-yellow-700 bg-yellow-50 border-yellow-200',
  low: 'text-green-600 bg-green-50 border-green-200',
}

const criticalities = {
  Critical: 'bg-red-100 text-red-800',
  High: 'bg-orange-100 text-orange-800',
  Medium: 'bg-yellow-100 text-yellow-800',
  Low: 'bg-green-100 text-green-800',
  Major: 'bg-orange-100 text-orange-800',
  Minor: 'bg-slate-100 text-slate-800',
}

const currentSuggestions = computed(() => storiesStore.suggestionsByStoryId?.[storyId] || null)
const currentAgentResult = computed(() => storiesStore.agentResultsByStoryId?.[storyId] || null)
const currentPreview = computed(() => storiesStore.previewByStoryId?.[storyId] || null)
const pendingDrafts = computed(() => storiesStore.currentStory?.pending_drafts || [])
const attachedChecklists = computed(() => storiesStore.currentStory?.checklists || [])
const attachedChecklistCount = computed(() => attachedChecklists.value.length)
const attachedChecklistIds = computed(() => new Set(attachedChecklists.value.map((checklist) => Number(checklist.id))))
const attachedChecklistLookup = computed(() => {
  const ids = new Set()
  const names = new Set()

  for (const checklist of attachedChecklists.value) {
    for (const candidate of [checklist?.id, checklist?.checklist_id, checklist?.source_checklist_id]) {
      if (candidate !== null && candidate !== undefined && candidate !== '') {
        ids.add(String(candidate))
      }
    }

    const normalizedName = normalizeChecklistName(checklist?.name || checklist?.title)
    if (normalizedName) {
      names.add(normalizedName)
    }
  }

  return { ids, names }
})
const visibleSuggestions = computed(() => {
  const suggestions = Array.isArray(currentSuggestions.value?.suggestions)
    ? currentSuggestions.value.suggestions
    : []
  const dedupedSuggestions = new Map()

  for (const suggestion of suggestions) {
    const suggestionId = suggestionIdentifier(suggestion)
    const normalizedName = suggestionNameKey(suggestion)

    if (
      (suggestionId && attachedChecklistLookup.value.ids.has(String(suggestionId))) ||
      (normalizedName && attachedChecklistLookup.value.names.has(normalizedName))
    ) {
      continue
    }

    const uniqueKey = suggestionId ? `id:${suggestionId}` : normalizedName ? `name:${normalizedName}` : null
    if (!uniqueKey) {
      continue
    }

    const existing = dedupedSuggestions.get(uniqueKey)
    if (!existing || scoreValue(suggestion) > scoreValue(existing)) {
      dedupedSuggestions.set(uniqueKey, suggestion)
    }
  }

  return Array.from(dedupedSuggestions.values())
})
const suggestedChecklistCount = computed(() => visibleSuggestions.value.length || 0)
const isAdminReadonly = computed(() => auth.isSystemAdmin && !auth.isProjectManager && !auth.isTester)
const storyStatusLabel = computed(() => statusLabels[storiesStore.currentStory?.status] || storiesStore.currentStory?.status || '-')
const storyPriorityLabel = computed(() => {
  const priority = storiesStore.currentStory?.priority || ''
  return priority ? priority.charAt(0).toUpperCase() + priority.slice(1) : '-'
})
const acceptanceCriteriaText = computed(() => storiesStore.currentStory?.acceptance_criteria || 'No acceptance criteria provided.')
const attachableChecklists = computed(() =>
  availableChecklists.value.filter((checklist) => {
    const checklistId = Number(checklist.id)
    const lifecycleStatus = String(checklist.lifecycle_status || '').toLowerCase()

    return (
      checklist.is_active &&
      lifecycleStatus !== 'archived' &&
      !attachedChecklistIds.value.has(checklistId)
    )
  }),
)
const attachChecklistPageSize = 5
const attachChecklistTotalPages = computed(() => Math.max(1, Math.ceil(attachableChecklists.value.length / attachChecklistPageSize)))
const paginatedAttachableChecklists = computed(() => {
  const start = (attachChecklistPage.value - 1) * attachChecklistPageSize
  return attachableChecklists.value.slice(start, start + attachChecklistPageSize)
})
const selectedExistingChecklists = computed(() =>
  attachableChecklists.value.filter((checklist) => selectedExistingChecklistIds.value.includes(Number(checklist.id))),
)
const businessRules = computed(() => Array.isArray(storiesStore.currentStory?.business_rules) ? storiesStore.currentStory.business_rules : [])
const businessScenarios = computed(() => Array.isArray(storiesStore.currentStory?.scenarios) ? storiesStore.currentStory.scenarios : [])
const projectLabel = computed(() => projectSummary.value?.name || `Projet #${projectId.value || '-'}`)
const hasSuggestions = computed(() => visibleSuggestions.value.length > 0)
const hasDrafts = computed(() => pendingDrafts.value.length > 0)
const hasAttachedChecklists = computed(() => attachedChecklists.value.length > 0)
const activeStoryTab = ref('description')
const openHeaderActions = ref(false)
const previewIntent = ref('details')
const prepareSection = ref(null)
const suggestionsSection = ref(null)
const workflowSteps = [
  { id: 1, label: 'Analyser' },
  { id: 2, label: 'Adapter checklist' },
  { id: 3, label: 'Associer' },
  { id: 4, label: 'Executer' },
]
const workflowCurrentStep = computed(() => {
  if (hasAttachedChecklists.value) return 4
  if (hasDrafts.value) return 3
  if (hasSuggestions.value || currentAgentResult.value) return 2
  return 1
})
const summaryChips = computed(() => [
  { label: 'Statut', value: storyStatusLabel.value, tone: 'neutral' },
  { label: 'Priorite', value: storyPriorityLabel.value, tone: 'warning' },
  { label: 'Suggestions', value: String(suggestedChecklistCount.value), tone: 'info' },
  { label: 'Checklists associees', value: String(attachedChecklistCount.value), tone: 'success' },
])
const storyTabs = computed(() => [
  {
    id: 'description',
    label: 'Description',
    content: storiesStore.currentStory?.description || 'Aucune description disponible.',
  },
  {
    id: 'criteria',
    label: 'Criteres d acceptation',
    content: acceptanceCriteriaText.value || 'Aucun critere d acceptation disponible.',
  },
  {
    id: 'rules',
    label: 'Regles metier',
    items: businessRules.value,
    badge: businessRules.value.length,
    emptyText: 'Aucune regle metier renseignee.',
  },
  {
    id: 'scenarios',
    label: 'Scenarios metier',
    items: businessScenarios.value,
    badge: businessScenarios.value.length,
    emptyText: 'Aucun scenario metier renseigne.',
  },
])
const primaryAction = computed(() => {
  if (hasAttachedChecklists.value) {
    return { key: 'execution', label: 'Ouvrir l espace d execution' }
  }
  if (hasDrafts.value) {
    return { key: 'drafts', label: 'Continuer la validation' }
  }
  return { key: 'prepare', label: 'Preparer une checklist' }
})
const agentStatus = computed(() => {
  if (storiesStore.isGenerating) {
    return {
      label: 'Generation...',
      description: 'Le système génére une proposition de checklist. Veuillez patienter.',
      tone: 'info',
    }
  }

  if (storiesStore.error) {
    return {
      label: 'Erreur',
      description: storiesStore.error,
      tone: 'danger',
    }
  }

  if (currentAgentResult.value?.checklist || currentAgentResult.value?.reuse_summary) {
    return {
      label: 'Termine',
      description: 'Une proposition de checklist est disponible.',
      tone: 'success',
    }
  }

  return {
    label: 'Pret',
    description: 'Choisissez une methode pour preparer la checklist.',
    tone: 'neutral',
  }
})

const suggestionStatus = computed(() => {
  if (loadingSuggestions.value) {
    return {
      title: 'Recherche...',
      description: 'Le systeme recherche les checklists les plus proches de cette User Story.',
    }
  }

  return {
    title: 'Checklists suggerées',
    description: 'Checklists existantes proches de cette User Story.',
  }
})
const interactionLockState = computed(() => {
  if (storiesStore.isGenerating) {
    return {
      active: true,
      title: 'Generation de checklist en cours',
      description: 'Veuillez patienter pendant que le systeme prepare la checklist.',
    }
  }

  if (lockingSuggestionRefresh.value) {
    return {
      active: true,
      title: 'Recherche des recommandations en cours',
      description: 'Le systeme analyse les checklists existantes pour trouver les meilleures correspondances.',
    }
  }

  if (previewLoading.value) {
    return {
      active: true,
      title: 'Ouverture de la recommandation',
      description: 'Les details de la checklist suggeree sont en cours de chargement.',
    }
  }

  return {
    active: false,
    title: '',
    description: '',
  }
})
const isInteractionLocked = computed(() => interactionLockState.value.active)

const previewOpen = ref(false)
const previewLoading = ref(false)
const adapting = ref(false)
const attaching = ref(false)
const loadingAvailableChecklists = ref(false)
const loadingAvailableItems = ref(false)
const loadingSuggestions = ref(false)
const lockingSuggestionRefresh = ref(false)
const attachingExistingChecklist = ref(false)
const approvingDraftId = ref(null)
const collapsedDrafts = ref({})
const previewMode = ref('suggestion')
const previewChecklistId = ref(null)
const availableChecklists = ref([])
const availableItems = ref([])
const showAttachExistingPanel = ref(false)
const selectedExistingChecklistIds = ref([])
const selectedExistingItemId = ref('')
const attachChecklistPage = ref(1)
const attachedSection = ref(null)
const highlightedChecklistId = ref(null)
const openDraftMenuId = ref(null)
const rejectingDraftId = ref(null)
let highlightTimeoutId = null
let scrollTimeoutId = null
const previewForm = ref({
  name: '',
  description: '',
  category: '',
  items: [],
})
const addableExistingItems = computed(() => {
  const usedSignatures = new Set(
    previewForm.value.items.map((item) =>
      `${String(item.title || '').trim()}|${String(item.description || '').trim()}|${item.priority || ''}|${item.criticality || ''}`,
    ),
  )

  return availableItems.value.filter((item) => {
    const signature =
      `${String(item.title || '').trim()}|${String(item.description || '').trim()}|${item.priority || ''}|${item.criticality || ''}`
    return !usedSignatures.has(signature)
  })
})

async function loadStory() {
  if (!projectId.value) {
    return
  }

  await Promise.all([
    storiesStore.fetchStory(projectId.value, storyId),
    auth.isTester ? storiesStore.fetchGeneratorStatus(projectId.value) : Promise.resolve(),
    auth.isTester ? refreshSuggestedChecklists() : Promise.resolve(),
    loadProjectSummary(),
    auth.isTester ? loadAvailableChecklists() : Promise.resolve(),
  ])
}

async function refreshSuggestedChecklists(options = {}) {
  const { notifyIfEmpty = false, lockUi = false } = options

  if (!projectId.value) {
    return
  }

  try {
    lockingSuggestionRefresh.value = lockUi
    loadingSuggestions.value = true
    await storiesStore.fetchChecklistSuggestions(projectId.value, storyId)
    if (notifyIfEmpty && visibleSuggestions.value.length === 0) {
      toast.info('Aucune checklist correspondante n a ete trouvee pour cette user story.')
    }
  } finally {
    loadingSuggestions.value = false
    lockingSuggestionRefresh.value = false
  }
}

async function loadAvailableChecklists() {
  if (!projectId.value || !auth.isTester) {
    availableChecklists.value = []
    return
  }

  try {
    loadingAvailableChecklists.value = true
    const data = await apiRequest(`/checklists?project_id=${projectId.value}&per_page=100`, {}, auth.token)
    availableChecklists.value = Array.isArray(data?.data) ? data.data : []
  } catch {
    availableChecklists.value = []
  } finally {
    loadingAvailableChecklists.value = false
    goToAttachChecklistPage(attachChecklistPage.value)
  }
}

async function loadAvailableItems() {
  if (loadingAvailableItems.value) {
    return
  }

  try {
    loadingAvailableItems.value = true
    const data = await apiRequest('/checklists/items/available', {}, auth.token)
    availableItems.value = Array.isArray(data) ? data : []
  } catch {
    availableItems.value = []
  } finally {
    loadingAvailableItems.value = false
  }
}

async function loadProjectSummary() {
  if (!projectId.value) {
    projectSummary.value = null
    return
  }

  try {
    projectSummary.value = await apiRequest(`/projects/${projectId.value}`, {}, auth.token)
  } catch {
    projectSummary.value = { id: projectId.value, name: `Projet #${projectId.value}` }
  }
}

function scrollToSection(target) {
  target?.value?.scrollIntoView({ behavior: 'smooth', block: 'start' })
}

async function generateChecklist() {
  if (!confirm(localizeMessage('Launch the checklist agent? It will reuse approved checklists first, then generate missing coverage.', currentLanguage.value))) {
    return
  }
  if (!projectId.value) {
    return
  }

  try {
    await storiesStore.generateChecklistWithAgent(projectId.value, storyId)
    await loadStory()
  } catch (err) {
    alert(`${tr('error_prefix', {}, currentLanguage.value)}: ${localizeError(err, 'error_generic', currentLanguage.value)}`)
  }
}

async function detachChecklist(checklistId) {
  if (!confirm(localizeMessage('Detach this checklist?', currentLanguage.value))) {
    return
  }
  if (!projectId.value) {
    return
  }

  try {
    await storiesStore.detachChecklist(projectId.value, storyId, checklistId)
    await refreshSuggestedChecklists()
    toast.success(localizeMessage('Checklist detachee de la User Story.', currentLanguage.value))
  } catch (err) {
    alert(`${tr('error_prefix', {}, currentLanguage.value)}: ${localizeError(err, 'error_generic', currentLanguage.value)}`)
  }
}

function toggleAttachExistingPanel() {
  showAttachExistingPanel.value = !showAttachExistingPanel.value

  if (!showAttachExistingPanel.value) {
    selectedExistingChecklistIds.value = []
    attachChecklistPage.value = 1
  }
}

function goToAttachChecklistPage(page) {
  attachChecklistPage.value = Math.min(Math.max(1, page), attachChecklistTotalPages.value)
}

async function attachExistingChecklist() {
  if (!projectId.value || selectedExistingChecklistIds.value.length === 0) {
    return
  }

  try {
    attachingExistingChecklist.value = true
    const checklistIds = [...selectedExistingChecklistIds.value]

    await Promise.all(
      checklistIds.map((checklistId) => storiesStore.attachChecklist(projectId.value, storyId, checklistId)),
    )
    await Promise.all([
      refreshSuggestedChecklists(),
      loadAvailableChecklists(),
    ])

    toast.success(
      checklistIds.length > 1
        ? localizeMessage('Checklists existantes associees a la User Story.', currentLanguage.value)
        : localizeMessage('Checklist existante associee a la User Story.', currentLanguage.value),
    )
    showAttachExistingPanel.value = false
    selectedExistingChecklistIds.value = []

    await nextTick()

    highlightedChecklistId.value = checklistIds[0]

    if (scrollTimeoutId) {
      clearTimeout(scrollTimeoutId)
    }
    scrollTimeoutId = setTimeout(() => {
      attachedSection.value?.scrollIntoView({ behavior: 'smooth', block: 'start' })
    }, 250)

    if (highlightTimeoutId) {
      clearTimeout(highlightTimeoutId)
    }
    highlightTimeoutId = setTimeout(() => {
      highlightedChecklistId.value = null
    }, 2000)
  } catch (err) {
    toast.error(localizeError(err, 'error_generic', currentLanguage.value))
  } finally {
    attachingExistingChecklist.value = false
  }
}

async function viewSuggestedChecklist(checklistId) {
  if (!projectId.value) {
    return
  }

  try {
    previewLoading.value = true
    await loadAvailableItems()
    const preview = await storiesStore.previewSuggestedChecklist(projectId.value, storyId, checklistId)
    previewForm.value = {
      name: preview?.checklist?.title || '',
      description: preview?.checklist?.description || '',
      category: preview?.checklist?.category || '',
      items: Array.isArray(preview?.checklist?.items)
        ? preview.checklist.items.map((item) => ({
            ...item,
            status: String(item.status || 'Pending').toLowerCase(),
          }))
        : [],
    }
    selectedExistingItemId.value = ''
    previewMode.value = 'suggestion'
    previewChecklistId.value = checklistId
    previewOpen.value = true
  } catch (err) {
    alert(`${tr('error_prefix', {}, currentLanguage.value)}: ${localizeError(err, 'error_generic', currentLanguage.value)}`)
  } finally {
    previewLoading.value = false
  }
}

function removePendingDraftFromView(checklistId) {
  if (!storiesStore.currentStory?.pending_drafts) {
    return
  }

  storiesStore.currentStory = {
    ...storiesStore.currentStory,
    pending_drafts: storiesStore.currentStory.pending_drafts.filter(
      (draft) => Number(draft.id) !== Number(checklistId),
    ),
  }
}

async function approveGeneratedChecklist(checklistId) {
  if (!projectId.value) {
    return
  }
  try {
    approvingDraftId.value = checklistId
    openDraftMenuId.value = null
    const response = await storiesStore.approveGeneratedChecklist(projectId.value, storyId, checklistId)
    const approvedChecklistId = response?.approved_checklist?.id || checklistId
    removePendingDraftFromView(checklistId)
    await loadStory()
    toast.success(localizeMessage('Checklist approuvee et associee a la User Story.', currentLanguage.value))
    await nextTick()
    highlightedChecklistId.value = approvedChecklistId
    if (scrollTimeoutId) {
      clearTimeout(scrollTimeoutId)
    }
    scrollTimeoutId = setTimeout(() => {
      attachedSection.value?.scrollIntoView({ behavior: 'smooth', block: 'start' })
    }, 250)
    if (highlightTimeoutId) {
      clearTimeout(highlightTimeoutId)
    }
    highlightTimeoutId = setTimeout(() => {
      highlightedChecklistId.value = null
    }, 2000)
  } catch (err) {
    toast.error(localizeError(err, 'error_generic', currentLanguage.value))
  } finally {
    approvingDraftId.value = null
  }
}

async function rejectPendingDraft(checklistId) {
  if (!projectId.value) {
    return
  }

  if (!confirm(localizeMessage('Reject this generated checklist draft?', currentLanguage.value))) {
    return
  }

  try {
    rejectingDraftId.value = checklistId
    openDraftMenuId.value = null
    await storiesStore.rejectGeneratedChecklist(projectId.value, storyId, checklistId)
    removePendingDraftFromView(checklistId)
    await loadStory()
    toast.success(localizeMessage('Checklist brouillon rejetee.', currentLanguage.value))
  } catch (err) {
    toast.error(localizeError(err, 'error_generic', currentLanguage.value))
  } finally {
    rejectingDraftId.value = null
  }
}

function removeSuggestedChecklist(checklistId) {
  const current = storiesStore.suggestionsByStoryId?.[storyId]
  if (!current?.suggestions) {
    return
  }

  storiesStore.suggestionsByStoryId = {
    ...storiesStore.suggestionsByStoryId,
    [storyId]: {
      ...current,
      suggestions: current.suggestions.filter(
        (suggestion) => String(suggestion.source_checklist_id || suggestion.id) !== String(checklistId),
      ),
    },
  }
}

function openManualDraft() {
  previewIntent.value = 'manual'
  previewMode.value = 'manual'
  previewChecklistId.value = null
  loadAvailableItems()
  previewForm.value = {
    name: `Manual Draft: ${storiesStore.currentStory?.title || 'Checklist'}`,
    description: '',
    category: 'manual-story-draft',
    items: [
      {
        id: 'TC-001',
        title: '',
        description: '',
        priority: 'Medium',
        criticality: 'Major',
        status: 'pending',
      },
    ],
  }
  selectedExistingItemId.value = ''
  previewOpen.value = true
}

function editPendingDraft(draft) {
  previewIntent.value = 'draft'
  previewMode.value = 'draft'
  previewChecklistId.value = draft.id
  loadAvailableItems()
  previewForm.value = {
    name: draft.name || '',
    description: draft.description || '',
    category: draft.category || '',
    items: Array.isArray(draft.items)
      ? draft.items.map((item) => ({
          ...item,
          status: String(item.status || 'pending').toLowerCase(),
        }))
      : [],
  }
  selectedExistingItemId.value = ''
  previewOpen.value = true
}

function closePreview() {
  if (previewLoading.value || adapting.value || attaching.value) {
    return
  }
  previewOpen.value = false
}

function toggleDraftCollapse(draftId) {
  collapsedDrafts.value = {
    ...collapsedDrafts.value,
    [draftId]: !collapsedDrafts.value[draftId],
  }
}

function isDraftCollapsed(draftId) {
  return Boolean(collapsedDrafts.value[draftId])
}

function toggleDraftMenu(draftId) {
  openDraftMenuId.value = openDraftMenuId.value === draftId ? null : draftId
}

function executionSpaceRoute(checklistId = null) {
  if (!projectId.value) {
    return { name: 'project-detail' }
  }

  if (checklistId) {
    return {
      name: 'checklist-detail',
      params: { id: checklistId },
      query: { projectId: projectId.value },
    }
  }

  const firstChecklistId = attachedChecklists.value[0]?.id
  if (firstChecklistId) {
    return {
      name: 'checklist-detail',
      params: { id: firstChecklistId },
      query: { projectId: projectId.value },
    }
  }

  return { name: 'project-detail', params: { id: projectId.value } }
}

function checklistEditRoute(checklistId) {
  return {
    name: 'checklist-edit',
    params: { id: checklistId },
    query: {
      returnTo: 'story-detail',
      storyId,
      ...(projectId.value ? { projectId: projectId.value } : {}),
    },
  }
}

function toggleHeaderActions() {
  openHeaderActions.value = !openHeaderActions.value
}

function handlePrimaryAction() {
  if (primaryAction.value.key === 'execution') {
    router.push(executionSpaceRoute(attachedChecklists.value[0]?.id))
    return
  }

  if (primaryAction.value.key === 'drafts') {
    scrollToSection(prepareSection)
    return
  }

  scrollToSection(prepareSection)
}

async function openSuggestionPreview(checklistId, intent = 'details') {
  previewIntent.value = intent
  await viewSuggestedChecklist(checklistId)
}

function normalizeChecklistName(value) {
  return String(value || '')
    .trim()
    .toLowerCase()
    .replace(/\s+/g, ' ')
}

function suggestionIdentifier(suggestion) {
  const candidates = [suggestion?.checklist_id, suggestion?.source_checklist_id, suggestion?.id]

  for (const candidate of candidates) {
    if (candidate !== null && candidate !== undefined && candidate !== '') {
      return String(candidate)
    }
  }

  return ''
}

function suggestionNameKey(suggestion) {
  return normalizeChecklistName(
    suggestion?.title ||
    suggestion?.name ||
    suggestion?.checklist?.title ||
    suggestion?.checklist?.name,
  )
}

function suggestionRenderKey(suggestion) {
  return suggestionIdentifier(suggestion) || suggestionNameKey(suggestion)
}

function localizeGeneratedText(text) {
  return String(text || '')
    .replace(/Ã©/g, 'é')
    .replace(/Ã¨/g, 'è')
    .replace(/Ãª/g, 'ê')
    .replace(/Ã /g, 'à')
    .replace(/Ã¢/g, 'â')
    .replace(/Ã®/g, 'î')
    .replace(/Ã´/g, 'ô')
    .replace(/Ã¹/g, 'ù')
    .replace(/Ã»/g, 'û')
    .replace(/Ã§/g, 'ç')
    .replace(/â€™/g, '’')
    .replace(/Â/g, '')
}

function provenanceLabel(item) {
  if (item?.source_type === 'reused') {
    return currentLanguage.value === 'en' ? 'Reused' : 'Réutilisé'
  }

  if (item?.source_type === 'generated') {
    return currentLanguage.value === 'en' ? 'Generated' : 'Généré'
  }

  return currentLanguage.value === 'en' ? 'Custom' : 'Personnalisé'
}

function provenanceClass(item) {
  if (item?.source_type === 'reused') {
    return 'story-source-badge-reused'
  }

  if (item?.source_type === 'generated') {
    return 'story-source-badge-generated'
  }

  return 'story-source-badge-custom'
}

function provenanceSourceText(item) {
  if (item?.source_type !== 'reused' || !item?.source_checklist_name) {
    return ''
  }

  return currentLanguage.value === 'en'
    ? `Reused from ${item.source_checklist_name}`
    : `Réutilisé depuis ${item.source_checklist_name}`
}

function scoreValue(suggestion) {
  return Number(suggestion?.score || 0)
}

function scoreLabel(suggestion) {
  return `${scoreValue(suggestion)} / 100`
}

function confidenceLabel(suggestion) {
  const score = scoreValue(suggestion)
  if (score >= 85) return 'Correspondance elevee'
  if (score >= 60) return 'Correspondance moyenne'
  return 'Correspondance faible'
}

function recommendationLabel(recommendation) {
  const labels = {
    reuse: 'Réutilisation recommandée',
    review: 'Relecture recommandée',
    adapt: 'Adaptation recommandée',
    create_draft: 'Création de brouillon recommandée',
    create_new_draft: 'Création de brouillon recommandée',
    generate_new: 'Génération recommandée',
    generate_new_draft: 'Génération recommandée',
  }

  const normalized = String(recommendation || '').trim().toLowerCase()
  return labels[normalized] || 'Préparation recommandée'
}

function coveredCount(suggestion) {
  return Array.isArray(suggestion?.coverage) ? suggestion.coverage.length : 0
}

function missingCount(suggestion) {
  return Array.isArray(suggestion?.missing) ? suggestion.missing.length : 0
}

function suggestionItemCount(suggestion) {
  if (typeof suggestion?.items_count === 'number') return suggestion.items_count
  if (typeof suggestion?.test_cases_count === 'number') return suggestion.test_cases_count
  if (typeof suggestion?.test_case_count === 'number') return suggestion.test_case_count
  if (typeof suggestion?.checklist_items_count === 'number') return suggestion.checklist_items_count
  if (Array.isArray(suggestion?.items)) return suggestion.items.length
  if (Array.isArray(suggestion?.checklist?.items)) return suggestion.checklist.items.length
  if (coveredCount(suggestion) > 0) return coveredCount(suggestion)
  return 0
}

function suggestionStatusText(suggestion) {
  if (suggestion?.lifecycle_status) {
    return lifecycleLabel(suggestion.lifecycle_status)
  }

  return suggestion?.status || 'Active'
}

function suggestionCriticality(suggestion) {
  if (suggestion?.criticality) return suggestion.criticality
  if (suggestion?.global_criticality) return suggestion.global_criticality
  if (suggestion?.checklist?.global_criticality) return suggestion.checklist.global_criticality

  const items = Array.isArray(suggestion?.items)
    ? suggestion.items
    : Array.isArray(suggestion?.checklist?.items)
      ? suggestion.checklist.items
      : []
  const rank = { Critical: 4, High: 3, Major: 3, Medium: 2, Low: 1, Minor: 1 }
  let current = ''

  for (const item of items) {
    if (!current || (rank[item?.criticality] || 0) > (rank[current] || 0)) {
      current = item?.criticality || current
    }
  }

  return current
}

function humanizeTechnicalLabel(value) {
  return String(value || '')
    .replaceAll('_', ' ')
    .replaceAll('-', ' ')
    .replace(/\s+/g, ' ')
    .trim()
}

function generatedSourceLabel(value) {
  const labels = {
    ai: 'Génération automatique',
    reuse: 'Réutilisation d une checklist existante',
    manual: 'Création manuelle',
  }

  return labels[value] || 'Préparation manuelle'
}

function generatedFromLabel(value) {
  const labels = currentLanguage.value === 'en'
    ? { ai: 'AI draft', reuse: 'Reuse draft', manual: 'Manual draft' }
    : { ai: 'Brouillon IA', reuse: 'Brouillon réutilisé', manual: 'Brouillon manuel' }

  return labels[value] || value || '-'
}

function lifecycleLabel(value) {
  const labels = {
    draft: 'Brouillon',
    approved: 'Approuvée',
    archived: 'Archivée',
  }

  return labels[value] || value || '-'
}

function suggestionStatusLabel() {
  return 'Suggestion'
}

function summaryChipClass(tone) {
  switch (tone) {
    case 'warning':
      return 'border-amber-200 bg-amber-50 text-amber-800'
    case 'info':
      return 'border-sky-200 bg-sky-50 text-sky-800'
    case 'success':
      return 'border-emerald-200 bg-emerald-50 text-emerald-800'
    default:
      return 'border-slate-200 bg-slate-50 text-slate-700'
  }
}

function agentStatusClass(tone) {
  switch (tone) {
    case 'info':
      return 'border-sky-200 bg-sky-50 text-sky-800'
    case 'success':
      return 'border-emerald-200 bg-emerald-50 text-emerald-800'
    case 'danger':
      return 'border-rose-200 bg-rose-50 text-rose-700'
    default:
      return 'border-slate-200 bg-slate-50 text-slate-700'
  }
}

function handleWindowClick() {
  openDraftMenuId.value = null
  openHeaderActions.value = false
}

async function adaptSuggestedChecklist() {
  if (!projectId.value) {
    return
  }

  try {
    adapting.value = true
    let attachedChecklistId = null
    const payload = {
      name: previewForm.value.name,
      description: previewForm.value.description,
      category: previewForm.value.category,
      items: previewForm.value.items.map((item) => ({
        title: item.title,
        description: item.description,
        priority: item.priority,
        criticality: item.criticality,
        status: item.status || 'pending',
      })),
    }

    if (previewMode.value === 'manual') {
      const createdChecklist = await storiesStore.createManualDraft(projectId.value, storyId, payload)
      attachedChecklistId = createdChecklist?.id || null

      if (attachedChecklistId) {
        await storiesStore.attachChecklist(projectId.value, storyId, attachedChecklistId)
      }
    } else if (previewMode.value === 'draft' && previewChecklistId.value) {
      await storiesStore.updateDraftChecklist(previewChecklistId.value, payload)
    } else if (currentPreview.value?.source_checklist_id) {
      await storiesStore.adaptChecklist(
        projectId.value,
        storyId,
        currentPreview.value.source_checklist_id,
        payload,
      )
    }

    previewOpen.value = false
    await loadStory()

    if (attachedChecklistId) {
      await nextTick()
      highlightedChecklistId.value = attachedChecklistId

      if (scrollTimeoutId) {
        clearTimeout(scrollTimeoutId)
      }
      scrollTimeoutId = setTimeout(() => {
        attachedSection.value?.scrollIntoView({ behavior: 'smooth', block: 'start' })
      }, 250)

      if (highlightTimeoutId) {
        clearTimeout(highlightTimeoutId)
      }
      highlightTimeoutId = setTimeout(() => {
        highlightedChecklistId.value = null
      }, 2000)
    }
  } catch (err) {
    alert(`${tr('error_prefix', {}, currentLanguage.value)}: ${localizeError(err, 'error_generic', currentLanguage.value)}`)
  } finally {
    adapting.value = false
  }
}

function addPreviewItem() {
  previewForm.value.items.push({
    id: `TC-${Date.now()}`,
    title: '',
    description: '',
    priority: 'Medium',
    criticality: 'Major',
    status: 'pending',
  })
}

function addExistingItemToPreview() {
  if (!selectedExistingItemId.value) {
    return
  }

  const existingItem = availableItems.value.find(
    (item) => String(item.id) === String(selectedExistingItemId.value),
  )

  if (!existingItem) {
    return
  }

  previewForm.value.items.push({
    id: `TC-${Date.now()}`,
    title: existingItem.title || '',
    description: existingItem.description || '',
    priority: existingItem.priority || 'Medium',
    criticality: existingItem.criticality || 'Major',
    status: String(existingItem.status || 'pending').toLowerCase(),
  })

  selectedExistingItemId.value = ''
}

function removePreviewItem(index) {
  previewForm.value.items.splice(index, 1)
}

function editStory() {
  if (!projectId.value) {
    return
  }

  router.push({
    name: 'story-edit',
    params: { id: storyId },
    query: { projectId: projectId.value },
  })
}

function goBack() {
  if (projectId.value) {
    router.push({ name: 'project-detail', params: { id: projectId.value } })
    return
  }

  router.back()
}

onMounted(() => {
  window.addEventListener('click', handleWindowClick)
  loadStory()
})

onBeforeUnmount(() => {
  window.removeEventListener('click', handleWindowClick)
  if (highlightTimeoutId) {
    clearTimeout(highlightTimeoutId)
  }
  if (scrollTimeoutId) {
    clearTimeout(scrollTimeoutId)
  }
})
</script>

<template>
  <section class="page stack story-detail-page">
    <div class="flex items-center gap-4">
      <button
        @click="goBack"
        class="inline-flex h-11 w-11 items-center justify-center rounded-2xl border border-slate-200 bg-white text-slate-700 shadow-sm transition hover:-translate-y-0.5 hover:border-sky-200 hover:text-sky-700"
      >
        <ArrowLeft :size="20" />
      </button>
      <div class="min-w-0 flex-1">
        <h1 class="m-0 text-[clamp(1.9rem,2.8vw,2.8rem)] font-semibold tracking-[-0.04em] text-slate-950">
          {{ storiesStore.currentStory?.title || 'Chargement...' }}
        </h1>
        <p class="muted">User Story #{{ storyId }}</p>
      </div>
    </div>

    <div v-if="storiesStore.loading" class="flex justify-center py-12">
      <div class="h-8 w-8 animate-spin rounded-full border border-blue-500 border-t-transparent"></div>
    </div>

    <div
      v-else-if="storiesStore.currentStory"
      class="space-y-6"
      :aria-busy="isInteractionLocked ? 'true' : 'false'"
      :class="{ 'pointer-events-none select-none': isInteractionLocked }"
    >
      <section class="overflow-hidden rounded-[2rem] border border-slate-200 bg-white shadow-[0_24px_60px_-44px_rgba(15,23,42,0.35)]">
        <div class="bg-[radial-gradient(circle_at_top_right,rgba(56,189,248,0.15),transparent_18rem),radial-gradient(circle_at_bottom_left,rgba(14,165,233,0.12),transparent_18rem)] px-6 py-6 md:px-8">
          <div class="flex flex-col gap-6 xl:flex-row xl:items-start xl:justify-between">
            <div class="min-w-0 flex-1 space-y-4">
              <p class="text-xs font-extrabold uppercase tracking-[0.28em] text-teal-700">Espace user story</p>
              <div class="space-y-3">
                <div class="flex flex-wrap items-center gap-2">
                  <span class="rounded-full border border-slate-200 bg-white px-3 py-1 text-xs font-semibold text-slate-700">
                    ID #{{ storyId }}
                  </span>
                  <span class="rounded-full border border-slate-200 bg-white px-3 py-1 text-xs font-semibold text-slate-700">
                    {{ storyStatusLabel }}
                  </span>
                  <span :class="['rounded-full border px-3 py-1 text-xs font-semibold', priorityColors[storiesStore.currentStory.priority]]">
                    Priorite {{ storyPriorityLabel }}
                  </span>
                  <span class="rounded-full border border-slate-200 bg-white px-3 py-1 text-xs font-semibold text-slate-700">
                    {{ projectLabel }}
                  </span>
                </div>
                <p class="max-w-4xl text-sm leading-7 text-slate-600">
                  {{ isAdminReadonly
                    ? 'Consultez la story, son contexte et les checklists deja rattachees dans un mode lecture seule.'
                    : "Analysez le besoin, preparez la checklist la plus pertinente, puis faites progresser la validation jusqu a l execution." }}
                </p>
              </div>
            </div>

            <div class="flex w-full flex-col gap-3 xl:max-w-md xl:items-end">
              <button
                v-if="auth.isTester"
                type="button"
                class="btn btn-primary w-full justify-center xl:w-auto"
                @click="handlePrimaryAction"
              >
                <FlaskConical v-if="primaryAction.key === 'execution'" :size="16" />
                <WandSparkles v-else-if="primaryAction.key === 'prepare'" :size="16" />
                <Sparkles v-else :size="16" />
                <span>{{ primaryAction.label }}</span>
              </button>

              <div class="relative w-full xl:w-auto">
                
                  

                <div
                  v-if="openHeaderActions"
                  class="absolute right-0 top-[calc(100%+0.5rem)] z-20 min-w-[16rem] overflow-hidden rounded-3xl border border-slate-200 bg-white p-2 shadow-xl"
                  @click.stop
                >
                  <button
                    v-if="auth.isTester"
                    type="button"
                    class="story-menu-action"
                    @click="toggleAttachExistingPanel(); openHeaderActions = false"
                  >
                    <Link :size="15" />
                    <span>{{ showAttachExistingPanel ? 'Masquer les checklists existantes' : 'Associer une checklist existante' }}</span>
                  </button>
                  <button
                    v-if="auth.isTester"
                    type="button"
                    class="story-menu-action"
                    @click="openManualDraft(); openHeaderActions = false"
                  >
                    <Plus :size="15" />
                    <span>Creer manuellement</span>
                  </button>
                  <RouterLink
                    v-if="projectId && auth.isTester"
                    :to="executionSpaceRoute()"
                    class="story-menu-action"
                    @click="openHeaderActions = false"
                  >
                    <FlaskConical :size="15" />
                    <span>Ouvrir l espace d execution</span>
                  </RouterLink>
                  <button
                    v-if="auth.isProjectManager"
                    type="button"
                    class="story-menu-action"
                    @click="editStory(); openHeaderActions = false"
                  >
                    <FileText :size="15" />
                    <span>Modifier la user story</span>
                  </button>
                </div>
              </div>
            </div>
          </div>
        </div>

        <div class="space-y-4 border-t border-slate-100 px-6 py-5 md:px-8">
          <div class="flex flex-wrap gap-2">
            <span
              v-for="chip in summaryChips"
              :key="chip.label"
              :class="['inline-flex items-center gap-2 rounded-full border px-3 py-2 text-xs font-semibold', summaryChipClass(chip.tone)]"
            >
              <span class="uppercase tracking-[0.14em]">{{ chip.label }}</span>
              <span class="text-sm font-bold normal-case">{{ chip.value }}</span>
            </span>
          </div>
          <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
            <div>
              <p class="text-sm font-semibold text-slate-900">Progression QA</p>
              <p class="text-sm text-slate-500">Analyser, adapter, associer puis executer sur la checklist retenue.</p>
            </div>
            <WorkflowStepper :steps="workflowSteps" :current-step="workflowCurrentStep" />
          </div>
        </div>
      </section>

      <section class="rounded-[1.75rem] border border-slate-200 bg-white p-5 shadow-sm md:p-6">
        <div class="mb-4 flex items-center justify-between gap-3">
          <div>
            <h2 class="text-xl font-semibold text-slate-950">Contexte de la user story</h2>
            <p class="text-sm text-slate-500">Consultez le besoin par onglets pour éviter de surcharger la page.</p>
          </div>
          <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-600">
            {{ storyTabs.length }} vues
          </span>
        </div>
        <StoryTabs v-model="activeStoryTab" :tabs="storyTabs" />
      </section>

      <section
        v-if="auth.isTester"
        ref="prepareSection"
        class="rounded-[1.75rem] border border-slate-200 bg-white p-5 shadow-sm md:p-6"
      >
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
          <div class="space-y-3">
            <div class="flex flex-wrap items-center gap-2">
              <h2 class="text-xl font-semibold text-slate-950">Preparer une checklist</h2>
              <span :class="['rounded-full border px-3 py-1 text-xs font-semibold', agentStatusClass(agentStatus.tone)]">
                {{ agentStatus.label }}
              </span>
            </div>
            <p class="max-w-3xl text-sm leading-7 text-slate-600">
              {{ agentStatus.description }}
            </p>
          </div>
          <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-600">
            <p class="font-semibold text-slate-900">Source : {{ generatedSourceLabel('ai') }}</p>
            <p class="mt-1">Le workflow garde la meme logique metier, avec une lecture plus progressive.</p>
          </div>
        </div>

        <div class="mt-5 grid gap-4 xl:grid-cols-3">
          <button type="button" class="prepare-choice-card text-left" @click="toggleAttachExistingPanel">
            <div class="flex items-center gap-3">
              <span class="rounded-2xl bg-sky-50 p-3 text-sky-700">
                <Link :size="18" />
              </span>
              <div>
                <p class="font-semibold text-slate-900">Reutiliser une checklist existante</p>
                <p class="text-sm text-slate-500">Associez un modele deja valide dans le projet.</p>
              </div>
            </div>
            <p class="mt-4 text-xs font-semibold uppercase tracking-[0.16em] text-slate-400">
              {{ attachableChecklists.length }} disponible(s)
            </p>
          </button>

          <button
            type="button"
            class="prepare-choice-card text-left"
            :disabled="storiesStore.isGenerating"
            @click="generateChecklist"
          >
            <div class="flex items-center gap-3">
              <span class="rounded-2xl bg-violet-50 p-3 text-violet-700">
                <Sparkles :size="18" />
              </span>
              <div>
                <p class="font-semibold text-slate-900">
                  {{ storiesStore.isGenerating ? "Génération..." : "Générer avec l'agent" }}
                </p>
                <p class="text-sm text-slate-500">
                  {{ storiesStore.isGenerating
                    ? 'Veuillez patienter pendant que le systeme prepare la checklist.'
                    : 'L agent privilegie la reutilisation avant de completer la couverture manquante.' }}
                </p>
              </div>
            </div>
            <p class="mt-4 text-xs font-semibold uppercase tracking-[0.16em] text-slate-400">
              {{ storiesStore.isGenerating ? 'Generation en cours...' : 'Pret a lancer' }}
            </p>
          </button>

          <button type="button" class="prepare-choice-card text-left" @click="openManualDraft">
            <div class="flex items-center gap-3">
              <span class="rounded-2xl bg-amber-50 p-3 text-amber-700">
                <Plus :size="18" />
              </span>
              <div>
                <p class="font-semibold text-slate-900">Créer manuellement</p>
                <p class="text-sm text-slate-500">Préparez un brouillon sur mesure avant validation.</p>
              </div>
            </div>
            <p class="mt-4 text-xs font-semibold uppercase tracking-[0.16em] text-slate-400">
              Brouillon editable
            </p>
          </button>
        </div>

        <div v-if="auth.isTester && showAttachExistingPanel" class="mt-5 rounded-3xl border border-slate-200 bg-slate-50/80 p-5">
          <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div>
              <h3 class="text-lg font-semibold text-slate-950">Associer une checklist existante</h3>
              <p class="text-sm text-slate-500">Selectionnez une checklist active du projet pour l associer directement a cette user story.</p>
            </div>
            <span class="rounded-full bg-white px-3 py-1 text-xs font-semibold text-slate-600">
              {{ attachableChecklists.length }} disponible(s)
            </span>
          </div>

          <div v-if="loadingAvailableChecklists" class="mt-4 text-sm text-slate-500">Chargement des checklists disponibles...</div>

          <div v-else-if="attachableChecklists.length === 0" class="mt-4 rounded-3xl border border-dashed border-slate-200 bg-white px-5 py-8 text-center">
            <AlertCircle :size="30" class="mx-auto mb-3 text-slate-400" />
            <p class="font-medium text-slate-900">Aucune checklist existante disponible</p>
            <p class="mt-2 text-sm text-slate-500">Toutes les checklists actives du projet sont deja associees ou archivées.</p>
          </div>

          <form v-else class="mt-4 space-y-4" @submit.prevent="attachExistingChecklist">
            <div class="grid gap-3">
              <label
                v-for="checklist in paginatedAttachableChecklists"
                :key="checklist.id"
                class="grid cursor-pointer grid-cols-[auto_minmax(0,1fr)] gap-3 rounded-2xl border border-slate-200 bg-white px-4 py-4 transition hover:border-sky-200 hover:shadow-sm"
              >
                <input
                  v-model="selectedExistingChecklistIds"
                  type="checkbox"
                  :value="Number(checklist.id)"
                  :disabled="attachingExistingChecklist"
                  class="mt-1 h-4 w-4 rounded border-slate-300"
                />
                <span class="min-w-0">
                  <strong class="block text-sm font-semibold text-slate-900">{{ checklist.name }}</strong>
                  <small class="mt-1 block text-sm text-slate-500">
                    {{ checklist.category || 'Sans categorie' }} · {{ checklist.items?.length || 0 }} cas de test · {{ checklist.lifecycle_status || checklist.status || 'validee' }}
                  </small>
                  <span v-if="checklist.description" class="mt-1 block text-sm leading-6 text-slate-600">{{ checklist.description }}</span>
                </span>
              </label>
            </div>

            <div v-if="attachChecklistTotalPages > 1" class="flex items-center justify-end gap-3">
              <button
                type="button"
                class="btn btn-secondary btn-sm"
                :disabled="attachChecklistPage === 1 || attachingExistingChecklist"
                @click="goToAttachChecklistPage(attachChecklistPage - 1)"
              >
                Precedent
              </button>
              <span class="text-sm font-semibold text-slate-500">Page {{ attachChecklistPage }} / {{ attachChecklistTotalPages }}</span>
              <button
                type="button"
                class="btn btn-secondary btn-sm"
                :disabled="attachChecklistPage === attachChecklistTotalPages || attachingExistingChecklist"
                @click="goToAttachChecklistPage(attachChecklistPage + 1)"
              >
                Suivant
              </button>
            </div>

            <div v-if="selectedExistingChecklists.length > 0" class="rounded-2xl border border-slate-200 bg-white px-4 py-4">
              <strong class="block text-sm font-semibold text-slate-900">{{ selectedExistingChecklists.length }} checklist(s) selectionnee(s)</strong>
              <p class="mt-1 text-sm text-slate-500">Elles seront associees a cette user story et visibles dans la section des checklists associees.</p>
            </div>

            <div class="flex justify-end gap-3">
              <button type="button" class="btn btn-secondary" @click="toggleAttachExistingPanel">
                Annuler
              </button>
              <button class="btn btn-primary" type="submit" :disabled="attachingExistingChecklist || selectedExistingChecklistIds.length === 0">
                {{ attachingExistingChecklist ? 'Association...' : 'Associer les checklists' }}
              </button>
            </div>
          </form>
        </div>
        <div class="mt-8 border-t border-slate-100 pt-6">
          <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
            <div>
              <h3 class="text-xl font-semibold text-slate-950">En attente de validation testeur</h3>
              <p class="text-sm text-slate-500">Les brouillons restent ici jusqu a validation puis association a la user story.</p>
            </div>
            <span class="rounded-full bg-amber-50 px-3 py-1 text-xs font-semibold text-amber-800">
              {{ pendingDrafts.length }} brouillon(s)
            </span>
          </div>

          <div v-if="hasDrafts" class="mt-5 flex flex-col gap-4">
          <article
            v-for="draft in pendingDrafts"
            :key="draft.id"
            class="rounded-3xl border border-slate-200 bg-slate-50/70 p-4 shadow-sm"
          >
            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
              <div class="min-w-0 flex-1 space-y-2">
                <div class="flex flex-wrap items-center gap-2">
                  <h3 class="text-base font-semibold text-slate-900">{{ localizeGeneratedText(draft.name) }}</h3>
                  <span class="rounded-full border border-amber-200 bg-amber-50 px-2.5 py-1 text-xs font-semibold text-amber-700">
                    En attente
                  </span>
                </div>
                <p class="text-sm leading-6 text-slate-600">
                  {{ localizeGeneratedText(draft.description) || 'Brouillon pret pour relecture et validation.' }}
                </p>
                <div class="flex flex-wrap gap-2 text-xs font-semibold text-slate-600">
                  <span class="rounded-full bg-white px-2.5 py-1">{{ draft.items?.length || 0 }} test case(s)</span>
                  <span class="rounded-full bg-white px-2.5 py-1">Statut : {{ lifecycleLabel(draft.lifecycle_status) }}</span>
                  <span class="rounded-full bg-white px-2.5 py-1">Source : {{ generatedSourceLabel(draft.generated_from) }}</span>
                </div>
              </div>

              <div v-if="auth.isTester" class="relative self-start">
                  <button
                    type="button"
                    class="btn btn-secondary btn-sm"
                    :aria-expanded="openDraftMenuId === draft.id"
                    @click.stop="toggleDraftMenu(draft.id)"
                  >
                    <Ellipsis :size="14" />
                  </button>
                  <div
                    v-if="openDraftMenuId === draft.id"
                    class="absolute right-0 top-[calc(100%+0.35rem)] z-20 min-w-[12rem] overflow-hidden rounded-2xl border border-slate-200 bg-white p-2 shadow-lg"
                    @click.stop
                  >
                    <button type="button" class="story-menu-action" @click="editPendingDraft(draft); openDraftMenuId = null">
                      Relire le brouillon
                    </button>
                    <button
                      type="button"
                      class="story-menu-action"
                      :disabled="approvingDraftId === draft.id"
                      @click="approveGeneratedChecklist(draft.id); openDraftMenuId = null"
                    >
                      {{ approvingDraftId === draft.id ? 'Association...' : 'Associer' }}
                    </button>
                    <button
                      type="button"
                      class="story-menu-action text-rose-600"
                      :disabled="rejectingDraftId === draft.id"
                      @click="rejectPendingDraft(draft.id)"
                    >
                      {{ rejectingDraftId === draft.id ? 'Refus...' : 'Refuser' }}
                    </button>
                  </div>
              </div>
            </div>
          </article>
        </div>

          <div v-else class="mt-5 rounded-3xl border border-dashed border-slate-200 bg-slate-50 px-5 py-9 text-center">
            <AlertCircle :size="30" class="mx-auto mb-3 text-slate-400" />
            <p class="font-medium text-slate-900">Aucun brouillon en attente</p>
            <p class="mt-2 text-sm text-slate-500">Lorsqu une checklist doit etre relue avant association, elle apparait ici.</p>
          </div>
        </div>
      </section>

      <section
        v-if="auth.isTester"
        ref="suggestionsSection"
        class="rounded-[1.75rem] border border-slate-200 bg-white p-5 shadow-sm md:p-6"
      >
        <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
          <div>
            <h2 class="text-xl font-semibold text-slate-950">{{ suggestionStatus.title }}</h2>
            <p class="text-sm text-slate-500">{{ suggestionStatus.description }}</p>
          </div>
          <button
            type="button"
            class="btn btn-secondary btn-sm self-start"
            :disabled="loadingSuggestions"
            @click="refreshSuggestedChecklists({ notifyIfEmpty: true, lockUi: true })"
          >
            <Zap :size="14" />
            <span>{{ loadingSuggestions ? 'Recherche...' : 'Recommander' }}</span>
          </button>
        </div>

        <div v-if="hasSuggestions" class="mt-5 flex flex-col gap-4">
          <SuggestionCard
            v-for="suggestion in visibleSuggestions"
            :key="suggestionRenderKey(suggestion)"
            :title="suggestion.title || suggestion.name || 'Checklist suggeree'"
            :description="suggestion.description || 'Aucune description disponible.'"
            :score-label="scoreLabel(suggestion)"
            :item-count="suggestionItemCount(suggestion)"
            :status-label="suggestionStatusText(suggestion)"
            :criticality-label="suggestionCriticality(suggestion)"
            :can-create-draft="auth.canCurateStoryChecklists"
            :actions-disabled="!suggestionIdentifier(suggestion)"
            :loading="previewLoading"
            @view-details="openSuggestionPreview(suggestionIdentifier(suggestion), 'details')"
            @adapt="openSuggestionPreview(suggestionIdentifier(suggestion), 'adapt')"
            @create-draft="openSuggestionPreview(suggestionIdentifier(suggestion), 'draft')"
          />
        </div>

        <div v-else class="mt-5 rounded-3xl border border-dashed border-slate-200 bg-slate-50 px-5 py-9 text-center">
          <AlertCircle :size="30" class="mx-auto mb-3 text-slate-400" />
          <p class="font-medium text-slate-900">
            {{ loadingSuggestions ? 'Recherche en cours...' : 'Aucune checklist similaire trouvee' }}
          </p>
          <p class="mt-2 text-sm text-slate-500">
            {{ loadingSuggestions
              ? 'Veuillez patienter pendant que le systeme analyse les checklists existantes.'
              : 'Vous pouvez creer une checklist manuellement ou lancer l agent de generation.' }}
          </p>
        </div>
      </section>

      <section
        ref="attachedSection"
        class="rounded-[1.75rem] border border-slate-200 bg-white p-5 shadow-sm md:p-6"
      >
        <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
          <div>
            <h2 class="text-xl font-semibold text-slate-950">Checklists associées</h2>
            <p class="text-sm text-slate-500">Ces checklists sont rattachees a cette User Story pour conserver la traçabilité et le contexte de test.</p>
          </div>
        </div>

        <div v-if="hasAttachedChecklists" class="mt-5 flex flex-col gap-4">
          <ChecklistAccordion
            v-for="checklist in attachedChecklists"
            :key="checklist.id"
            :checklist="checklist"
            :execution-to="executionSpaceRoute(checklist.id)"
            :edit-to="checklistEditRoute(checklist.id)"
            :can-execute="Boolean(projectId) && auth.isTester"
            :can-manage="auth.isTester"
            :highlighted="highlightedChecklistId === checklist.id"
            @detach="detachChecklist(checklist.id)"
          />
        </div>

        <div v-else class="mt-5 rounded-3xl border border-dashed border-slate-200 bg-slate-50 px-5 py-9 text-center">
          <AlertCircle :size="30" class="mx-auto mb-3 text-slate-400" />
          <p class="font-medium text-slate-900">Aucune checklist associee</p>
          <p class="mt-2 text-sm text-slate-500">Aucune checklist n est encore rattachee a cette User Story.</p>
          <button
            v-if="auth.isTester"
            type="button"
            class="btn btn-primary mt-4"
            @click="scrollToSection(prepareSection)"
          >
            Preparer une checklist
          </button>
        </div>
      </section>
    </div>

    <div v-else class="card empty-dashed-card story-empty-state">
      <p class="muted">User Story introuvable.</p>
    </div>

    <div
      v-if="isInteractionLocked"
      class="fixed inset-0 z-40 flex items-center justify-center bg-slate-950/28 px-4 backdrop-blur-[2px]"
      aria-live="polite"
      aria-modal="true"
      role="status"
    >
      <div class="w-full max-w-sm rounded-[1.75rem] border border-slate-200 bg-white px-6 py-6 text-center shadow-2xl">
        <div class="story-lock-spinner mx-auto"></div>
        <h3 class="mt-4 text-lg font-semibold text-slate-950">{{ interactionLockState.title }}</h3>
        <p class="mt-2 text-sm leading-6 text-slate-500">{{ interactionLockState.description }}</p>
      </div>
    </div>

    <div v-if="previewOpen" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/40 p-4">
      <div class="max-h-[88vh] w-full max-w-6xl overflow-hidden rounded-[2rem] border border-slate-200 bg-white shadow-2xl">
        <div class="flex flex-col gap-3 border-b border-slate-200 px-6 py-5 lg:flex-row lg:items-center lg:justify-between">
          <div>
            <h3 class="text-xl font-semibold text-slate-900">
              {{
                previewMode === 'manual'
                  ? 'Nouveau brouillon manuel'
                  : previewMode === 'draft'
                    ? previewForm.name || 'Brouillon de checklist'
                    : currentPreview?.checklist?.title || previewForm.name || 'Checklist suggeree'
              }}
            </h3>
            <p class="text-sm text-slate-500">
              {{
                previewMode === 'manual'
                  ? 'Preparez un brouillon manuel avant validation.'
                  : previewMode === 'draft'
                    ? 'Relisez et ajustez le brouillon avant association.'
                    : previewIntent === 'adapt'
                      ? 'Adaptez la suggestion retenue avant creation du brouillon.'
                      : previewIntent === 'draft'
                        ? 'Creez un brouillon a partir de cette suggestion.'
                        : 'Consultez les details de la suggestion puis decidez de la suite.'
              }}
            </p>
          </div>
          <button @click="closePreview" class="rounded-xl border border-slate-200 px-3 py-2 text-sm hover:bg-slate-50">
            Fermer
          </button>
        </div>

        <div class="grid max-h-[calc(88vh-5.5rem)] grid-cols-1 lg:grid-cols-[320px_minmax(0,1fr)]">
          <aside class="border-r border-slate-200 bg-slate-50/80 p-5">
            <div class="space-y-4">
              <div class="rounded-2xl border border-slate-200 bg-white p-4">
                <p class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Synthese</p>
                <div class="mt-3 space-y-2 text-sm text-slate-600">
                  <p v-if="previewMode === 'suggestion'">Decision : {{ recommendationLabel(currentPreview?.suggestion?.recommendation) }}</p>
                  <p v-if="previewMode === 'suggestion'">Correspondance : {{ confidenceLabel(currentPreview?.suggestion) }}</p>
                  <p>Source : {{ previewMode === 'manual' ? generatedSourceLabel('manual') : previewMode === 'draft' ? generatedSourceLabel('reuse') : generatedSourceLabel('ai') }}</p>
                </div>
              </div>

              <div v-if="previewMode === 'suggestion'" class="rounded-2xl border border-slate-200 bg-white p-4">
                <p class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Couverture</p>
                <div class="mt-3 flex flex-wrap gap-2">
                  <span
                    v-for="item in currentPreview?.suggestion?.coverage || []"
                    :key="item"
                    class="rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-semibold text-emerald-700"
                  >
                    {{ humanizeTechnicalLabel(item) }}
                  </span>
                </div>
              </div>

              <div v-if="previewMode === 'suggestion'" class="rounded-2xl border border-slate-200 bg-white p-4">
                <p class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Couverture manquante</p>
                <div class="mt-3 flex flex-wrap gap-2">
                  <span
                    v-for="item in currentPreview?.suggestion?.missing || []"
                    :key="item"
                    class="rounded-full bg-amber-50 px-2.5 py-1 text-xs font-semibold text-amber-700"
                  >
                    {{ humanizeTechnicalLabel(item) }}
                  </span>
                </div>
              </div>

              <div class="rounded-2xl bg-sky-50 px-4 py-4 text-sm leading-6 text-sky-900">
                <template v-if="previewMode === 'suggestion'">
                  {{ currentPreview?.suggestion?.explanation || 'Cette suggestion peut etre adaptee avant validation.' }}
                </template>
                <template v-else-if="previewMode === 'draft'">
                  Ajustez librement le brouillon avant de l associer a la user story.
                </template>
                <template v-else>
                  Construisez un brouillon manuel puis faites-le valider dans le workflow habituel.
                </template>
              </div>

              <button
                class="w-full rounded-2xl bg-sky-600 px-4 py-3 text-sm font-semibold text-white transition hover:bg-sky-700 disabled:opacity-50"
                :disabled="adapting || attaching || previewForm.items.length === 0"
                @click="adaptSuggestedChecklist"
              >
                {{
                  adapting
                    ? 'Enregistrement...'
                    : previewMode === 'manual'
                      ? 'Associer'
                      : previewMode === 'draft'
                        ? 'Enregistrer les modifications'
                        : previewIntent === 'adapt'
                          ? 'Créer un brouillon adapté'
                          : 'Créer un brouillon de validation'
                }}
              </button>
            </div>
          </aside>

          <div class="space-y-5 overflow-auto p-6">
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
              <label class="block">
                <span class="text-sm font-medium text-slate-700">Nom de la checklist</span>
                <input v-model="previewForm.name" class="mt-2 w-full rounded-2xl border border-slate-300 px-4 py-3" />
              </label>
              <label class="block">
                <span class="text-sm font-medium text-slate-700">Categorie</span>
                <input v-model="previewForm.category" class="mt-2 w-full rounded-2xl border border-slate-300 px-4 py-3" />
              </label>
            </div>

            <label class="block">
              <span class="text-sm font-medium text-slate-700">Description</span>
              <textarea v-model="previewForm.description" rows="3" class="mt-2 w-full rounded-2xl border border-slate-300 px-4 py-3"></textarea>
            </label>

            <div class="flex items-center justify-between gap-3">
              <div>
                <h4 class="text-lg font-semibold text-slate-900">Cas de test</h4>
                <p class="text-sm text-slate-500">Les cas de test restent modifiables avant validation.</p>
              </div>
              <button @click="addPreviewItem" class="rounded-xl border border-slate-200 px-3 py-2 text-sm hover:bg-slate-50">
                Ajouter un item
              </button>
            </div>

            <div class="rounded-3xl border border-slate-200 bg-slate-50 p-4">
              <div class="grid grid-cols-1 gap-4 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-end">
                <label class="block">
                  <span class="text-sm font-medium text-slate-700">Ajouter un item existant</span>
                  <select
                    v-model="selectedExistingItemId"
                    class="mt-2 w-full rounded-2xl border border-slate-300 bg-white px-4 py-3"
                    @focus="loadAvailableItems"
                  >
                    <option value="">
                      {{
                        loadingAvailableItems
                          ? 'Chargement des items disponibles...'
                          : addableExistingItems.length > 0
                            ? 'Selectionner un item existant'
                            : 'Aucun item réutilisable disponible'
                      }}
                    </option>
                    <option
                      v-for="existingItem in addableExistingItems"
                      :key="existingItem.id"
                      :value="existingItem.id"
                    >
                      {{ existingItem.title }} ({{ existingItem.priority }} / {{ existingItem.criticality }})
                    </option>
                  </select>
                </label>
                <button
                  type="button"
                  class="rounded-xl border border-slate-200 px-4 py-3 text-sm hover:bg-white disabled:cursor-not-allowed disabled:opacity-50"
                  :disabled="loadingAvailableItems || !selectedExistingItemId"
                  @click="addExistingItemToPreview"
                >
                  Ajouter l item selectionne
                </button>
              </div>
              <p class="mt-3 text-sm text-slate-500">
                éutilisez rapidement des items déjà présents dans la bibliothèque de checklists.
              </p>
            </div>

            <div class="max-h-[26rem] space-y-4 overflow-y-auto pr-2">
              <div
                v-for="(item, index) in previewForm.items"
                :key="item.id || index"
                class="rounded-3xl border border-slate-200 bg-slate-50 p-4"
              >
                <div class="flex flex-col gap-4 xl:flex-row xl:items-start xl:justify-between">
                  <div class="grid flex-1 grid-cols-1 gap-4 md:grid-cols-2">
                    <label class="block md:col-span-2">
                      <span class="text-sm font-medium text-slate-700">Titre</span>
                      <input v-model="item.title" class="mt-2 w-full rounded-2xl border border-slate-300 px-4 py-3" />
                    </label>
                    <label class="block md:col-span-2">
                      <span class="text-sm font-medium text-slate-700">Description</span>
                      <textarea v-model="item.description" rows="2" class="mt-2 w-full rounded-2xl border border-slate-300 px-4 py-3"></textarea>
                    </label>
                    <label class="block">
                      <span class="text-sm font-medium text-slate-700">Priorite</span>
                      <select v-model="item.priority" class="mt-2 w-full rounded-2xl border border-slate-300 px-4 py-3">
                        <option>Low</option>
                        <option>Medium</option>
                        <option>High</option>
                      </select>
                    </label>
                    <label class="block">
                      <span class="text-sm font-medium text-slate-700">Criticite</span>
                      <select v-model="item.criticality" class="mt-2 w-full rounded-2xl border border-slate-300 px-4 py-3">
                        <option>Minor</option>
                        <option>Major</option>
                        <option>Critical</option>
                      </select>
                    </label>
                    <label class="block">
                      <span class="text-sm font-medium text-slate-700">Statut</span>
                      <select v-model="item.status" class="mt-2 w-full rounded-2xl border border-slate-300 px-4 py-3">
                        <option value="pending">Pending</option>
                        <option value="passed">Passed</option>
                        <option value="failed">Failed</option>
                      </select>
                    </label>
                  </div>

                  <button @click="removePreviewItem(index)" class="btn btn-danger btn-sm self-start" type="button">
                    <Trash2 :size="14" />
                    <span>Supprimer</span>
                  </button>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </section>
</template>

<style>
.story-detail-page {
  gap: 1.35rem;
}

.story-menu-action {
  display: flex;
  width: 100%;
  align-items: center;
  gap: 0.65rem;
  border-radius: 1rem;
  padding: 0.8rem 0.95rem;
  text-align: left;
  font-size: 0.92rem;
  color: #334155;
  transition: background 0.2s ease, color 0.2s ease;
}

.story-menu-action:hover {
  background: #f8fafc;
  color: #0f172a;
}

.prepare-choice-card {
  border-radius: 1.5rem;
  border: 1px solid rgba(226, 232, 240, 1);
  background: linear-gradient(180deg, rgba(255, 255, 255, 1), rgba(248, 250, 252, 0.94));
  padding: 1.15rem;
  transition: transform 0.2s ease, border-color 0.2s ease, box-shadow 0.2s ease;
}

.prepare-choice-card:hover {
  transform: translateY(-2px);
  border-color: rgba(125, 211, 252, 1);
  box-shadow: 0 18px 32px -28px rgba(15, 23, 42, 0.28);
}

.prepare-choice-card:disabled {
  cursor: not-allowed;
  opacity: 0.65;
  transform: none;
  box-shadow: none;
}

.story-lock-spinner {
  height: 2.75rem;
  width: 2.75rem;
  border-radius: 9999px;
  border: 3px solid #dbeafe;
  border-top-color: #0284c7;
  animation: story-lock-spin 0.8s linear infinite;
}

@keyframes story-lock-spin {
  to {
    transform: rotate(360deg);
  }
}
</style>

