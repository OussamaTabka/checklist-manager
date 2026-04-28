<script setup>
import { computed, onMounted, ref } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { useUserStoriesStore } from '@/stores/userStories'
import { useAuthStore } from '@/stores/auth'
import { translateCurrentPhrase } from '@/lib/runtimeTranslations'
import { AlertCircle, ArrowLeft, ArrowRight, CheckCircle2, FlaskConical, Sparkles, Trash2, Zap } from 'lucide-vue-next'

const route = useRoute()
const router = useRouter()
const storiesStore = useUserStoriesStore()
const auth = useAuthStore()

const projectId = computed(() => route.query.projectId || null)
const storyId = route.params.id

const statusLabels = {
  backlog: 'Backlog',
  in_progress: 'In Progress',
  ready_for_test: 'Ready for Test',
  completed: 'Completed',
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
const attachedChecklistCount = computed(() => storiesStore.currentStory?.checklists?.length || 0)
const suggestedChecklistCount = computed(() => currentSuggestions.value?.suggestions?.length || 0)
const storyStatusLabel = computed(() => statusLabels[storiesStore.currentStory?.status] || storiesStore.currentStory?.status || '-')
const storyPriorityLabel = computed(() => {
  const priority = storiesStore.currentStory?.priority || ''
  return priority ? priority.charAt(0).toUpperCase() + priority.slice(1) : '-'
})
const acceptanceCriteriaText = computed(() => storiesStore.currentStory?.acceptance_criteria || 'No acceptance criteria provided.')

const previewOpen = ref(false)
const previewLoading = ref(false)
const adapting = ref(false)
const attaching = ref(false)
const previewMode = ref('suggestion')
const previewChecklistId = ref(null)
const previewForm = ref({
  name: '',
  description: '',
  category: '',
  items: [],
})

async function loadStory() {
  if (!projectId.value) {
    return
  }

  await Promise.all([
    storiesStore.fetchStory(projectId.value, storyId),
    storiesStore.fetchGeneratorStatus(projectId.value),
    storiesStore.fetchChecklistSuggestions(projectId.value, storyId),
  ])
}

async function generateChecklist() {
  if (!confirm(translateCurrentPhrase('Launch the checklist agent? It will reuse approved checklists first, then generate missing coverage.'))) {
    return
  }
  if (!projectId.value) {
    return
  }

  try {
    await storiesStore.generateChecklistWithAgent(projectId.value, storyId)
    await loadStory()
  } catch (err) {
    alert(`${translateCurrentPhrase('Error')}: ${err.message}`)
  }
}

async function detachChecklist(checklistId) {
  if (!confirm(translateCurrentPhrase('Detach this checklist?'))) {
    return
  }
  if (!projectId.value) {
    return
  }

  try {
    await storiesStore.detachChecklist(projectId.value, storyId, checklistId)
    await storiesStore.fetchChecklistSuggestions(projectId.value, storyId)
  } catch (err) {
    alert(`${translateCurrentPhrase('Error')}: ${err.message}`)
  }
}

async function viewSuggestedChecklist(checklistId) {
  if (!projectId.value) {
    return
  }

  try {
    previewLoading.value = true
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
    previewMode.value = 'suggestion'
    previewChecklistId.value = checklistId
    previewOpen.value = true
  } catch (err) {
    alert(`${translateCurrentPhrase('Error')}: ${err.message}`)
  } finally {
    previewLoading.value = false
  }
}

async function approveGeneratedChecklist(checklistId) {
  if (!projectId.value) {
    return
  }

  try {
    await storiesStore.approveGeneratedChecklist(projectId.value, storyId, checklistId)
    await loadStory()
  } catch (err) {
    alert(`${translateCurrentPhrase('Error')}: ${err.message}`)
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
        (suggestion) => String(suggestion.source_checklist_id || suggestion.id) !== String(checklistId)
      ),
    },
  }
}

function openManualDraft() {
  previewMode.value = 'manual'
  previewChecklistId.value = null
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
  previewOpen.value = true
}

function editPendingDraft(draft) {
  previewMode.value = 'draft'
  previewChecklistId.value = draft.id
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
  previewOpen.value = true
}

function closePreview() {
  if (previewLoading.value || adapting.value || attaching.value) {
    return
  }
  previewOpen.value = false
}

