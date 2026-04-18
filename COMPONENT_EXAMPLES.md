# Settings System - Component Examples

This document shows how to update existing components to use language and dark mode support.

## Example 1: User Stories List Component

### Before (without translations/dark mode):
```vue
<template>
  <div class="container">
    <h1 class="text-gray-900">User Stories</h1>
    <button class="bg-white">Create New Story</button>
    
    <div class="bg-white">
      <div class="text-gray-600">Status</div>
    </div>
  </div>
</template>
```

### After (with translations and dark mode):
```vue
<script setup>
import { useI18n } from '@/composables/useI18n'

const { t } = useI18n()
</script>

<template>
  <div class="container">
    <!-- Dark mode: bg changes, text color inverts -->
    <h1 class="text-gray-900 dark:text-white">
      {{ t('stories.title') }}
    </h1>

    <!-- Dark mode button -->
    <button class="bg-blue-600 dark:bg-blue-700 text-white hover:dark:bg-blue-800">
      {{ t('stories.new') }}
    </button>
    
    <!-- Dark mode card -->
    <div class="bg-white dark:bg-gray-800 rounded-lg">
      <div class="text-gray-600 dark:text-gray-400">
        {{ t('stories.status') }}
      </div>
    </div>
  </div>
</template>
```

## Example 2: Dashboard with RTL Support

### Before:
```vue
<template>
  <div class="flex justify-between">
    <div class="flex gap-4">
      <div>Completed</div>
      <div>Pending</div>
    </div>
  </div>
</template>
```

### After (with RTL support):
```vue
<script setup>
import { useI18n } from '@/composables/useI18n'

const { t, isRTL } = useI18n()
</script>

<template>
  <!-- Add dir binding for RTL -->
  <div :dir="isRTL ? 'rtl' : 'ltr'" class="flex justify-between">
    <div class="flex gap-4">
      <div class="dark:text-gray-300">{{ t('checklists.completed') }}</div>
      <div class="dark:text-gray-300">{{ t('checklists.pending') }}</div>
    </div>
  </div>
</template>
```

## Example 3: Dialog/Modal Component

### Before:
```vue
<template>
  <div class="modal-overlay">
    <div class="modal-content bg-white">
      <h2>Confirm Delete</h2>
      <p>Are you sure?</p>
      <button class="bg-red-600">Delete</button>
      <button class="bg-gray-300">Cancel</button>
    </div>
  </div>
</template>
```

### After (with translations and dark mode):
```vue
<script setup>
import { useI18n } from '@/composables/useI18n'

const { t } = useI18n()
</script>

<template>
  <!-- Dark mode overlay -->
  <div class="modal-overlay dark:bg-black/50">
    <!-- Dark mode modal content -->
    <div class="modal-content bg-white dark:bg-gray-800">
      <h2 class="text-gray-900 dark:text-white">{{ t('msg.confirmDelete') }}</h2>
      <p class="text-gray-600 dark:text-gray-400">{{ t('stories.delete') }}</p>
      
      <div class="flex gap-2">
        <button class="bg-red-600 dark:bg-red-700 text-white hover:dark:bg-red-800">
          {{ t('common.delete') }}
        </button>
        <button class="bg-gray-300 dark:bg-gray-600 dark:text-white hover:dark:bg-gray-700">
          {{ t('common.cancel') }}
        </button>
      </div>
    </div>
  </div>
</template>
```

## Example 4: Form Component

### Before:
```vue
<template>
  <form>
    <label>Story Title</label>
    <input type="text" class="border border-gray-300" />
    
    <label>Description</label>
    <textarea class="border border-gray-300"></textarea>
    
    <button class="bg-blue-600">Save</button>
  </form>
</template>
```

### After (with translations and dark mode):
```vue
<script setup>
import { useI18n } from '@/composables/useI18n'

const { t } = useI18n()
</script>

<template>
  <form class="space-y-4">
    <!-- Label with dark mode -->
    <div>
      <label class="block text-gray-700 dark:text-gray-300 font-medium mb-2">
        {{ t('stories.title') }}
      </label>
      <!-- Input with dark mode -->
      <input 
        type="text" 
        class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded"
      />
    </div>
    
    <div>
      <label class="block text-gray-700 dark:text-gray-300 font-medium mb-2">
        {{ t('stories.description') }}
      </label>
      <textarea 
        class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded"
      ></textarea>
    </div>
    
    <!-- Button with dark mode -->
    <button 
      class="px-4 py-2 bg-blue-600 dark:bg-blue-700 text-white rounded hover:bg-blue-700 dark:hover:bg-blue-800 transition"
    >
      {{ t('common.save') }}
    </button>
  </form>
</template>

<style scoped>
/* Optional: Add transition for smoother dark mode switching */
input,
textarea,
button {
  @apply transition-colors duration-200;
}
</style>
```

