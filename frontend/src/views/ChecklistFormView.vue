<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useChecklistsStore } from '@/stores/checklists'
import { ArrowLeft, ChevronDown, Plus, Trash2, AlertCircle, Zap, BookOpen, FileText, CheckSquare, Grid3x3, Wand2 } from 'lucide-vue-next'

const route = useRoute()
const router = useRouter()
const checklistsStore = useChecklistsStore()

const checklistId = route.params.id
const isEditing = !!checklistId

const form = ref({
  // Section 1: General Info
  name: '',
  description: '',
  category: 'Fonctionnel',
  priority: 'medium',
  
  // Section 2: User Story
  as_a: '',
  i_want_that: '',
  so_that: '',
  acceptance_criteria: '',
  
  // Section 3: Business Rules
  business_rules: [],
  
  // Project & Status
  project_id: null,
  assigned_to: null,
  status: 'ready_for_test',
  
  // Section 4: Scenarios (items)
  items: [{ title: '', description: '', priority: 'High', criticality: 'Major', status: 'pending' }],
})

const expandedSections = ref({
  info: true,
  userStory: true,
  businessRules: false,
  scenarios: true,
})

const submitting = ref(false)
const projects = ref([])
const users = ref([])

const priorityOptions = [
  { value: 'low', label: 'Low' },
  { value: 'medium', label: 'Medium' },
  { value: 'high', label: 'High' },
  { value: 'critical', label: 'Critical' },
]

const itemPriorityOptions = [
  { value: 'Low', label: 'Low' },
  { value: 'Medium', label: 'Medium' },
  { value: 'High', label: 'High' },
]

const criticalityOptions = [
  { value: 'Minor', label: 'Minor' },
  { value: 'Major', label: 'Major' },
  { value: 'Critical', label: 'Critical' },
]

const itemStatusOptions = [
  { value: 'pending', label: 'Pending' },
  { value: 'passed', label: 'Passed' },
  { value: 'failed', label: 'Failed' },
]

const statusOptions = [
  { value: 'in_progress', label: 'In Progress' },
  { value: 'ready_for_test', label: 'Ready for Test' },
  { value: 'completed', label: 'Completed' },
]

const sectionIcons = {
  info: FileText,
  userStory: BookOpen,
  businessRules: Grid3x3,
  scenarios: CheckSquare,
}

async function loadChecklist() {
  if (isEditing) {
    try {
      const checklist = await checklistsStore.fetchChecklist(checklistId)
      form.value = {
        name: checklist.name || '',
        description: checklist.description || '',
        category: checklist.category || 'Fonctionnel',
        priority: checklist.priority || 'medium',
        as_a: checklist.as_a || '',
        i_want_that: checklist.i_want_that || '',
        so_that: checklist.so_that || '',
        acceptance_criteria: checklist.acceptance_criteria || '',
        business_rules: Array.isArray(checklist.business_rules) ? checklist.business_rules : [],
        project_id: checklist.project_id || null,
        assigned_to: checklist.assigned_to ? checklist.assigned_to.id : null,
        status: checklist.status || 'ready_for_test',
        items: checklist.items || [],
      }
    } catch (error) {
      console.error('Failed to load checklist:', error)
    }
  }
}

function toggleSection(section) {
  expandedSections.value[section] = !expandedSections.value[section]
}

function addBusinessRule() {
  form.value.business_rules.push('')
}

function removeBusinessRule(index) {
  form.value.business_rules.splice(index, 1)
}

function addScenario() {
  form.value.items.push({
    title: '',
    description: '',
    priority: 'Medium',
    criticality: 'Major',
    status: 'pending',
  })
}

function removeScenario(index) {
  if (form.value.items.length > 1) {
    form.value.items.splice(index, 1)
  }
}

function generateScenariosWithAI() {
  console.log('Generate scenarios with AI:', {
    name: form.value.name,
    description: form.value.description,
    as_a: form.value.as_a,
    i_want_that: form.value.i_want_that,
    so_that: form.value.so_that,
    acceptance_criteria: form.value.acceptance_criteria,
    business_rules: form.value.business_rules,
  })
  // TODO: Call AI agent to generate scenarios
  // This will be implemented by the modernization agent
}

function validate() {
  checklistsStore.clearErrors()

  if (!form.value.name.trim()) {
    checklistsStore.errors.name = 'Name is required'
  }
  if (!form.value.description.trim()) {
    checklistsStore.errors.description = 'Description is required'
  }
  if (form.value.items.length === 0) {
    checklistsStore.errors.items = 'At least one scenario is required'
  } else {
    for (let i = 0; i < form.value.items.length; i++) {
      if (!form.value.items[i].title.trim()) {
        checklistsStore.errors[`item_${i}_title`] = `Scenario ${i + 1} title is required`
      }
    }
  }

  return Object.keys(checklistsStore.errors).length === 0
}

