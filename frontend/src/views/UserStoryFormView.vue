<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useUserStoriesStore } from '@/stores/userStories'
import { apiRequest } from '@/lib/api'
import {
  ArrowLeft,
  BadgeCheck,
  BookOpenCheck,
  CheckCircle2,
  ClipboardCheck,
  FileText,
  Flag,
  Layers3,
  Link2,
  ListChecks,
  Rocket,
  Sparkles,
} from 'lucide-vue-next'

const route = useRoute()
const router = useRouter()
const storiesStore = useUserStoriesStore()

const projectId = computed(() => route.query.projectId || null)
const storyId = route.params.id
const isEditing = !!storyId

const project = ref(null)
const loadingContext = ref(false)
const errors = ref({})
const submitting = ref(false)

const form = ref({
  story_id: '',
  title: '',
  description: '',
  acceptance_criteria: '',
  status: 'backlog',
  priority: 'medium',
})

const statusOptions = [
  {
    value: 'backlog',
    label: 'Backlog',
    hint: 'A cadrer',
    icon: FileText,
  },
  {
    value: 'in_progress',
    label: 'En Cours',
    hint: 'En préparation',
    icon: Rocket,
  },
  {
    value: 'ready_for_test',
    label: 'Prêt pour Test',
    hint: 'Testable',
    icon: ClipboardCheck,
  },
  {
    value: 'completed',
    label: 'Complété',
    hint: 'Validé',
    icon: CheckCircle2,
  },
]

const priorityOptions = [
  { value: 'low', label: 'Basse', hint: 'Confort', tone: 'emerald' },
  { value: 'medium', label: 'Moyenne', hint: 'Standard', tone: 'blue' },
  { value: 'high', label: 'Haute', hint: 'Important', tone: 'amber' },
  { value: 'critical', label: 'Critique', hint: 'Bloquant', tone: 'rose' },
]

const selectedStatus = computed(() => statusOptions.find((option) => option.value === form.value.status))
const selectedPriority = computed(() => priorityOptions.find((option) => option.value === form.value.priority))

const projectStoriesCount = computed(() => storiesStore.stories.length)
const projectChecklistsCount = computed(() => {
  const direct = project.value?.checklists?.length
  if (typeof direct === 'number') return direct

  const versions = project.value?.versions || []
  return new Set(versions.map((version) => version.checklist?.id).filter(Boolean)).size
})

const acceptanceLines = computed(() => {
  return form.value.acceptance_criteria
    .split('\n')
    .map((line) => line.trim())
    .filter(Boolean)
})

const readiness = computed(() => [
  {
    label: 'Story liée au projet',
    done: Boolean(projectId.value),
  },
  {
    label: 'Description exploitable',
    done: form.value.description.trim().length >= 20,
  },
  {
    label: 'Critères Given-When-Then',
    done: acceptanceLines.value.some((line) => /given.+when.+then/i.test(line)),
  },
  {
    label: 'Priorité définie',
    done: Boolean(form.value.priority),
  },
])

const readyCount = computed(() => readiness.value.filter((item) => item.done).length)

async function loadContext() {
  if (!projectId.value) {
    return
  }

  loadingContext.value = true

  try {
    const [projectData] = await Promise.all([
      apiRequest(`/projects/${projectId.value}`),
      storiesStore.fetchStories(projectId.value),
    ])

    project.value = projectData
  } catch {
    project.value = null
  } finally {
    loadingContext.value = false
  }
}

async function loadStory() {
  if (isEditing && projectId.value) {
    const story = await storiesStore.fetchStory(projectId.value, storyId)
    if (story) {
      form.value = {
        story_id: story.story_id || '',
        title: story.title || '',
        description: story.description || '',
        acceptance_criteria: story.acceptance_criteria || '',
        status: story.status || 'backlog',
        priority: story.priority || 'medium',
      }
    }
  }
}