## Example 5: Table Component

### Before:
```vue
<template>
  <table class="w-full border-collapse">
    <thead>
      <tr class="bg-gray-100">
        <th>Name</th>
        <th>Status</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>
      <tr class="border-b">
        <td>Item 1</td>
        <td>Active</td>
        <td>
          <button>Edit</button>
          <button>Delete</button>
        </td>
      </tr>
    </tbody>
  </table>
</template>
```

### After (with translations and dark mode):
```vue
<script setup>
import { useI18n } from '@/composables/useI18n'

const { t } = useI18n()
</script>

<template>
  <div class="overflow-x-auto">
    <table class="w-full border-collapse">
      <!-- Dark mode table header -->
      <thead>
        <tr class="bg-gray-100 dark:bg-gray-700">
          <th class="px-4 py-2 text-left text-gray-900 dark:text-white font-semibold">
            Name
          </th>
          <th class="px-4 py-2 text-left text-gray-900 dark:text-white font-semibold">
            {{ t('stories.status') }}
          </th>
          <th class="px-4 py-2 text-left text-gray-900 dark:text-white font-semibold">
            {{ t('common.edit') }}
          </th>
        </tr>
      </thead>
      
      <!-- Dark mode table body -->
      <tbody>
        <tr class="border-b border-gray-200 dark:border-gray-600 hover:bg-gray-50 dark:hover:bg-gray-700 transition">
          <td class="px-4 py-2 text-gray-900 dark:text-white">Item 1</td>
          <td class="px-4 py-2 text-gray-600 dark:text-gray-400">Active</td>
          <td class="px-4 py-2">
            <button class="text-blue-600 dark:text-blue-400 hover:underline mr-2">
              {{ t('common.edit') }}
            </button>
            <button class="text-red-600 dark:text-red-400 hover:underline">
              {{ t('common.delete') }}
            </button>
          </td>
        </tr>
      </tbody>
    </table>
  </div>
</template>
```

## Example 6: Navigation Link Component

### Before:
```vue
<template>
  <nav class="bg-white shadow">
    <div class="flex gap-4">
      <a href="/dashboard" class="px-4 py-2 text-gray-700">Dashboard</a>
      <a href="/projects" class="px-4 py-2 text-gray-700">Projects</a>
      <a href="/checklists" class="px-4 py-2 text-gray-700">Checklists</a>
    </div>
  </nav>
</template>
```

### After (with translations and dark mode):
```vue
<script setup>
import { useI18n } from '@/composables/useI18n'
import { useRouter } from 'vue-router'

const { t, isRTL } = useI18n()
const router = useRouter()
</script>

<template>
  <!-- Dark mode nav with RTL support -->
  <nav 
    :dir="isRTL ? 'rtl' : 'ltr'" 
    class="bg-white dark:bg-gray-800 shadow"
  >
    <div class="flex gap-4">
      <!-- Dark mode nav links -->
      <a 
        href="/dashboard" 
        class="px-4 py-2 text-gray-700 dark:text-gray-300 hover:text-gray-900 dark:hover:text-white transition"
      >
        {{ t('nav.dashboard') }}
      </a>
      <a 
        href="/projects" 
        class="px-4 py-2 text-gray-700 dark:text-gray-300 hover:text-gray-900 dark:hover:text-white transition"
      >
        {{ t('nav.projects') }}
      </a>
      <a 
        href="/checklists" 
        class="px-4 py-2 text-gray-700 dark:text-gray-300 hover:text-gray-900 dark:hover:text-white transition"
      >
        {{ t('nav.checklists') }}
      </a>
    </div>
  </nav>
</template>
```

## Example 7: Card/Panel Component

### Before:
```vue
<template>
  <div class="bg-white rounded-lg p-6 shadow">
    <h3 class="text-lg font-bold text-gray-900">Card Title</h3>
    <p class="text-gray-600 mt-2">This is card content</p>
  </div>
</template>
```