async function submit() {
  if (!validate()) return

  submitting.value = true

  try {
    const payload = {
      name: form.value.name,
      description: form.value.description,
      category: form.value.category,
      priority: form.value.priority,
      as_a: form.value.as_a || null,
      i_want_that: form.value.i_want_that || null,
      so_that: form.value.so_that || null,
      acceptance_criteria: form.value.acceptance_criteria || null,
      business_rules: form.value.business_rules.filter((r) => r.trim()),
      project_id: form.value.project_id || null,
      assigned_to: form.value.assigned_to || null,
      status: form.value.status,
      items: form.value.items,
    }

    if (isEditing) {
      await checklistsStore.updateChecklist(checklistId, payload)
    } else {
      await checklistsStore.createChecklist(payload)
    }

    router.push({ name: 'checklists' })
  } catch (error) {
    console.error('Error submitting form:', error)
  } finally {
    submitting.value = false
  }
}

function goBack() {
  router.back()
}

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
          <h1>{{ isEditing ? 'Edit Checklist' : 'Create Checklist' }}</h1>
          <p class="muted mt-1">Organize your requirements in 4 sections</p>
        </div>
      </div>
    </div>

    <!-- Alert Errors -->
    <div v-if="Object.keys(checklistsStore.errors).length > 0" class="error">
      <div class="flex items-start gap-3">
        <AlertCircle :size="20" class="flex-shrink-0" />
        <div>
          <strong>Please fix the following errors:</strong>
          <ul class="mt-2 space-y-1">
            <li v-for="(error, key) in checklistsStore.errors" :key="key">• {{ error }}</li>
          </ul>
        </div>
      </div>
    </div>

    <!-- Section 1: General Information -->
    <div class="card stack">
      <button
        @click="expandedSections.info = !expandedSections.info"
        class="flex items-center justify-between w-full text-left mb-4 pb-4 border-b border-gray-200"
      >
        <div class="flex items-center gap-3">
          <FileText :size="20" class="text-blue-600" />
          <h2>General Information</h2>
        </div>
        <ChevronDown :size="20" :class="['transition-transform', expandedSections.info ? 'rotate-180' : '']" />
      </button>

      <div v-show="expandedSections.info" class="space-y-4">
        <div class="grid grid-cols-2 gap-4">
          <div class="field col-span-2">
            <label>Checklist Name *</label>
            <input v-model="form.name" type="text" placeholder="E.g., Login Feature Testing" />
          </div>

          <div class="field col-span-2">
            <label>Description *</label>
            <textarea v-model="form.description" rows="3" placeholder="Describe the purpose of this checklist"></textarea>
          </div>

          <div class="field">
            <label>Category</label>
            <input v-model="form.category" type="text" placeholder="Fonctionnel" />
          </div>

          <div class="field">
            <label>Priority</label>
            <select v-model="form.priority">
              <option v-for="opt in priorityOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
            </select>
          </div>

          <div class="field">
            <label>Status</label>
            <select v-model="form.status">
              <option v-for="opt in statusOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
            </select>
          </div>
        </div>
      </div>
    </div>

    <!-- Section 2: User Story -->
    <div class="card stack">
      <button
        @click="expandedSections.userStory = !expandedSections.userStory"
        class="flex items-center justify-between w-full text-left mb-4 pb-4 border-b border-gray-200"
      >
        <div class="flex items-center gap-3">
          <BookOpen :size="20" class="text-green-600" />
          <h2>User Story</h2>
        </div>
        <ChevronDown :size="20" :class="['transition-transform', expandedSections.userStory ? 'rotate-180' : '']" />
      </button>

      <div v-show="expandedSections.userStory" class="space-y-4">
        <div class="bg-blue-50 border border-blue-200 rounded-lg p-3 text-sm">
          <strong class="text-blue-900 block mb-2">User Story Format (Optional):</strong>
          <p class="text-blue-700 text-xs leading-relaxed">
            Structure: "As a [role], I want [feature], So that [benefit]"
          </p>
        </div>

        <div class="field">
          <label>As A (Role)</label>
          <input v-model="form.as_a" placeholder="E.g., regular user, administrator" />
        </div>

        <div class="field">
          <label>I Want That (Feature)</label>
          <textarea v-model="form.i_want_that" rows="2" placeholder="Describe what the user wants to do"></textarea>
        </div>

        <div class="field">
          <label>So That (Benefit)</label>
          <textarea v-model="form.so_that" rows="2" placeholder="Explain the business value or benefit"></textarea>
        </div>

        <div class="field">
          <label>Acceptance Criteria</label>
          <textarea v-model="form.acceptance_criteria" rows="3" placeholder="List the acceptance criteria or success conditions" class="font-mono text-sm"></textarea>
        </div>
      </div>
    </div>

    <!-- Section 3: Business Rules -->
    <div class="card stack">
      <button
        @click="expandedSections.businessRules = !expandedSections.businessRules"
        class="flex items-center justify-between w-full text-left mb-4 pb-4 border-b border-gray-200"
      >
        <div class="flex items-center gap-3">
          <Grid3x3 :size="20" class="text-purple-600" />
          <h2>Business Rules</h2>
        </div>
        <ChevronDown :size="20" :class="['transition-transform', expandedSections.businessRules ? 'rotate-180' : '']" />
      </button>

      <div v-show="expandedSections.businessRules" class="space-y-2">
        <div v-for="(rule, index) in form.business_rules" :key="index" class="flex gap-2">
          <input
            v-model="form.business_rules[index]"
            type="text"
            placeholder="E.g., Must validate email format"
            class="flex-1 border border-gray-300 rounded-lg px-4 py-3 text-sm bg-white transition-all focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-200"
          />
          <button @click="removeBusinessRule(index)" class="btn btn-danger btn-sm">
            <Trash2 :size="16" />
          </button>
        </div>

        <button @click="addBusinessRule" class="btn btn-secondary w-full">
          <Plus :size="16" />
          Add Business Rule
        </button>
      </div>
    </div>

    <!-- Section 4: Scenarios -->
    <div class="card stack">
      <button
        @click="expandedSections.scenarios = !expandedSections.scenarios"
        class="flex items-center justify-between w-full text-left mb-4 pb-4 border-b border-gray-200"
      >
        <div class="flex items-center gap-3">
          <CheckSquare :size="20" class="text-orange-600" />
          <h2>Test Scenarios</h2>
          <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold bg-blue-100 text-blue-700">
            {{ form.items.length }}
          </span>
        </div>
        <ChevronDown :size="20" :class="['transition-transform', expandedSections.scenarios ? 'rotate-180' : '']" />
      </button>

      <div v-show="expandedSections.scenarios" class="space-y-4">
        <div v-for="(item, index) in form.items" :key="index" class="bg-gray-50 border border-gray-200 rounded-lg p-4">
          <div class="flex items-center justify-between mb-4">
            <h4 class="font-semibold text-gray-900">Scenario {{ index + 1 }}</h4>
            <button v-if="form.items.length > 1" @click="removeScenario(index)" class="btn btn-danger btn-sm">
              <Trash2 :size="16" />
            </button>
          </div>

          <div class="grid grid-cols-2 gap-4">
            <div class="field col-span-2">
              <label>Title *</label>
              <input v-model="form.items[index].title" placeholder="E.g., User enters valid email" />
            </div>

            <div class="field col-span-2">
              <label>Description</label>
              <textarea v-model="form.items[index].description" rows="2" placeholder="Describe this test scenario"></textarea>
            </div>

            <div class="field">
              <label>Priority</label>
              <select v-model="form.items[index].priority">
                <option v-for="opt in itemPriorityOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
              </select>
            </div>

            <div class="field">
              <label>Criticality</label>
              <select v-model="form.items[index].criticality">
                <option v-for="opt in criticalityOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
              </select>
            </div>

            <div class="field">
              <label>Status</label>
              <select v-model="form.items[index].status">
                <option v-for="opt in itemStatusOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
              </select>
            </div>
          </div>
        </div>

        <button @click="addScenario" class="btn btn-secondary w-full">
          <Plus :size="16" />
          Add Scenario
        </button>

        <button @click="generateScenariosWithAI" class="btn btn-primary w-full">
          <Wand2 :size="16" />
          Generate Scenarios with AI
        </button>
      </div>
    </div>

    <!-- Action Buttons -->
    <div class="actions">
      <button @click="goBack" class="btn btn-secondary">
        <ArrowLeft :size="16" />
        Cancel
      </button>
      <button @click="submit" :disabled="submitting" class="btn btn-primary flex-1">
        <Zap v-if="!submitting" :size="16" />
        {{ submitting ? 'Saving...' : isEditing ? 'Update Checklist' : 'Create Checklist' }}
      </button>
    </div>
  </section>
</template>
