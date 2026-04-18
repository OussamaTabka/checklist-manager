# Settings System - Quick Integration

This document shows exactly what to do to enable language and dark mode support in your app.

## Step 1: Initialize Settings Store in App.vue

Add this to App.vue's onMounted:

```js
onMounted(async () => {
  const settingsStore = useSettingsStore()
  settingsStore.loadSettings()  // ← ADD THIS LINE
  
  document.addEventListener('click', handleDocumentClick)
  // ... rest of onMounted
})
```

**Import at top of App.vue script:**
```js
import { useSettingsStore } from '@/stores/settings'
```

## Step 2: Add Settings Menu to Header (Optional)

To add a quick settings menu to the header, insert this in App.vue template before the profile section:

**After the notifications-wrap div, add:**

```vue
<SettingsMenu />
```

**Import the component:**
```js
import SettingsMenu from '@/components/SettingsMenu.vue'
```

Location in template (around line 458):
```vue
<div class="topbar-right">
  <!-- Notifications (existing) -->
  <div class="notifications-wrap" ref="notificationsRef">
    <!-- ... existing code ... -->
  </div>

  <!-- ADD SETTINGS MENU HERE -->
  <SettingsMenu />

  <!-- Profile menu (existing) -->
  <div class="profile-wrap" ref="profileMenuRef">
    <!-- ... existing code ... -->
  </div>
</div>
```

## Step 3: Update Settings Navigation Item

The existing "Settings" in the profile menu (line 463) currently calls `goToSettings()` which goes to Users page.

Update it to go to the Settings page instead:

**Current:**
```js
async function goToSettings() {
  showProfileMenu.value = false
  if (auth.canManageUsers) {
    await router.push({ name: 'users' })
    return
  }
  await router.push({ name: 'dashboard' })
}
```

**Update to:**
```js
async function goToSettings() {
  showProfileMenu.value = false
  await router.push({ name: 'settings' })  // Changed this line
}
```

## Step 4: Use Translations in Components

For any component that needs translated text:

```vue
<script setup>
import { useI18n } from '@/composables/useI18n'
const { t } = useI18n()
</script>

<template>
  <h1>{{ t('nav.dashboard') }}</h1>
  <button>{{ t('common.save') }}</button>
</template>
```

## Step 5: Apply Dark Mode Classes

Update components with dark mode support:

```vue
<!-- Example: Add dark: prefixes to existing classes -->
<div class="bg-white dark:bg-gray-900">
  <h1 class="text-gray-900 dark:text-white">Title</h1>
  <button class="bg-blue-600 dark:bg-blue-700">Button</button>
</div>
```

## Complete Example Integration

### In App.vue script setup:

```vue
<script setup>
import { onMounted, onBeforeUnmount } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { useSettingsStore } from '@/stores/settings'  // ← ADD
import LogoHeader from '@/components/LogoHeader.vue'
import SettingsMenu from '@/components/SettingsMenu.vue'  // ← ADD

const auth = useAuthStore()
const settingsStore = useSettingsStore()  // ← ADD

// ... existing code ...

onMounted(async () => {
  settingsStore.loadSettings()  // ← ADD THIS
  
  document.addEventListener('click', handleDocumentClick)
  // ... rest of existing onMounted
})
</script>
```

### In App.vue template topbar-right:

```vue
<div class="topbar-right">
  <div class="notifications-wrap" ref="notificationsRef">
    <!-- notifications code -->
  </div>

  <SettingsMenu />  <!-- ← ADD THIS -->

  <div class="profile-wrap" ref="profileMenuRef">
    <!-- profile code -->
  </div>
</div>
```

## Files Created

✅ **Store**
- `frontend/src/stores/settings.js` - Language and dark mode state management

✅ **Components**
- `frontend/src/components/SettingsMenu.vue` - Dropdown menu for quick access
- `frontend/src/views/SettingsView.vue` - Full settings page at `/settings`

✅ **Utilities**
- `frontend/src/lib/translations.js` - All UI text in 3 languages (FR, EN, AR)
- `frontend/src/composables/useI18n.js` - Translation composable

✅ **Configuration**
- `frontend/tailwind.config.js` - Updated with dark mode support
- `frontend/src/router/index.js` - Added /settings route

✅ **Documentation**
- `SETTINGS_GUIDE.md` - Complete guide with examples

## What's Supported

### Languages
- 🇫🇷 French (Français)
- 🇬🇧 English
- 🇸🇦 Arabic (العربية) - with RTL support

### Features
- ✅ Language switching with persistent storage
- ✅ Dark mode toggle
- ✅ RTL support for Arabic
- ✅ Full settings page at `/settings`
- ✅ Quick settings dropdown menu
- ✅ All UI text translated

### Pages Translated
- Navigation items
- User Stories
- Checklists
- Projects
- Common buttons (Save, Delete, etc.)
- Settings dashboard
- Messages and alerts

## Testing

### Test Language Switch
1. Click settings menu (gear icon in topbar)
2. Click language flag
3. UI should update immediately
4. Reload page - settings should persist

### Test Dark Mode
1. Click settings menu (gear icon in topbar)
2. Toggle dark mode switch
3. Entire page should switch to dark theme
4. Reload page - dark mode should persist

### Test Arabic RTL
1. Switch to Arabic
2. Page direction should change to RTL
3. All text should be right-aligned
4. Layout should adapt for RTL

## Next Steps

1. Add the settings initialization to App.vue
2. Add the SettingsMenu component to header
3. Update the goToSettings() function
4. Start using `useI18n()` in components
5. Add dark mode classes to your components

## Need Help?

- See `SETTINGS_GUIDE.md` for detailed examples
- Check component prop interfaces for type hints
- All components use standard Vue 3 Composition API