function validate() {
  errors.value = {}

  if (!projectId.value) {
    errors.value.project = 'Aucun projet sélectionné. Ouvrez la création depuis un projet.'
  }
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

function applyTemplate() {
  if (!form.value.description.trim()) {
    form.value.description = 'En tant que [type utilisateur], je veux [objectif] afin de [valeur métier].'
  }

  if (!form.value.acceptance_criteria.trim()) {
    form.value.acceptance_criteria = [
      'Given [contexte initial], When [action utilisateur], Then [résultat attendu]',
      'Given [cas invalide ou limite], When [action utilisateur], Then [message ou comportement attendu]',
      'Given [droits ou données nécessaires], When [exécution du scénario], Then [traçabilité ou état final attendu]',
    ].join('\n')
  }
}

async function submit() {
  if (!validate()) return

  submitting.value = true

  try {
    const payload = {
      ...form.value,
      story_id: form.value.story_id || null,
    }

    const story = isEditing
      ? await storiesStore.updateStory(projectId.value, storyId, payload)
      : await storiesStore.createStory(projectId.value, payload)

    router.push({
      name: 'story-detail',
      params: { id: story?.id || storyId },
      query: { projectId: projectId.value },
    })
  } catch (err) {
    errors.value.submit = err.message
  } finally {
    submitting.value = false
  }
}

function goBack() {
  if (projectId.value) {
    router.push({ name: 'stories', query: { projectId: projectId.value } })
    return
  }

  router.back()
}

onMounted(async () => {
  await Promise.all([loadContext(), loadStory()])
})
</script>

<template>
  <div class="story-form-shell">
    <div class="story-form-header">
      <button @click="goBack" class="story-back-button" type="button" aria-label="Retour">
        <ArrowLeft :size="20" />
      </button>

      <div class="story-header-copy">
        <span class="story-eyebrow">User Story Workspace</span>
        <h1>{{ isEditing ? 'Éditer la user story' : 'Nouvelle user story' }}</h1>
        <p>
          Reliez le besoin métier au projet, préparez les critères de test, puis ouvrez directement la génération de checklist.
        </p>
      </div>

      <button type="button" class="story-template-button" @click="applyTemplate">
        <Sparkles :size="17" />
        Modèle testable
      </button>
    </div>

    <div v-if="errors.project" class="story-alert story-alert-danger">
      {{ errors.project }}
    </div>

    <div v-if="errors.submit" class="story-alert story-alert-danger">
      {{ errors.submit }}
    </div>

    <div class="story-form-grid">
      <form @submit.prevent="submit" class="story-main-panel">
        <section class="story-section">
          <div class="story-section-title">
            <BookOpenCheck :size="18" />
            <div>
              <h2>Contexte fonctionnel</h2>
              <p>Un titre clair et une référence rendent la story facile à retrouver dans le projet.</p>
            </div>
          </div>

          <div class="story-two-columns">
            <label class="story-field">
              <span>Référence</span>
              <input
                v-model="form.story_id"
                type="text"
                placeholder="US-LOGIN-01"
              />
            </label>

            <label class="story-field">
              <span>Titre de la story *</span>
              <input
                v-model="form.title"
                type="text"
                placeholder="Connexion avec email et mot de passe"
                :class="{ 'is-invalid': errors.title }"
              />
              <small v-if="errors.title">{{ errors.title }}</small>
            </label>
          </div>

          <label class="story-field">
            <span>Description *</span>
            <textarea
              v-model="form.description"
              rows="5"
              placeholder="En tant que client, je veux me connecter avec mon email afin d'accéder à mon espace personnel."
              :class="{ 'is-invalid': errors.description }"
            ></textarea>
            <small v-if="errors.description">{{ errors.description }}</small>
          </label>
        </section>

        <section class="story-section">
          <div class="story-section-title">
            <ListChecks :size="18" />
            <div>
              <h2>Critères d'acceptation</h2>
              <p>Ces lignes alimentent les suggestions de checklists et l'agent de génération.</p>
            </div>
          </div>

          <label class="story-field">
            <span>Scénarios Given-When-Then *</span>
            <textarea
              v-model="form.acceptance_criteria"
              rows="7"
              class="story-mono"
              placeholder="Given valid credentials, When user submits the login form, Then dashboard loads&#10;Given invalid credentials, When user submits the form, Then an error message is displayed"
              :class="{ 'is-invalid': errors.acceptance_criteria }"
            ></textarea>
            <small v-if="errors.acceptance_criteria">{{ errors.acceptance_criteria }}</small>
          </label>

          <div class="story-criteria-preview">
            <span>{{ acceptanceLines.length }} scénario{{ acceptanceLines.length > 1 ? 's' : '' }}</span>
            <span>{{ readyCount }}/{{ readiness.length }} points prêts pour test</span>
          </div>
        </section>

        <section class="story-section">
          <div class="story-section-title">
            <Flag :size="18" />
            <div>
              <h2>Workflow QA</h2>
              <p>Le statut et la priorité pilotent la visibilité côté testeur.</p>
            </div>
          </div>

          <div class="story-option-block">
            <span class="story-option-label">Statut</span>
            <div class="story-status-grid">
              <button
                v-for="option in statusOptions"
                :key="option.value"
                type="button"
                :class="['story-choice-card', { active: form.status === option.value }]"
                @click="form.status = option.value"
              >
                <component :is="option.icon" :size="18" />
                <strong>{{ option.label }}</strong>
                <small>{{ option.hint }}</small>
              </button>
            </div>
          </div>

          <div class="story-option-block">
            <span class="story-option-label">Priorité</span>
            <div class="story-priority-grid">
              <button
                v-for="option in priorityOptions"
                :key="option.value"
                type="button"
                :class="['story-priority-pill', `tone-${option.tone}`, { active: form.priority === option.value }]"
                @click="form.priority = option.value"
              >
                <span>{{ option.label }}</span>
                <small>{{ option.hint }}</small>
              </button>
            </div>
          </div>
        </section>

        <div class="story-actions">
          <button @click="goBack" type="button" class="story-secondary-button">
            Annuler
          </button>
          <button type="submit" :disabled="submitting" class="story-primary-button">
            <BadgeCheck :size="18" />
            {{ submitting ? 'Enregistrement...' : isEditing ? 'Mettre à jour et ouvrir' : 'Créer et ouvrir' }}
          </button>
        </div>
      </form>

      <aside class="story-side-panel">
        <section class="story-context-card">
          <span class="story-eyebrow">Projet lié</span>
          <h2>{{ loadingContext ? 'Chargement...' : project?.name || 'Projet sélectionné' }}</h2>
          <p>{{ project?.description || 'La story sera attachée au projet actif et visible dans son tableau de stories.' }}</p>

          <div class="story-context-stats">
            <div>
              <strong>{{ projectStoriesCount }}</strong>
              <span>Stories</span>
            </div>
            <div>
              <strong>{{ projectChecklistsCount }}</strong>
              <span>Checklists</span>
            </div>
          </div>
        </section>

        <section class="story-link-card">
          <div class="story-link-row">
            <Layers3 :size="18" />
            <div>
              <strong>Chaîne de traçabilité</strong>
              <span>Projet → User story → Checklist → Items de test</span>
            </div>
          </div>
          <div class="story-link-row">
            <Link2 :size="18" />
            <div>
              <strong>Après création</strong>
              <span>Ouverture directe de la story pour lancer l'agent ou attacher une checklist.</span>
            </div>
          </div>
        </section>

        <section class="story-readiness-card">
          <div class="story-readiness-head">
            <ClipboardCheck :size="18" />
            <strong>Qualité testable</strong>
          </div>

          <ul>
            <li v-for="item in readiness" :key="item.label" :class="{ done: item.done }">
              <CheckCircle2 :size="16" />
              <span>{{ item.label }}</span>
            </li>
          </ul>
        </section>

        <section class="story-summary-card">
          <span class="story-eyebrow">Résumé</span>
          <h3>{{ form.title || 'Titre à définir' }}</h3>
          <div class="story-summary-meta">
            <span>{{ selectedStatus?.label }}</span>
            <span>{{ selectedPriority?.label }}</span>
          </div>
        </section>
      </aside>
    </div>
  </div>
</template>

<style scoped>
.story-form-shell {
  max-width: 1180px;
  margin: 0 auto;
  padding: 8px 0 48px;
}

.story-form-header {
  display: grid;
  grid-template-columns: auto minmax(0, 1fr) auto;
  gap: 18px;
  align-items: center;
  margin-bottom: 24px;
}

.story-back-button,
.story-template-button,
.story-secondary-button,
.story-primary-button,
.story-choice-card,
.story-priority-pill {
  border: 0;
  font: inherit;
  cursor: pointer;
}

.story-back-button {
  width: 42px;
  height: 42px;
  display: grid;
  place-items: center;
  color: #0f172a;
  background: #ffffff;
  border: 1px solid #d8e0ec;
  border-radius: 8px;
  box-shadow: 0 10px 22px rgba(15, 23, 42, 0.06);
}

.story-header-copy h1 {
  margin: 4px 0 4px;
  font-size: clamp(2rem, 4vw, 3.35rem);
  line-height: 1;
  color: #09111f;
}

.story-header-copy p,
.story-section-title p,
.story-context-card p,
.story-link-row span {
  color: #5c6b82;
}

.story-eyebrow {
  display: inline-flex;
  align-items: center;
  color: #2458ff;
  font-size: 0.78rem;
  font-weight: 800;
  letter-spacing: 0;
  text-transform: uppercase;
}

.story-template-button {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  min-height: 44px;
  padding: 0 16px;
  color: #1740b8;
  background: #eef4ff;
  border: 1px solid #c9d9ff;
  border-radius: 8px;
  font-weight: 800;
}

.story-alert {
  margin-bottom: 16px;
  padding: 14px 16px;
  border-radius: 8px;
  font-weight: 700;
}

.story-alert-danger {
  color: #b42318;
  background: #fff1f0;
  border: 1px solid #ffd6d2;
}

.story-form-grid {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 340px;
  gap: 22px;
  align-items: start;
}

.story-main-panel,
.story-context-card,
.story-link-card,
.story-readiness-card,
.story-summary-card {
  background: #ffffff;
  border: 1px solid #dfe6f0;
  border-radius: 8px;
  box-shadow: 0 18px 44px rgba(15, 23, 42, 0.07);
}

.story-main-panel {
  padding: 24px;
}

.story-section {
  padding: 0 0 24px;
  margin-bottom: 24px;
  border-bottom: 1px solid #e6ebf2;
}

.story-section-title {
  display: flex;
  gap: 12px;
  align-items: flex-start;
  margin-bottom: 18px;
  color: #1b55e7;
}

.story-section-title h2 {
  margin: 0 0 3px;
  color: #0f172a;
  font-size: 1.1rem;
}

.story-section-title p {
  margin: 0;
  font-size: 0.9rem;
}

.story-two-columns {
  display: grid;
  grid-template-columns: 210px minmax(0, 1fr);
  gap: 14px;
}

.story-field {
  display: grid;
  gap: 8px;
  margin-bottom: 18px;
}

.story-field span,
.story-option-label {
  color: #1f2a3d;
  font-size: 0.92rem;
  font-weight: 800;
}

.story-field input,
.story-field textarea {
  width: 100%;
  color: #101828;
  background: #fbfdff;
  border: 1px solid #cfd8e5;
  border-radius: 8px;
  outline: none;
  padding: 13px 15px;
  transition: border-color 150ms ease, box-shadow 150ms ease, background 150ms ease;
}

.story-field textarea {
  resize: vertical;
}

.story-field input:focus,
.story-field textarea:focus {
  background: #ffffff;
  border-color: #2f6bff;
  box-shadow: 0 0 0 4px rgba(47, 107, 255, 0.12);
}

.story-field .is-invalid {
  border-color: #f04438;
}

.story-field small {
  color: #d92d20;
  font-weight: 700;
}

.story-mono {
  font-family: "JetBrains Mono", "Cascadia Code", Consolas, monospace;
  font-size: 0.9rem;
}

.story-criteria-preview {
  display: flex;
  gap: 10px;
  flex-wrap: wrap;
}

.story-criteria-preview span {
  display: inline-flex;
  min-height: 34px;
  align-items: center;
  padding: 0 12px;
  color: #26415f;
  background: #f4f7fb;
  border: 1px solid #dfe6f0;
  border-radius: 999px;
  font-size: 0.84rem;
  font-weight: 800;
}

.story-option-block {
  display: grid;
  gap: 10px;
  margin-bottom: 20px;
}

.story-status-grid,
.story-priority-grid {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 10px;
}

.story-choice-card {
  min-height: 92px;
  display: grid;
  gap: 5px;
  justify-items: start;
  align-content: center;
  padding: 14px;
  color: #344054;
  background: #f8fafc;
  border: 1px solid #d8e0ec;
  border-radius: 8px;
  text-align: left;
}

.story-choice-card small,
.story-priority-pill small {
  color: #697586;
  font-weight: 700;
}

.story-choice-card.active {
  color: #1740b8;
  background: #eef4ff;
  border-color: #356dff;
  box-shadow: inset 0 0 0 1px #356dff;
}

.story-priority-pill {
  display: grid;
  gap: 2px;
  min-height: 66px;
  padding: 12px;
  text-align: left;
  color: #344054;
  background: #ffffff;
  border: 1px solid #d8e0ec;
  border-radius: 8px;
}

.story-priority-pill span {
  font-weight: 900;
}

.story-priority-pill.active {
  border-width: 2px;
}

.story-priority-pill.tone-emerald.active {
  color: #067647;
  border-color: #32d583;
  background: #ecfdf3;
}

.story-priority-pill.tone-blue.active {
  color: #1740b8;
  border-color: #528bff;
  background: #eff5ff;
}

.story-priority-pill.tone-amber.active {
  color: #93370d;
  border-color: #fdb022;
  background: #fffaeb;
}

.story-priority-pill.tone-rose.active {
  color: #b42318;
  border-color: #f97066;
  background: #fff1f0;
}

.story-actions {
  display: flex;
  justify-content: flex-end;
  gap: 12px;
}

.story-secondary-button,
.story-primary-button {
  min-height: 46px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  padding: 0 18px;
  border-radius: 8px;
  font-weight: 900;
}

.story-secondary-button {
  color: #344054;
  background: #ffffff;
  border: 1px solid #cfd8e5;
}

.story-primary-button {
  color: #ffffff;
  background: #1f5eff;
  box-shadow: 0 14px 26px rgba(31, 94, 255, 0.24);
}

.story-primary-button:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

.story-side-panel {
  display: grid;
  gap: 14px;
  position: sticky;
  top: 96px;
}

.story-context-card,
.story-link-card,
.story-readiness-card,
.story-summary-card {
  padding: 18px;
}

.story-context-card h2,
.story-summary-card h3 {
  margin: 6px 0 8px;
  color: #101828;
}

.story-context-stats {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 10px;
  margin-top: 16px;
}

.story-context-stats div {
  padding: 12px;
  background: #f6f9fd;
  border: 1px solid #e0e7f1;
  border-radius: 8px;
}

.story-context-stats strong {
  display: block;
  color: #101828;
  font-size: 1.6rem;
  line-height: 1;
}

.story-context-stats span,
.story-summary-meta span {
  color: #61708a;
  font-size: 0.82rem;
  font-weight: 800;
}

.story-link-card {
  display: grid;
  gap: 14px;
}

.story-link-row {
  display: grid;
  grid-template-columns: auto minmax(0, 1fr);
  gap: 10px;
  color: #1f5eff;
}

.story-link-row strong,
.story-link-row span {
  display: block;
}

.story-link-row strong {
  color: #101828;
}

.story-readiness-head {
  display: flex;
  align-items: center;
  gap: 8px;
  color: #101828;
  margin-bottom: 12px;
}

.story-readiness-card ul {
  display: grid;
  gap: 10px;
  margin: 0;
  padding: 0;
  list-style: none;
}

.story-readiness-card li {
  display: flex;
  align-items: center;
  gap: 9px;
  color: #7a879a;
  font-weight: 750;
}

.story-readiness-card li.done {
  color: #087443;
}

.story-summary-meta {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
}

.story-summary-meta span {
  padding: 7px 10px;
  background: #f4f7fb;
  border-radius: 999px;
}

@media (max-width: 1080px) {
  .story-form-grid {
    grid-template-columns: 1fr;
  }

  .story-side-panel {
    position: static;
  }
}

@media (max-width: 720px) {
  .story-form-header {
    grid-template-columns: auto minmax(0, 1fr);
  }

  .story-template-button {
    grid-column: 1 / -1;
    justify-content: center;
  }

  .story-two-columns,
  .story-status-grid,
  .story-priority-grid {
    grid-template-columns: 1fr;
  }

  .story-main-panel {
    padding: 18px;
  }

  .story-actions {
    flex-direction: column-reverse;
  }

  .story-secondary-button,
  .story-primary-button {
    width: 100%;
  }
}
</style>