function addPreviewItem() {
  previewForm.value.items.push({
    id: `TC-${String(previewForm.value.items.length + 1).padStart(3, '0')}`,
    title: '',
    description: '',
    priority: 'Medium',
    criticality: 'Major',
    status: 'pending',
  })
}

function removePreviewItem(index) {
  previewForm.value.items.splice(index, 1)
}

async function adaptSuggestedChecklist() {
  if (!projectId.value) {
    return
  }

  try {
    adapting.value = true
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
      await storiesStore.createManualDraft(projectId.value, storyId, payload)
    } else if (previewMode.value === 'draft' && previewChecklistId.value) {
      await storiesStore.updateDraftChecklist(previewChecklistId.value, payload)
    } else if (currentPreview.value?.source_checklist_id) {
      await storiesStore.adaptChecklist(
        projectId.value,
        storyId,
        currentPreview.value.source_checklist_id,
        payload
      )
    }

    previewOpen.value = false
    await loadStory()
  } catch (err) {
    alert(`${translateCurrentPhrase('Error')}: ${err.message}`)
  } finally {
    adapting.value = false
  }
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
  router.back()
}

onMounted(() => {
  loadStory()
})
</script>

<template>
  <section class="page stack story-detail-page">
    <div class="story-detail-topbar">
      <button @click="goBack" class="story-detail-back">
        <ArrowLeft :size="20" />
      </button>
      <div class="story-detail-topbar-copy">
        <h1>{{ storiesStore.currentStory?.title || 'Loading...' }}</h1>
        <p class="muted">Story ID: #{{ storyId }}</p>
      </div>
      <button
        v-if="auth.isProjectManager && storiesStore.currentStory"
        @click="editStory"
        class="btn btn-secondary"
      >
        Edit story
      </button>
    </div>

    <div v-if="storiesStore.loading" class="flex justify-center py-12">
      <div class="animate-spin rounded-full h-8 w-8 border border-blue-500 border-t-transparent"></div>
    </div>

    <div v-else-if="storiesStore.currentStory" class="stack stack-gap-lg">
      <div class="story-detail-hero">
        <div>
          <p class="story-detail-kicker">Story workspace</p>
          <h2>{{ storiesStore.currentStory.title }}</h2>
          <p class="story-detail-subtitle">
            Review the requirement, adapt the best checklist, then move into the execution workspace to run tests automatically.
          </p>
        </div>

        <div class="story-detail-hero-actions">
          <button
            v-if="auth.isProjectManager"
            @click="openManualDraft"
            class="btn btn-secondary"
          >
            <Sparkles :size="16" />
            <span>Add manual checklist</span>
          </button>
          <RouterLink
            v-if="projectId"
            class="btn btn-primary"
            :to="{ name: 'project-detail', params: { id: projectId } }"
          >
            <FlaskConical :size="16" />
            <span>Open execution workspace</span>
          </RouterLink>
          <button
            @click="generateChecklist"
            :disabled="storiesStore.isGenerating"
            class="btn btn-secondary"
          >
            <Zap :size="16" />
            <span>{{ storiesStore.isGenerating ? 'Agent running...' : 'Launch agent' }}</span>
          </button>
        </div>
      </div>

      <div class="story-detail-metrics">
        <article class="story-detail-metric">
          <span>Status</span>
          <strong>{{ storyStatusLabel }}</strong>
        </article>
        <article class="story-detail-metric">
          <span>Priority</span>
          <strong>{{ storyPriorityLabel }}</strong>
        </article>
        <article class="story-detail-metric">
          <span>Attached checklists</span>
          <strong>{{ attachedChecklistCount }}</strong>
        </article>
        <article class="story-detail-metric">
          <span>Suggestions</span>
          <strong>{{ suggestedChecklistCount }}</strong>
        </article>
      </div>

      <div class="story-detail-grid">
        <div class="card stack story-detail-summary-card">
          <div class="story-detail-card-head">
            <h3>Description</h3>
            <span :class="['story-priority-pill', priorityColors[storiesStore.currentStory.priority]]">
              {{ storyPriorityLabel }}
            </span>
          </div>
          <p class="story-detail-richtext">{{ storiesStore.currentStory.description }}</p>
        </div>

        <div class="card stack story-detail-summary-card">
          <div class="story-detail-card-head">
            <h3>Acceptance criteria</h3>
            <CheckCircle2 :size="18" />
          </div>
          <div class="story-detail-criteria-box">
            {{ acceptanceCriteriaText }}
          </div>
        </div>
      </div>

      <div class="story-workflow-strip">
        <div class="story-workflow-step active">
          <span>1</span>
          <strong>Review story</strong>
        </div>
        <ArrowRight :size="16" class="story-workflow-arrow" />
        <div class="story-workflow-step active">
          <span>2</span>
          <strong>Adapt checklist</strong>
        </div>
        <ArrowRight :size="16" class="story-workflow-arrow" />
        <div class="story-workflow-step">
          <span>3</span>
          <strong>Attach to project</strong>
        </div>
        <ArrowRight :size="16" class="story-workflow-arrow" />
        <div class="story-workflow-step">
          <span>4</span>
          <strong>Run Test in workspace</strong>
        </div>
      </div>

      <div v-if="currentSuggestions" class="card stack story-suggestions-card">
        <div class="story-detail-card-head story-detail-card-head-start">
          <div>
            <h3>Checklist suggestions</h3>
            <p class="muted">
              Always review before attach. The assistant suggests reusable QA templates and highlights missing coverage.
            </p>
          </div>
          <span class="story-recommendation-pill">
            Recommended: {{ currentSuggestions.summary?.recommended_action || 'GENERATE_NEW' }}
          </span>
        </div>

        <div v-if="currentSuggestions.suggestions?.length" class="stack stack-gap-sm">
          <div
            v-for="suggestion in currentSuggestions.suggestions"
            :key="suggestion.source_checklist_id || suggestion.id"
            class="story-suggestion-card"
          >
            <div class="story-suggestion-head">
              <div class="story-suggestion-copy">
                <p class="story-suggestion-title">{{ suggestion.title || suggestion.name }}</p>
                <p class="muted">{{ suggestion.description || 'No description' }}</p>
                <p class="story-suggestion-meta">
                  {{ suggestion.checklist_id }} | Score: {{ suggestion.score }} | {{ suggestion.items_count }} items | {{ suggestion.status }}
                </p>
                <p class="story-suggestion-meta">
                  Match: {{ (suggestion.matching_keywords || suggestion.matched_terms || []).join(', ') || '-' }}
                </p>
              </div>
              <div class="story-suggestion-actions">
                <button
                  v-if="auth.canCurateStoryChecklists"
                  @click="viewSuggestedChecklist(suggestion.source_checklist_id || suggestion.id)"
                  class="btn btn-secondary btn-sm"
                  :disabled="previewLoading"
                >
                  {{ previewLoading ? 'Loading...' : 'View details' }}
                </button>
                <button
                  v-if="auth.isProjectManager"
                  @click="removeSuggestedChecklist(suggestion.source_checklist_id || suggestion.id)"
                  class="btn btn-danger btn-sm"
                  type="button"
                >
                  <Trash2 :size="14" />
                  <span>Delete</span>
                </button>
              </div>
            </div>

            <div class="story-suggestion-grid">
              <div class="story-suggestion-chip story-suggestion-chip-good">
                Coverage: {{ (suggestion.coverage || []).join(', ') || '-' }}
              </div>
              <div class="story-suggestion-chip story-suggestion-chip-warn">
                Missing: {{ (suggestion.missing || []).join(', ') || '-' }}
              </div>
              <div class="story-suggestion-chip story-suggestion-chip-neutral">
                Recommendation: {{ suggestion.recommendation }}
              </div>
            </div>
          </div>
        </div>

        <p v-else class="muted">
          No approved checklist is similar enough. Recommended next step: generate a new draft.
        </p>
      </div>

      <div class="story-agent-panel">
        <div class="story-agent-panel-inner">
          <div>
            <h3 class="story-agent-title">
              <Sparkles :size="20" />
              Checklist generation agent
            </h3>
            <p class="muted">
              The agent reuses approved checklists first, then generates only the missing test coverage.
            </p>
            <p class="story-agent-caption">
              Active generator: <span>{{ storiesStore.generatorStatus.current || 'Determining...' }}</span>
            </p>
            <div v-if="currentAgentResult?.reuse_summary" class="story-agent-metrics">
              <span class="story-agent-metric">
                Reused: <strong>{{ currentAgentResult.reuse_summary.reused_items }}</strong>
              </span>
              <span class="story-agent-metric">
                Generated: <strong>{{ currentAgentResult.reuse_summary.generated_items }}</strong>
              </span>
              <span class="story-agent-metric">
                Final: <strong>{{ currentAgentResult.reuse_summary.final_items }}</strong>
              </span>
            </div>
          </div>
          <button
            @click="generateChecklist"
            :disabled="storiesStore.isGenerating"
            class="btn btn-primary"
          >
            {{ storiesStore.isGenerating ? 'Agent running...' : 'Launch agent' }}
          </button>
        </div>
      </div>

      <div v-if="pendingDrafts.length > 0" class="stack stack-gap-sm">
        <div class="story-detail-card-head story-detail-card-head-start">
          <div>
            <h3>Pending chef validation ({{ pendingDrafts.length }})</h3>
            <p class="muted">Agent-generated drafts stay here until a chef reviews and approves them. They are not attached to the story yet.</p>
          </div>
        </div>

        <div
          v-for="draft in pendingDrafts"
          :key="draft.id"
          class="story-attached-card"
        >
          <div class="story-attached-head">
            <div class="story-attached-copy">
              <h4>{{ draft.name }}</h4>
              <p class="muted">{{ draft.description }}</p>
              <p class="story-suggestion-meta">Lifecycle: {{ draft.lifecycle_status }} | Source: {{ draft.generated_from }}</p>
            </div>
            <button
              v-if="auth.isProjectManager"
              @click="editPendingDraft(draft)"
              class="btn btn-secondary btn-sm"
            >
              Review draft
            </button>
            <button
              v-if="auth.isProjectManager"
              @click="approveGeneratedChecklist(draft.id)"
              class="btn btn-primary btn-sm"
            >
              Approve and attach
            </button>
          </div>

          <div class="story-attached-items">
            <div
              v-for="item in draft.items"
              :key="item.id"
              class="story-attached-item"
            >
              <div class="flex-1">
                <p class="story-attached-item-title">{{ item.title }}</p>
                <p v-if="item.description" class="muted">{{ item.description }}</p>
              </div>
              <span v-if="item.criticality" :class="['story-attached-criticality', criticalities[item.criticality] || 'bg-slate-100 text-slate-800']">
                {{ item.criticality }}
              </span>
            </div>
          </div>

          <div class="story-ai-origin">
            <Zap :size="16" />
            Pending validation before attachment
          </div>
        </div>
      </div>

      <div v-if="storiesStore.currentStory.checklists?.length > 0" class="stack stack-gap-sm">
        <div class="story-detail-card-head story-detail-card-head-start">
          <div>
            <h3>Attached checklists ({{ storiesStore.currentStory.checklists.length }})</h3>
            <p class="muted">These checklists are linked to the story. Automated Run Test happens in the project execution workspace.</p>
          </div>
          <RouterLink
            v-if="projectId"
            class="btn btn-secondary btn-sm"
            :to="{ name: 'project-detail', params: { id: projectId } }"
          >
            Open workspace
          </RouterLink>
        </div>

        <div
          v-for="checklist in storiesStore.currentStory.checklists"
          :key="checklist.id"
          class="story-attached-card"
        >
          <div class="story-attached-head">
            <div class="story-attached-copy">
              <h4>{{ checklist.name }}</h4>
              <p class="muted">{{ checklist.description }}</p>
            </div>
            <button
              v-if="auth.isProjectManager"
              @click="detachChecklist(checklist.id)"
              class="btn btn-danger btn-sm"
              title="Detach"
            >
              <Trash2 :size="18" />
            </button>
          </div>

          <div class="story-attached-items">
            <div
              v-for="item in checklist.items"
              :key="item.id"
              class="story-attached-item"
            >
              <div class="flex-1">
                <p class="story-attached-item-title">{{ item.title }}</p>
                <p v-if="item.description" class="muted">{{ item.description }}</p>
              </div>
              <span v-if="item.criticality" :class="['story-attached-criticality', criticalities[item.criticality] || 'bg-slate-100 text-slate-800']">
                {{ item.criticality }}
              </span>
            </div>
          </div>

          <div v-if="checklist.pivot?.is_generated_from_arxis" class="story-ai-origin">
            <Zap :size="16" />
            Generated automatically by AI
          </div>
        </div>
      </div>

      <div v-else class="card empty-dashed-card story-empty-state">
        <AlertCircle :size="32" class="story-empty-icon" />
        <p>No checklist attached yet.</p>
        <p class="muted">Generate a checklist with AI or adapt a suggested template before moving to execution.</p>
      </div>
    </div>

    <div v-else class="card empty-dashed-card story-empty-state">
      <p class="muted">Story not found.</p>
    </div>

    <div v-if="previewOpen" class="fixed inset-0 z-50 bg-slate-950/40 flex items-center justify-center p-4">
      <div class="w-full max-w-5xl bg-white rounded-2xl shadow-2xl border border-slate-200 overflow-hidden">
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-200">
          <div>
            <h3 class="text-xl font-semibold text-slate-900">
              {{
                previewMode === 'manual'
                  ? 'Manual checklist draft'
                  : previewMode === 'draft'
                    ? previewForm.name || 'Draft checklist'
                    : currentPreview?.checklist?.title
              }}
            </h3>
            <p class="text-sm text-slate-500">
              {{
                previewMode === 'suggestion'
                  ? `${currentPreview?.suggestion?.recommendation || 'REVIEW'} | Score ${currentPreview?.suggestion?.score ?? '-'}`
                  : previewMode === 'draft'
                    ? 'Draft checklist review and item customization'
                    : 'Create a manual checklist draft, then approve it later'
              }}
            </p>
          </div>
          <button @click="closePreview" class="px-3 py-2 text-sm rounded-lg border border-slate-200 hover:bg-slate-50">Close</button>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-[320px_minmax(0,1fr)]">
          <aside class="border-r border-slate-200 bg-slate-50/80 p-5 space-y-4">
            <div v-if="previewMode === 'suggestion'">
              <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Coverage</p>
              <div class="mt-2 flex flex-wrap gap-2">
                <span v-for="item in currentPreview?.suggestion?.coverage || []" :key="item" class="px-2 py-1 rounded-full text-xs bg-emerald-100 text-emerald-800">{{ item }}</span>
              </div>
            </div>
            <div v-if="previewMode === 'suggestion'">
              <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Missing</p>
              <div class="mt-2 flex flex-wrap gap-2">
                <span v-for="item in currentPreview?.suggestion?.missing || []" :key="item" class="px-2 py-1 rounded-full text-xs bg-amber-100 text-amber-800">{{ item }}</span>
              </div>
            </div>
            <div class="rounded-xl bg-blue-50 px-4 py-3 text-sm text-blue-800">
              <template v-if="previewMode === 'suggestion'">
              {{ currentPreview?.suggestion?.explanation }}
              </template>
              <template v-else-if="previewMode === 'draft'">
                Edit this draft freely: add items, remove items, or change titles and descriptions before approval.
              </template>
              <template v-else>
                Start from scratch, define the checklist items you want, then approve and attach the draft once ready.
              </template>
            </div>
            <div>
              <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Actions</p>
              <div class="mt-3 space-y-2">
                <button
                  class="w-full px-4 py-3 rounded-xl bg-blue-600 text-white font-medium hover:bg-blue-700 disabled:opacity-50"
                  :disabled="adapting || attaching || previewForm.items.length === 0"
                  @click="adaptSuggestedChecklist"
                >
                  {{
                    adapting
                      ? 'Saving...'
                      : previewMode === 'manual'
                        ? 'SAVE_MANUAL_DRAFT'
                        : previewMode === 'draft'
                          ? 'SAVE_DRAFT_CHANGES'
                          : 'CREATE_DRAFT_FOR_REVIEW'
                  }}
                </button>
              </div>
            </div>
            <p class="text-xs text-slate-500 leading-5">
              Review and editing happen before any attachment. The chef can add or remove items, then approve separately.
            </p>
          </aside>

          <div class="p-6 space-y-5 max-h-[80vh] overflow-auto">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
              <label class="block">
                <span class="text-sm font-medium text-slate-700">Checklist title</span>
                <input v-model="previewForm.name" class="mt-2 w-full rounded-xl border border-slate-300 px-4 py-3" />
              </label>
              <label class="block">
                <span class="text-sm font-medium text-slate-700">Category</span>
                <input v-model="previewForm.category" class="mt-2 w-full rounded-xl border border-slate-300 px-4 py-3" />
              </label>
            </div>

            <label class="block">
              <span class="text-sm font-medium text-slate-700">Description</span>
              <textarea v-model="previewForm.description" rows="3" class="mt-2 w-full rounded-xl border border-slate-300 px-4 py-3"></textarea>
            </label>

            <div class="flex items-center justify-between">
              <h4 class="text-lg font-semibold text-slate-900">Checklist Items</h4>
              <button @click="addPreviewItem" class="px-3 py-2 rounded-lg border border-slate-200 hover:bg-slate-50 text-sm">Add item</button>
            </div>

            <div class="space-y-4">
              <div v-for="(item, index) in previewForm.items" :key="item.id || index" class="rounded-2xl border border-slate-200 p-4 space-y-4">
                <div class="flex items-start justify-between gap-3">
                  <div class="flex-1 grid grid-cols-1 md:grid-cols-2 gap-4">
                    <label class="block md:col-span-2">
                      <span class="text-sm font-medium text-slate-700">Title</span>
                      <input v-model="item.title" class="mt-2 w-full rounded-xl border border-slate-300 px-4 py-3" />
                    </label>
                    <label class="block md:col-span-2">
                      <span class="text-sm font-medium text-slate-700">Description</span>
                      <textarea v-model="item.description" rows="2" class="mt-2 w-full rounded-xl border border-slate-300 px-4 py-3"></textarea>
                    </label>
                    <label class="block">
                      <span class="text-sm font-medium text-slate-700">Priority</span>
                      <select v-model="item.priority" class="mt-2 w-full rounded-xl border border-slate-300 px-4 py-3">
                        <option>Low</option>
                        <option>Medium</option>
                        <option>High</option>
                      </select>
                    </label>
                    <label class="block">
                      <span class="text-sm font-medium text-slate-700">Criticality</span>
                      <select v-model="item.criticality" class="mt-2 w-full rounded-xl border border-slate-300 px-4 py-3">
                        <option>Minor</option>
                        <option>Major</option>
                        <option>Critical</option>
                      </select>
                    </label>
                    <label class="block">
                      <span class="text-sm font-medium text-slate-700">Status</span>
                      <select v-model="item.status" class="mt-2 w-full rounded-xl border border-slate-300 px-4 py-3">
                        <option value="pending">Pending</option>
                        <option value="passed">Passed</option>
                        <option value="failed">Failed</option>
                      </select>
                    </label>
                  </div>
                  <button @click="removePreviewItem(index)" class="btn btn-danger btn-sm" type="button">
                    <Trash2 :size="14" />
                    <span>Delete test case</span>
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

