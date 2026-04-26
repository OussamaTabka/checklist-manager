<script setup>
import { onMounted, ref, computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useChecklistsStore } from '@/stores/checklists'
import { ArrowLeft, Clock, CheckCircle, AlertTriangle, Square, AlertCircle, Edit, Trash2, BookOpen, FileText, Grid3x3, CheckSquare } from 'lucide-vue-next'

const route = useRoute()
const router = useRouter()
const checklistsStore = useChecklistsStore()

const checklistId = route.params.id
const expandedItems = ref({})
const showItemHistory = ref({})
const selectedItemId = ref(null)

const statusIcons = {
  'Not Tested': { icon: Square, color: 'text-gray-500', label: 'Not Tested' },
  'Passed': { icon: CheckCircle, color: 'text-green-600', label: 'Passed' },
  'Failed': { icon: AlertTriangle, color: 'text-red-600', label: 'Failed' },
  'Blocked': { icon: AlertCircle, color: 'text-orange-600', label: 'Blocked' },
}

const fallbackItemStatus = { icon: Square, color: 'text-gray-500', label: 'Not Tested' }

const criticalityColors = {
  'Low': 'bg-green-100 text-green-800',
  'Medium': 'bg-yellow-100 text-yellow-800',
  'High': 'bg-orange-100 text-orange-800',
  'Critical': 'bg-red-100 text-red-800',
}

function getItemStatusMeta(status) {
  return statusIcons[status] || fallbackItemStatus
}

function getItemCriticalityClass(criticality) {
  return criticalityColors[criticality] || 'bg-gray-100 text-gray-700'
}

function formatChecklistPriority(priority) {
  if (!priority) return 'Unknown'
  const value = String(priority)
  return value.charAt(0).toUpperCase() + value.slice(1)
}

function formatChecklistStatus(status) {
  if (!status) return 'Unknown'
  const value = String(status).replaceAll('_', ' ')
  return value.charAt(0).toUpperCase() + value.slice(1)
}

function getChecklistPriorityTagClass(priority) {
  if (priority === 'critical') return 'text-red-900 border-red-300 bg-red-100'
  if (priority === 'high') return 'text-orange-900 border-orange-300 bg-orange-100'
  if (priority === 'medium') return 'text-yellow-900 border-yellow-300 bg-yellow-100'
  if (priority === 'low') return 'text-green-900 border-green-300 bg-green-100'
  return 'text-gray-900 border-gray-300 bg-gray-100'
}

function getScenarioStatusTagClass(status) {
  if (status === 'Passed') return 'text-green-900 border-green-300 bg-green-100'
  if (status === 'Failed') return 'text-red-900 border-red-300 bg-red-100'
  if (status === 'Blocked') return 'text-orange-900 border-orange-300 bg-orange-100'
  return 'text-gray-900 border-gray-300 bg-gray-100'
}

async function loadChecklist() {
  try {
    await checklistsStore.fetchChecklist(checklistId)
  } catch (error) {
    console.error('Failed to load checklist:', error)
  }
}

async function toggleItemHistory(itemId) {
  if (showItemHistory.value[itemId]) {
    showItemHistory.value[itemId] = false
  } else {
    try {
      const history = await checklistsStore.getItemHistory(checklistId, itemId)
      const item = checklistsStore.currentChecklist?.items?.find(i => i.id === itemId)
      if (item) {
        item.history = history
      }
      showItemHistory.value[itemId] = true
    } catch (error) {
      console.error('Failed to load history:', error)
    }
  }
}

async function updateItemStatus(itemId, newStatus) {
  try {
    await checklistsStore.updateItemStatus(checklistId, itemId, newStatus)
  } catch (error) {
    console.error('Failed to update status:', error)
  }
}

function editChecklist() {
  router.push({
    name: 'checklist-edit',
    params: { id: checklistId },
  })
}

async function deleteChecklist() {
  if (!confirm('Are you sure you want to delete this checklist? This action cannot be undone.')) return

  try {
    await checklistsStore.deleteChecklist(checklistId)
    router.push({ name: 'checklists' })
  } catch (error) {
    console.error('Failed to delete checklist:', error)
  }
}

function goBack() {
  router.back()
}

function toggleItem(itemId) {
  expandedItems.value[itemId] = !expandedItems.value[itemId]
}

const checklist = computed(() => checklistsStore.currentChecklist)
const scenariosStats = computed(() => {
  const items = Array.isArray(checklist.value?.items) ? checklist.value.items : []
  if (!items.length) return { total: 0, passed: 0, failed: 0, blocked: 0, notTested: 0 }

  const stats = { total: items.length, passed: 0, failed: 0, blocked: 0, notTested: 0 }
  items.forEach(item => {
    if (item.status === 'Passed') stats.passed++
    else if (item.status === 'Failed') stats.failed++
    else if (item.status === 'Blocked') stats.blocked++
    else stats.notTested++
  })
  return stats
})