### After (with dark mode):
```vue
<script setup>
import { useI18n } from '@/composables/useI18n'

const { t } = useI18n()
</script>

<template>
  <!-- Dark mode card -->
  <div class="bg-white dark:bg-gray-800 rounded-lg p-6 shadow dark:shadow-lg dark:shadow-black/20">
    <h3 class="text-lg font-bold text-gray-900 dark:text-white">
      {{ t('stories.generate') }}
    </h3>
    <p class="text-gray-600 dark:text-gray-400 mt-2">
      {{ t('stories.generating') }}
    </p>
  </div>
</template>

<style scoped>
/* Smooth transition for theme switching */
div {
  @apply transition-colors duration-200;
}
</style>
```

## Common Dark Mode Patterns

### Text Colors
```
Light Mode          → Dark Mode
text-gray-900       → dark:text-white
text-gray-700       → dark:text-gray-300
text-gray-600       → dark:text-gray-400
text-gray-500       → dark:text-gray-500
```

### Background Colors
```
Light Mode          → Dark Mode
bg-white            → dark:bg-gray-800
bg-gray-50          → dark:bg-gray-900
bg-gray-100         → dark:bg-gray-700
bg-gray-200         → dark:bg-gray-600
```

### Border Colors
```
Light Mode          → Dark Mode
border-gray-300     → dark:border-gray-600
border-gray-200     → dark:border-gray-700
border-blue-300     → dark:border-blue-600
```

### Interactive Elements
```
Light Mode          → Dark Mode
bg-blue-600         → dark:bg-blue-700
hover:bg-blue-700   → dark:hover:bg-blue-800
text-blue-600       → dark:text-blue-400
```

## Quick Reference: Theme Builder

### Button (Primary)
```vue
<button class="bg-blue-600 dark:bg-blue-700 text-white rounded hover:bg-blue-700 dark:hover:bg-blue-800 transition px-4 py-2">
  {{ t('common.save') }}
</button>
```

### Button (Secondary)
```vue
<button class="bg-gray-200 dark:bg-gray-700 text-gray-900 dark:text-white rounded hover:bg-gray-300 dark:hover:bg-gray-600 transition px-4 py-2">
  {{ t('common.cancel') }}
</button>
```

### Alert Box
```vue
<div class="bg-amber-50 dark:bg-amber-900/20 border border-amber-300 dark:border-amber-700 rounded p-4">
  <p class="text-amber-800 dark:text-amber-200">{{ t('msg.error') }}</p>
</div>
```

### Input Field
```vue
<input 
  class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-900 dark:text-white rounded"
  placeholder="Search..."
/>
```

## Integration Checklist per Component

- [ ] Import `useI18n` from `@/composables/useI18n`
- [ ] Add `const { t, isRTL } = useI18n()`
- [ ] Replace hardcoded text with `{{ t('key.name') }}`
- [ ] Add `dark:` prefixed classes to all elements
- [ ] Test in both light and dark modes
- [ ] Test in English, French, and Arabic
- [ ] Add `dir` binding to parent div if RTL support needed
- [ ] Verify colors are accessible in both themes

## Tailwind Dark Mode Utilities

### Hiding/Showing Elements
```vue
<!-- Show in light mode only -->
<div class="hidden dark:block">Dark mode content</div>

<!-- Show in dark mode only -->
<div class="block dark:hidden">Light mode content</div>
```

### Spacing Adjustments
```vue
<!-- Different padding for light/dark -->
<div class="p-4 dark:p-6">Content</div>
```

### Border Styles
```vue
<!-- Different border styles for light/dark -->
<div class="border border-gray-300 dark:border-gray-600">Content</div>
```

## Performance Tips

1. **Use `@apply`** for common patterns in scoped styles
2. **Avoid** dynamic class strings that can't be purged
3. **Memoize** expensive computed properties
4. **Use `transition-colors`** for smooth theme switching
5. **Batch** DOM updates when changing theme

## Testing Your Components

### Light Mode Testing
```bash
# Default - should show light colors
npm run dev
```

### Dark Mode Testing
```bash
# In browser console:
document.documentElement.classList.add('dark')
# And to toggle off:
document.documentElement.classList.remove('dark')
```

### Language Testing
```vue
<!-- In browser console: -->
localStorage.setItem('app_settings', JSON.stringify({ language: 'ar', darkMode: false }))
// Reload page
```