.story-detail-topbar {
  display: flex;
  align-items: center;
  gap: 1rem;
}

.story-detail-topbar-copy {
  flex: 1;
}

.story-detail-topbar-copy h1 {
  margin: 0;
  font-size: clamp(1.9rem, 2.7vw, 2.6rem);
  letter-spacing: -0.04em;
}

.story-detail-back {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 2.7rem;
  height: 2.7rem;
  border-radius: 0.9rem;
  border: 1px solid rgba(148, 163, 184, 0.22);
  background: rgba(255, 255, 255, 0.86);
  transition: background 0.2s ease, border-color 0.2s ease, transform 0.2s ease;
}

.story-detail-back:hover {
  background: #ffffff;
  border-color: rgba(14, 165, 233, 0.35);
  transform: translateY(-1px);
}

.story-detail-hero {
  display: grid;
  grid-template-columns: minmax(0, 1.25fr) auto;
  gap: 1rem;
  padding: 1.5rem 1.6rem;
  border-radius: 1.6rem;
  background:
    radial-gradient(circle at top right, rgba(14, 165, 233, 0.16), transparent 13rem),
    radial-gradient(circle at bottom left, rgba(37, 99, 235, 0.1), transparent 14rem),
    linear-gradient(180deg, rgba(255, 255, 255, 0.98), rgba(248, 250, 252, 0.98));
  border: 1px solid rgba(148, 163, 184, 0.18);
  box-shadow: 0 22px 40px -34px rgba(15, 23, 42, 0.32);
}