onMounted(() => {
  loadChecklist()
})
</script>

<template>
  <section class="page stack">
    <!-- Header -->
    <div class="section-header">
      <div class="flex items-center gap-4">
        <button @click="goBack" class="p-2 hover:bg-gray-100 rounded-lg transition">
          <ArrowLeft :size="20" />
        </button>
        <div>
          <h1>{{ checklist?.name || 'Loading...' }}</h1>
          <p class="muted">Checklist #{{ checklistId }}</p>
        </div>
      </div>
      <div class="flex gap-2">
        <button @click="editChecklist" class="btn btn-primary">
          <Edit :size="16" />
          Edit
        </button>
        <button @click="deleteChecklist" class="btn btn-danger">
          <Trash2 :size="16" />
          Delete
        </button>
      </div>
    </div>

    <!-- Loading State -->
    <div v-if="checklistsStore.loading" class="flex justify-center py-12">
      <div class="animate-spin rounded-full h-8 w-8 border border-blue-500 border-t-transparent"></div>
    </div>

    <!-- Checklist Content -->
    <div v-else-if="checklist" class="stack">
      <!-- General Info Section -->
      <div class="card stack">
        <div class="flex items-center gap-3 pb-4 border-b border-gray-200 mb-4">
          <FileText :size="20" class="text-blue-600" />
          <h2>General Information</h2>
        </div>

        <div class="grid grid-cols-[1fr_1fr_1fr] gap-4">
          <div>
            <p class="muted mb-1">Priority</p>
            <span :class="['tag', getChecklistPriorityTagClass(checklist.priority)]">
              {{ formatChecklistPriority(checklist.priority) }}
            </span>
          </div>

          <div>
            <p class="muted mb-1">Status</p>
            <span class="tag text-blue-900 border-blue-300 bg-blue-100">
              {{ formatChecklistStatus(checklist.status) }}
            </span>
          </div>

          <div>
            <p class="muted mb-1">Scenarios</p>
            <span class="tag text-gray-900 border-gray-300 bg-gray-100">
              {{ scenariosStats.total }} Total
            </span>
          </div>
        </div>

        <div class="mt-4">
          <p class="muted mb-2">Description</p>
          <p class="text-gray-700">{{ checklist.description }}</p>
        </div>
      </div>

      <!-- User Story Section -->
      <div v-if="checklist.as_a || checklist.i_want_that || checklist.so_that" class="card stack">
        <div class="flex items-center gap-3 pb-4 border-b border-gray-200 mb-4">
          <BookOpen :size="20" class="text-green-600" />
          <h2>User Story</h2>
        </div>

        <div class="space-y-3">
          <div v-if="checklist.as_a">
            <p class="muted mb-1">As A (Role)</p>
            <p class="text-gray-700">{{ checklist.as_a }}</p>
          </div>
          <div v-if="checklist.i_want_that">
            <p class="muted mb-1">I Want That (Feature)</p>
            <p class="text-gray-700 whitespace-pre-wrap">{{ checklist.i_want_that }}</p>
          </div>
          <div v-if="checklist.so_that">
            <p class="muted mb-1">So That (Benefit)</p>
            <p class="text-gray-700 whitespace-pre-wrap">{{ checklist.so_that }}</p>
          </div>
          <div v-if="checklist.acceptance_criteria" class="mt-4 pt-4 border-t border-gray-200">
            <p class="muted mb-2">Acceptance Criteria</p>
            <div class="bg-gray-50 border border-gray-200 p-3 rounded font-mono text-sm whitespace-pre-wrap text-gray-700">
              {{ checklist.acceptance_criteria }}
            </div>
          </div>
        </div>
      </div>

      <!-- Business Rules Section -->
      <div v-if="checklist.business_rules && checklist.business_rules.length > 0" class="card stack">
        <div class="flex items-center gap-3 pb-4 border-b border-gray-200 mb-4">
          <Grid3x3 :size="20" class="text-purple-600" />
          <h2>Business Rules</h2>
        </div>

        <ul class="space-y-2">
          <li v-for="(rule, index) in checklist.business_rules" :key="index" class="flex items-start gap-3 text-gray-700">
            <span class="inline-block w-1.5 h-1.5 bg-blue-600 rounded-full mt-2 flex-shrink-0"></span>
            {{ rule }}
          </li>
        </ul>
      </div>

      <!-- Scenarios Section -->
      <div class="card">
        <div class="pb-6 border-b border-gray-200 mb-6">
          <div class="flex items-center gap-3 mb-4">
            <CheckSquare :size="20" class="text-orange-600" />
            <h2>Test Scenarios</h2>
            <span class="tag text-blue-900 border-blue-300 bg-blue-100">{{ scenariosStats.total }}</span>
          </div>

          <div class="grid grid-cols-[repeat(auto-fit,minmax(120px,1fr))] gap-3 text-sm">
            <div class="bg-white border border-gray-200 rounded p-2 text-center">
              <p class="muted text-xs mb-1">Total</p>
              <p class="font-bold text-gray-900">{{ scenariosStats.total }}</p>
            </div>
            <div class="bg-green-50 border border-green-200 rounded p-2 text-center">
              <p class="text-green-700 text-xs font-medium mb-1">Passed</p>
              <p class="font-bold text-green-900">{{ scenariosStats.passed }}</p>
            </div>
            <div class="bg-red-50 border border-red-200 rounded p-2 text-center">
              <p class="text-red-700 text-xs font-medium mb-1">Failed</p>
              <p class="font-bold text-red-900">{{ scenariosStats.failed }}</p>
            </div>
            <div class="bg-orange-50 border border-orange-200 rounded p-2 text-center">
              <p class="text-orange-700 text-xs font-medium mb-1">Blocked</p>
              <p class="font-bold text-orange-900">{{ scenariosStats.blocked }}</p>
            </div>
            <div class="bg-gray-50 border border-gray-200 rounded p-2 text-center">
              <p class="muted text-xs mb-1">Not Tested</p>
              <p class="font-bold text-gray-900">{{ scenariosStats.notTested }}</p>
            </div>
          </div>
        </div>

        <div v-if="checklist.items && checklist.items.length > 0" class="space-y-3">
          <div v-for="(item, index) in checklist.items" :key="item.id" class="bg-gray-50 border border-gray-200 rounded-lg overflow-hidden">
            <!-- Item Header -->
            <button
              @click="toggleItem(item.id)"
              class="w-full px-4 py-3 flex items-center justify-between hover:bg-gray-100 transition"
            >
              <div class="flex items-center gap-3 flex-1 text-left">
                <component :is="getItemStatusMeta(item.status).icon" :size="20" :class="getItemStatusMeta(item.status).color" />
                <div>
                  <p class="font-semibold text-gray-900">{{ item.title }}</p>
                  <p v-if="item.description" class="text-sm text-gray-600">{{ item.description }}</p>
                </div>
              </div>
              <div class="flex items-center gap-2 ml-4">
                <span :class="['tag text-xs', getItemCriticalityClass(item.criticality)]">
                  {{ item.criticality }}
                </span>
                <span :class="['tag text-xs', getScenarioStatusTagClass(item.status)]">
                  {{ item.status }}
                </span>
              </div>
            </button>

            <!-- Item Details (Expanded) -->
            <div v-if="expandedItems[item.id]" class="bg-white border-t border-gray-200 px-4 py-4 space-y-4">
              <!-- Status Update -->
              <div>
                <p class="text-sm font-bold uppercase tracking-wider text-gray-700 mb-3">Update Status</p>
                <div class="flex gap-2 flex-wrap">
                  <button
                    v-for="status in ['Not Tested', 'Passed', 'Failed', 'Blocked']"
                    :key="status"
                    @click="updateItemStatus(item.id, status)"
                    :class="[
                      'btn btn-sm',
                      item.status === status ? 'btn-primary' : 'btn-secondary'
                    ]"
                  >
                    {{ status }}
                  </button>
                </div>
                <p v-if="item.tested_at" class="text-xs text-gray-600 mt-3">
                  <Clock :size="14" class="inline mr-1" />
                  Last tested: {{ new Date(item.tested_at).toLocaleString() }}
                </p>
              </div>

              <!-- History -->
              <div class="border-t border-gray-200 pt-4">
                <button
                  @click="toggleItemHistory(item.id)"
                  class="flex items-center gap-2 text-sm font-bold text-blue-600 hover:text-blue-700 uppercase tracking-wider"
                >
                  <Clock :size="16" />
                  {{ showItemHistory[item.id] ? 'Hide History' : 'Show History' }}
                </button>

                <div v-if="showItemHistory[item.id] && item.history" class="mt-3 space-y-2 max-h-80 overflow-y-auto">
                  <div
                    v-for="change in item.history"
                    :key="change.id"
                    class="bg-gray-50 border border-gray-200 p-3 rounded text-sm"
                  >
                    <div class="flex justify-between items-start mb-2">
                      <p class="font-bold text-gray-900">{{ change.changed_by?.name }}</p>
                      <span class="text-xs text-gray-600">{{ new Date(change.created_at).toLocaleString() }}</span>
                    </div>
                    <p class="text-gray-700 text-sm">
                      <strong class="text-blue-600">{{ change.field_name }}:</strong> {{ change.old_value }} → {{ change.new_value }}
                    </p>
                    <p v-if="change.notes" class="text-gray-600 text-xs mt-2 italic">💬 {{ change.notes }}</p>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <div v-else class="text-center py-12">
          <AlertCircle :size="32" class="mx-auto text-gray-400 mb-3" />
          <p class="text-gray-600 muted">No scenarios added yet</p>
        </div>
      </div>
    </div>

    <!-- Error State -->
    <div v-else class="card text-center py-12">
      <p class="muted">Checklist not found</p>
    </div>
  </section>
</template>
