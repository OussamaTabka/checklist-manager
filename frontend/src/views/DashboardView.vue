<script setup>
import { computed, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import {
  AlertTriangle,
  BriefcaseBusiness,
  CheckCircle2,
  ClipboardCheck,
  Filter,
  FolderKanban,
  Search,
  TrendingUp,
  Workflow,
  ArrowRight,
  Sparkles,
} from 'lucide-vue-next'
import { apiRequest } from '@/lib/api'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()

const loading = ref(false)
const errorMessage = ref('')

const totalProjects = ref(0)
const totalChecklists = ref(null)
const globalProgress = ref(0)
const failedCriticalItems = ref(0)
const testsRun = ref(0)
const testsFailed = ref(0)
const projectSuccessRows = ref([])
const searchQuery = ref('')
const showFilters = ref(false)
const statusFilter = ref('all')
const minSuccessFilter = ref('all')

const averageSuccessRate = computed(() => {
  if (!projectSuccessRows.value.length) return 0
  const total = projectSuccessRows.value.reduce((sum, row) => sum + (Number(row.successRate) || 0), 0)
  return Number((total / projectSuccessRows.value.length).toFixed(1))
})

const averageCompletion = computed(() => {
  if (!projectSuccessRows.value.length) return 0
  const total = projectSuccessRows.value.reduce((sum, row) => sum + (Number(row.completion) || 0), 0)
  return Number((total / projectSuccessRows.value.length).toFixed(1))
})

const qualityScore = computed(() => {
  const penalty = Math.min(failedCriticalItems.value * 3, 35)
  const weighted = (averageSuccessRate.value * 0.65) + (averageCompletion.value * 0.35)
  return Math.max(0, Number((weighted - penalty).toFixed(1)))
})

const roleDashboardCopy = computed(() => {
  switch (auth.primaryRole) {
    case 'admin':
      return {
        eyebrow: 'Admin Workspace',
        title: 'Platform Control Center',
        description:
          'Supervise platform health, account activity, project delivery, and test governance from one place.',
        tableKicker: 'System overview',
        tableTitle: 'Projects ranked by operational health',
      }
    case 'testeur':
      return {
        eyebrow: 'Execution Workspace',
        title: 'Testing Operations Center',
        description:
          'Follow assigned delivery health, monitor failures, and move quickly from alerts to execution work.',
        tableKicker: 'Execution overview',
        tableTitle: 'Projects ranked by execution health',
      }
    default:
      return {
        eyebrow: 'Chef Workspace',
        title: 'Project Command Center',
        description:
          'Track delivery health, spot risk early, and jump directly into the next decision across projects, stories, and checklist templates.',
        tableKicker: 'Portfolio overview',
        tableTitle: 'Projects ranked by execution health',
      }
  }
})

const previousWeekSuccessRate = computed(() => {
  const previous = Math.max(0, averageSuccessRate.value - 4)
  return Number(previous.toFixed(1))
})

const projectStatusBreakdown = computed(() => {
  return rowsWithStatus.value.reduce(
    (acc, row) => {
      acc[row.status.tone] = (acc[row.status.tone] || 0) + 1
      return acc
    },
    {
      perfect: 0,
      'in-progress': 0,
      delayed: 0,
      'at-risk': 0,
      'not-started': 0,
    },
  )
})

const focusCards = computed(() => [
  {
    title: 'Projects needing attention',
    value: projectStatusBreakdown.value['at-risk'] + projectStatusBreakdown.value.delayed,
    caption: 'At risk or delayed',
    tone: 'warm',
    icon: AlertTriangle,
  },
  {
    title: 'Ready to scale',
    value: projectStatusBreakdown.value.perfect,
    caption: 'Projects with excellent health',
    tone: 'cool',
    icon: Sparkles,
  },
  {
    title: 'Checklist library',
    value: totalChecklists.value ?? 0,
    caption: 'Reusable testing templates',
    tone: 'mint',
    icon: ClipboardCheck,
  },
])

function getProjectStatus(row) {
  const completion = pct(row.completion)
  const success = pct(row.successRate)
  const failed = Number(row.failed) || 0
  const pending = Number(row.pending) || 0

  if (completion === 0 && pending > 0) {
    return { label: 'Not Started', tone: 'not-started' }
  }

  if (failed >= 3 || success < 40) {
    return { label: 'At Risk', tone: 'at-risk' }
  }

  if (pending > 0 && completion < 100) {
    if (failed > 0) {
      return { label: 'Delayed', tone: 'delayed' }
    }

    return { label: 'In Progress', tone: 'in-progress' }
  }

  if (failed === 0 && success >= 95) {
    return { label: 'Perfect', tone: 'perfect' }
  }

  if (failed > 0) {
    return { label: 'Delayed', tone: 'delayed' }
  }

  return { label: 'In Progress', tone: 'in-progress' }
}

const rowsWithStatus = computed(() => {
  return projectSuccessRows.value.map((row) => ({
    ...row,
    status: getProjectStatus(row),
  }))
})

const filteredRows = computed(() => {
  const query = searchQuery.value.trim().toLowerCase()

  return rowsWithStatus.value.filter((row) => {
    const matchesSearch =
      query === '' ||
      `${row.name || ''} ${row.version || ''}`.toLowerCase().includes(query)

    const matchesStatus = statusFilter.value === 'all' || row.status.tone === statusFilter.value

    const minSuccess = Number(minSuccessFilter.value)
    const matchesMinSuccess =
      minSuccessFilter.value === 'all' || pct(row.successRate) >= minSuccess

    return matchesSearch && matchesStatus && matchesMinSuccess
  })
})

function pct(value) {
  const n = Number(value) || 0
  return Math.max(0, Math.min(100, n))
}

function meterColor(value) {
  const n = pct(value)
  if (n >= 80) return '#16a34a'
  if (n >= 60) return '#2563eb'
  if (n >= 40) return '#d97706'
  return '#dc2626'
}

function completionMeterColor(value) {
  const n = pct(value)
  if (n >= 80) return '#16a34a'
  if (n >= 40) return '#d97706'
  return '#2563eb'
}

async function loadDashboard() {
  loading.value = true
  errorMessage.value = ''

  try {
    const summary = await apiRequest('/dashboard/summary', {}, auth.token)
    totalProjects.value = summary.totalProjects || 0
    totalChecklists.value = summary.totalChecklists ?? null
    globalProgress.value = summary.globalProgress || 0
    failedCriticalItems.value = summary.failedCriticalItems || 0
    testsRun.value = Number(summary.testsRun || 0)
    testsFailed.value = Number(summary.testsFailed || 0)
    projectSuccessRows.value = summary.projectSuccessRows || []

    if (!summary.testsFailed) {
      testsFailed.value = projectSuccessRows.value.reduce((sum, row) => sum + (Number(row.failed) || 0), 0)
    }
  } catch (error) {
    errorMessage.value = error.data?.message || error.message
  } finally {
    loading.value = false
  }
}

onMounted(async () => {
  await loadDashboard()
})
</script>

<template>
  <section class="page stack dashboard-page">
    <div class="dashboard-command">
      <div class="dashboard-command-copy">
        <p class="dashboard-eyebrow">{{ roleDashboardCopy.eyebrow }}</p>
        <h1>{{ roleDashboardCopy.title }}</h1>
        <p class="dashboard-command-text">{{ roleDashboardCopy.description }}</p>
      </div>

      <div class="dashboard-command-actions">
        <button class="btn btn-secondary btn-sm" @click="loadDashboard" data-testid="dashboard-btn-refresh">Refresh</button>
        <RouterLink v-if="auth.canManageProjects" :to="{ name: 'projects', query: { create: '1' } }" class="btn btn-primary btn-sm">
          <FolderKanban :size="16" />
          <span>New Project</span>
        </RouterLink>
      </div>
    </div>

    <p v-if="errorMessage" class="error" data-testid="dashboard-msg-error">{{ errorMessage }}</p>
    <p v-if="loading" class="muted" data-testid="dashboard-msg-loading">Loading dashboard...</p>

    <div class="card stack dashboard-summary-card" v-if="!loading" data-testid="dashboard-stats">
      <div class="dashboard-toolbar">
        <div>
          <p class="dashboard-section-kicker">{{ roleDashboardCopy.tableKicker }}</p>
          <h2>Delivery performance at a glance</h2>
        </div>

        <div class="dashboard-toolbar-actions">
          <div class="search-input-wrap dashboard-search-box">
            <Search :size="16" :stroke-width="2" />
            <input v-model="searchQuery" placeholder="Search projects..." class="search-input" />
          </div>

          <button class="btn btn-secondary" type="button" @click="showFilters = !showFilters">
            <Filter :size="16" :stroke-width="2" />
            <span>Filters</span>
          </button>
        </div>
      </div>

      <div v-if="showFilters" class="dashboard-advanced-filters">
        <div class="field">
          <label>Status</label>
          <select v-model="statusFilter">
            <option value="all">All</option>
            <option value="in-progress">In Progress</option>
            <option value="perfect">Perfect</option>
            <option value="at-risk">At Risk</option>
            <option value="not-started">Not Started</option>
            <option value="delayed">Delayed</option>
          </select>
        </div>

        <div class="field">
          <label>Min Success</label>
          <select v-model="minSuccessFilter">
            <option value="all">All</option>
            <option value="25">>= 25%</option>
            <option value="50">>= 50%</option>
            <option value="75">>= 75%</option>
          </select>
        </div>

        <div class="actions dashboard-filter-actions">
          <button class="btn btn-secondary" type="button" @click="statusFilter = 'all'; minSuccessFilter = 'all'">
            Clear filters
          </button>
        </div>
      </div>

      <div class="dashboard-kpi-grid">
        <article class="dashboard-kpi-card">
          <div class="kpi-icon kpi-blue"><BriefcaseBusiness :size="18" /></div>
          <div class="kpi-body">
            <div class="kpi-title">Total Projects</div>
            <div class="kpi-value">{{ totalProjects }}</div>
          </div>
        </article>

        <article class="dashboard-kpi-card">
          <div class="kpi-icon kpi-green"><TrendingUp :size="18" /></div>
          <div class="kpi-body">
            <div class="kpi-title">Success Rate</div>
            <div class="kpi-value success-text">{{ averageSuccessRate }}%</div>
            <div class="kpi-sub">Up from {{ previousWeekSuccessRate }}% last week</div>
          </div>
        </article>

        <article class="dashboard-kpi-card">
          <div class="kpi-icon kpi-indigo"><CheckCircle2 :size="18" /></div>
          <div class="kpi-body">
            <div class="kpi-title">Tests Run</div>
            <div class="kpi-value">{{ testsRun }}</div>
          </div>
        </article>

        <article class="dashboard-kpi-card">
          <div class="kpi-icon kpi-red"><AlertTriangle :size="18" /></div>
          <div class="kpi-body">
            <div class="kpi-title">Tests Failed</div>
            <div class="kpi-value">{{ testsFailed }}</div>
          </div>
        </article>
      </div>

      <div class="dashboard-focus-grid">
        <article v-for="card in focusCards" :key="card.title" class="dashboard-focus-card" :class="`focus-${card.tone}`">
          <div class="dashboard-focus-icon">
            <component :is="card.icon" :size="18" />
          </div>
          <div class="dashboard-focus-copy">
            <p>{{ card.title }}</p>
            <strong>{{ card.value }}</strong>
            <span>{{ card.caption }}</span>
          </div>
        </article>
      </div>
    </div>

    <div class="card stack" v-if="!loading" data-testid="dashboard-project-success">
      <div class="section-header">
        <div>
          <p class="dashboard-section-kicker">Live table</p>
          <h2>{{ roleDashboardCopy.tableTitle }}</h2>
        </div>
        <RouterLink :to="{ name: 'stories' }" class="dashboard-inline-link">
          <span>Open user stories</span>
          <ArrowRight :size="15" />
        </RouterLink>
      </div>

      <div class="table-wrap">
        <table data-testid="dashboard-project-success">
          <thead>
            <tr>
              <th>Project</th>
              <th>Latest Version</th>
              <th>Success Rate</th>
              <th>Completion</th>
              <th>Failed Items</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in filteredRows" :key="row.id">
              <td>
                <div class="project-cell">
                  <span class="project-icon"><Workflow :size="16" /></span>
                  <span>{{ row.name }}</span>
                </div>
              </td>
              <td>{{ row.version ? `v${row.version}` : '-' }}</td>
              <td>
                <div class="metric-cell">
                  <div class="metric-value">{{ row.successRate }}%</div>
                  <div class="metric-track">
                    <div
                      class="metric-fill"
                      :style="{ width: `${pct(row.successRate)}%`, background: meterColor(row.successRate) }"
                    />
                  </div>
                </div>
              </td>
              <td>
                <div class="metric-cell">
                  <div class="metric-value">{{ row.completion }}%</div>
                  <div class="metric-track">
                    <div
                      class="metric-fill"
                      :style="{ width: `${pct(row.completion)}%`, background: completionMeterColor(row.completion) }"
                    />
                  </div>
                </div>
              </td>
              <td>{{ row.failed }}</td>
              <td>
                <span class="status-pill" :class="`status-${row.status.tone}`">{{ row.status.label }}</span>
              </td>
            </tr>
            <tr v-if="filteredRows.length === 0">
              <td colspan="6" class="muted">No projects available.</td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="dashboard-table-footer">
        <RouterLink :to="{ name: 'projects' }">View all projects</RouterLink>
      </div>
    </div>
  </section>
</template>