.story-detail-kicker {
  margin: 0 0 0.45rem;
  font-size: 0.72rem;
  font-weight: 800;
  letter-spacing: 0.16em;
  text-transform: uppercase;
  color: #0f766e;
}

.story-detail-hero h2 {
  margin: 0;
  font-size: clamp(1.6rem, 2.1vw, 2.3rem);
  letter-spacing: -0.04em;
}

.story-detail-subtitle {
  margin: 0.8rem 0 0;
  max-width: 52rem;
  color: #475569;
  line-height: 1.7;
}

.story-detail-hero-actions {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-start;
  justify-content: flex-end;
  gap: 0.75rem;
}

.story-detail-hero-actions .btn {
  display: inline-flex;
  align-items: center;
  gap: 0.45rem;
}

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

.story-detail-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 1rem;
}

.story-detail-summary-card {
  min-height: 100%;
}

.story-detail-card-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
}

.story-detail-card-head-start {
  align-items: flex-start;
}

.story-detail-card-head h3 {
  margin: 0;
}

.story-priority-pill {
  display: inline-flex;
  align-items: center;
  padding: 0.45rem 0.75rem;
  border-radius: 999px;
  font-size: 0.82rem;
  font-weight: 700;
  border-width: 1px;
}

.story-detail-richtext,
.story-detail-criteria-box {
  margin: 0;
  white-space: pre-wrap;
  line-height: 1.75;
  color: #334155;
}

