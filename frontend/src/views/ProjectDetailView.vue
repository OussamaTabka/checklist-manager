<script setup>
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import { ClipboardList, ExternalLink, Layers3 } from 'lucide-vue-next'
import { apiRequest } from '@/lib/api'
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

const storyStatusLabels = {
  backlog: 'Backlog',
  in_progress: 'In Progress',
  ready_for_test: 'Ready for Test',
  completed: 'Completed',
}

const isAdminReadonly = auth.isSystemAdmin && !auth.isProjectManager && !auth.isTester

function toggleStoryMenu(storyId) {
  openStoryMenuId.value = openStoryMenuId.value === storyId ? null : storyId
}

function closeStoryMenu() {
  openStoryMenuId.value = null
}

function handleWindowClick() {
  closeStoryMenu()
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

async function archiveStory(story) {
  closeStoryMenu()
  actionError.value = ''
  actionSuccess.value = ''

  if (!confirm(tr('confirm_archive_story', { title: story.title }, settings.language))) {
    return
  }

  try {
    await storiesStore.deleteStory(route.params.id, story.id)
    actionSuccess.value = localizeError({ message: 'La user story a ete archivee avec succes.' }, 'error_generic', settings.language)
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
          <p class="project-execution-kicker">{{ isAdminReadonly ? 'Project overview' : 'Project workspace' }}</p>
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
        </div>

        <div class="project-execution-side">
          <div class="project-execution-app-card">
            <span class="project-execution-side-label">Target application</span>
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
            <p class="muted">Objectives: {{ project.test_objectives || 'No test objectives defined yet.' }}</p>
            <p class="muted">{{ project.description || 'No project description defined yet.' }}</p>
          </div>

        </div>
      </div>

      <div class="card stack">
        <div class="section-header">
          <div>
            <h3>User stories</h3>
            <p class="muted">Review user stories linked to this project.</p>
          </div>
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
                      <button type="button" class="story-row-menu-item danger" @click="archiveStory(story)">
                        Archiver
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

  </section>
</template>

<style>
.project-execution-hero {
  display: grid;
  grid-template-columns: minmax(0, 1.35fr) minmax(280px, 0.95fr);
  gap: 1.5rem;
  padding: 1.6rem 1.7rem;
  border-radius: 1.6rem;
  background:
    radial-gradient(circle at top right, rgba(14, 165, 233, 0.16), transparent 14rem),
    radial-gradient(circle at bottom left, rgba(16, 185, 129, 0.12), transparent 14rem),
    linear-gradient(180deg, rgba(255, 255, 255, 0.98), rgba(248, 250, 252, 0.98));
  border: 1px solid rgba(148, 163, 184, 0.2);
  box-shadow: 0 22px 40px -34px rgba(15, 23, 42, 0.38);
}

.project-execution-kicker {
  margin: 0 0 0.55rem;
  font-size: 0.72rem;
  font-weight: 800;
  letter-spacing: 0.18em;
  text-transform: uppercase;
  color: #0f766e;
}

.project-execution-copy h1 {
  margin: 0;
  font-size: clamp(2rem, 3vw, 2.9rem);
  line-height: 1;
  letter-spacing: -0.05em;
}

.project-execution-subtitle {
  max-width: 56rem;
  margin: 0.85rem 0 1.15rem;
  font-size: 1rem;
  line-height: 1.7;
  color: #475569;
}

.project-meta-pills {
  display: flex;
  flex-wrap: wrap;
  gap: 0.7rem;
}

.project-meta-pill {
  display: inline-flex;
  align-items: center;
  gap: 0.45rem;
  padding: 0.6rem 0.85rem;
  border-radius: 999px;
  background: rgba(255, 255, 255, 0.82);
  border: 1px solid rgba(148, 163, 184, 0.22);
  color: #0f172a;
  font-weight: 600;
}

.project-execution-side {
  display: flex;
  flex-direction: column;
  gap: 1rem;
}

.project-execution-app-card {
  padding: 1rem 1.1rem;
  border-radius: 1.2rem;
  background: rgba(255, 255, 255, 0.78);
  border: 1px solid rgba(148, 163, 184, 0.18);
}

.project-execution-side-label {
  display: block;
  margin-bottom: 0.45rem;
  font-size: 0.72rem;
  font-weight: 800;
  letter-spacing: 0.12em;
  text-transform: uppercase;
  color: #64748b;
}

.project-app-link {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  font-weight: 700;
  color: #0f172a;
  text-decoration: none;
  word-break: break-word;
}

.project-app-link:hover {
  color: #0f766e;
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
.dark .project-execution-side-label {
  color: #94a3b8;
}

.dark .project-meta-pill,
.dark .project-execution-app-card,
.dark .story-menu-trigger,
.dark .story-row-menu {
  background: rgba(15, 23, 42, 0.9);
  border-color: rgba(51, 65, 85, 0.9);
  color: #e2e8f0;
}

.dark .project-app-link,
.dark .story-row-link {
  color: #f8fafc;
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

.dark .story-row-menu-item.danger {
  color: #fca5a5;
}

@media (max-width: 960px) {
  .project-execution-hero {
    grid-template-columns: 1fr;
  }
}
</style>