.story-detail-criteria-box {
  padding: 1rem;
  border-radius: 1rem;
  background: #f8fafc;
  font-family: "JetBrains Mono", "Cascadia Code", Consolas, monospace;
  font-size: 0.92rem;
}

.story-workflow-strip {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.7rem;
  padding: 1rem 1.1rem;
  border-radius: 1.1rem;
  background: rgba(241, 245, 249, 0.9);
  border: 1px solid rgba(148, 163, 184, 0.18);
}

.story-workflow-step {
  display: inline-flex;
  align-items: center;
  gap: 0.55rem;
  padding: 0.55rem 0.8rem;
  border-radius: 999px;
  background: rgba(255, 255, 255, 0.88);
  color: #334155;
  font-weight: 600;
}

.story-workflow-step span {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 1.6rem;
  height: 1.6rem;
  border-radius: 999px;
  background: #dbeafe;
  color: #1d4ed8;
  font-size: 0.8rem;
  font-weight: 800;
}

.story-workflow-step.active {
  background: rgba(219, 234, 254, 0.78);
}

.story-workflow-arrow {
  color: #94a3b8;
}

.story-suggestions-card,
.story-attached-card {
  border: 1px solid rgba(148, 163, 184, 0.16);
}

.story-recommendation-pill {
  display: inline-flex;
  align-items: center;
  padding: 0.5rem 0.8rem;
  border-radius: 999px;
  background: #dbeafe;
  color: #1d4ed8;
  font-size: 0.78rem;
  font-weight: 800;
  letter-spacing: 0.06em;
  text-transform: uppercase;
}

.story-suggestion-card {
  padding: 1rem 1.05rem;
  border-radius: 1.1rem;
  background: linear-gradient(180deg, #ffffff, #f8fafc);
  border: 1px solid rgba(148, 163, 184, 0.16);
}

.story-suggestion-head,
.story-attached-head,
.story-agent-panel-inner {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 1rem;
}

.story-suggestion-copy,
.story-attached-copy {
  flex: 1;
}

.story-suggestion-title,
.story-attached-copy h4 {
  margin: 0;
  font-size: 1rem;
  font-weight: 700;
  color: #0f172a;
}

.story-suggestion-meta {
  margin: 0.35rem 0 0;
  font-size: 0.82rem;
  color: #64748b;
}

.story-suggestion-grid {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 0.6rem;
  margin-top: 0.85rem;
}

.story-suggestion-chip {
  padding: 0.75rem 0.8rem;
  border-radius: 0.95rem;
  font-size: 0.82rem;
  line-height: 1.5;
}

.story-suggestion-chip-good {
  background: #ecfdf5;
  color: #047857;
}

.story-suggestion-chip-warn {
  background: #fffbeb;
  color: #b45309;
}

.story-suggestion-chip-neutral {
  background: #f8fafc;
  color: #334155;
}

.story-agent-panel {
  padding: 1.15rem 1.2rem;
  border-radius: 1.4rem;
  background:
    radial-gradient(circle at top right, rgba(59, 130, 246, 0.16), transparent 11rem),
    linear-gradient(90deg, rgba(239, 246, 255, 0.95), rgba(248, 250, 252, 0.96));
  border: 1px solid rgba(59, 130, 246, 0.18);
}

.story-agent-title {
  display: inline-flex;
  align-items: center;
  gap: 0.55rem;
  margin: 0 0 0.45rem;
}

.story-agent-caption {
  margin: 0.7rem 0 0;
  font-size: 0.82rem;
  color: #64748b;
}

.story-agent-caption span {
  font-weight: 700;
  color: #1d4ed8;
}

.story-agent-metrics {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 0.65rem;
  margin-top: 0.95rem;
}

.story-agent-metric {
  padding: 0.75rem 0.8rem;
  border-radius: 0.9rem;
  background: rgba(255, 255, 255, 0.78);
  color: #334155;
  font-size: 0.84rem;
}

.story-attached-card {
  padding: 1.2rem;
  border-left: 4px solid #2563eb;
  border-radius: 1.2rem;
  background: linear-gradient(180deg, #ffffff, #f8fafc);
}

.story-attached-items {
  display: grid;
  gap: 0.7rem;
  margin-top: 1rem;
}

.story-attached-item {
  display: flex;
  align-items: flex-start;
  gap: 0.75rem;
  padding: 0.9rem;
  border-radius: 1rem;
  background: rgba(248, 250, 252, 0.92);
}

.story-attached-item-title {
  margin: 0 0 0.25rem;
  font-weight: 700;
  color: #0f172a;
}

.story-attached-criticality {
  display: inline-flex;
  align-items: center;
  padding: 0.35rem 0.65rem;
  border-radius: 999px;
  font-size: 0.76rem;
  font-weight: 700;
  white-space: nowrap;
}

.story-ai-origin {
  display: inline-flex;
  align-items: center;
  gap: 0.45rem;
  margin-top: 1rem;
  padding: 0.75rem 0.9rem;
  border-radius: 0.95rem;
  background: #eff6ff;
  color: #1d4ed8;
  font-size: 0.88rem;
  font-weight: 600;
}

.story-empty-state {
  text-align: center;
}

.story-empty-icon {
  margin: 0 auto 0.75rem;
  color: #94a3b8;
}

.dark .story-detail-back,
.dark .story-detail-hero,
.dark .story-detail-metric,
.dark .story-suggestion-card,
.dark .story-attached-card,
.dark .story-workflow-strip,
.dark .story-agent-panel {
  background: rgba(15, 23, 42, 0.92);
  border-color: rgba(51, 65, 85, 0.9);
}

.dark .story-detail-subtitle,
.dark .story-detail-richtext,
.dark .story-suggestion-meta,
.dark .story-agent-caption,
.dark .story-workflow-arrow {
  color: #94a3b8;
}

.dark .story-detail-topbar-copy h1,
.dark .story-detail-hero h2,
.dark .story-detail-metric strong,
.dark .story-suggestion-title,
.dark .story-attached-copy h4,
.dark .story-attached-item-title {
  color: #f8fafc;
}

.dark .story-detail-criteria-box,
.dark .story-workflow-step,
.dark .story-attached-item,
.dark .story-agent-metric {
  background: rgba(15, 23, 42, 0.88);
  color: #cbd5e1;
  border-color: rgba(51, 65, 85, 0.9);
}

@media (max-width: 960px) {
  .story-detail-topbar,
  .story-detail-hero,
  .story-agent-panel-inner,
  .story-suggestion-head,
  .story-attached-head {
    flex-direction: column;
  }

  .story-detail-grid,
  .story-suggestion-grid,
  .story-agent-metrics {
    grid-template-columns: 1fr;
  }

  .story-detail-hero-actions {
    justify-content: flex-start;
  }
}
</style>
