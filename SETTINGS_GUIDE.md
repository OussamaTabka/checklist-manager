# Settings System Setup Guide

This guide explains how to use the new settings system including language switching and dark mode.

## Features

- **Language Support**: French (FR), English (EN), Arabic (AR)
- **Dark Mode**: Toggle between light and dark themes
- **RTL Support**: Automatic right-to-left layout for Arabic
- **Persistent Settings**: Settings are saved to localStorage
- **Reactive UI**: Real-time theme and language updates

## Components & Files

### Settings Store (`/src/stores/settings.js`)
- Manages language and dark mode state
- Persists settings to localStorage
- Applies CSS classes and HTML attributes

### Translations (`/src/lib/translations.js`)
- Contains all UI text in 3 languages (FR, EN, AR)
- Export `t()` function for direct translation
- Export `useTranslations()` composable

### Views

#### Settings View (`/src/views/SettingsView.vue`)
- Full settings page with language and dark mode controls
- Route: `/settings`
- Protected route (requires authentication)

#### Settings Menu (`/src/components/SettingsMenu.vue`)
- Compact dropdown menu for quick access
- Place in header/navbar
- Quick language switching and dark mode toggle
- Link to full settings page

### Composable (`/src/composables/useI18n.js`)
- Simple wrapper around translation and settings
- Provides reactive language and RTL info
- Usage: `const { translate: t, isRTL } = useI18n()`

## Integration Steps

### 1. Initialize Settings on App Load

Add to your main App.vue or main.js:

```vue
<!-- In App.vue -->
<script setup>
import { onMounted } from 'vue'
import { useSettingsStore } from '@/stores/settings'

const settingsStore = useSettingsStore()

onMounted(() => {
  settingsStore.loadSettings()
})
</script>
```

### 2. Add Settings Menu to Header

```vue
<!-- In your header/navbar component -->
<template>
  <header>
    <!-- Other header content -->
    <SettingsMenu />
  </header>
</template>

<script setup>
import SettingsMenu from '@/components/SettingsMenu.vue'
</script>
```

### 3. Use Translations in Components

**Option 1: Using the composable (recommended)**
```vue
<script setup>
import { useI18n } from '@/composables/useI18n'

const { translate: t } = useI18n()
</script>

<template>
  <h1>{{ t('settings.title') }}</h1>
  <p>{{ t('common.save') }}</p>
</template>
```

**Option 2: Using store directly**
```vue
<script setup>
import { useSettingsStore } from '@/stores/settings'
import { t } from '@/lib/translations'

const settingsStore = useSettingsStore()
</script>

<template>
  <h1>{{ t('settings.title', settingsStore.language) }}</h1>
</template>
```

### 4. Apply Dark Mode Classes

Add dark mode classes to your components:

```vue
<template>
  <!-- Light mode by default, dark mode with 'dark:' prefix -->
  <div class="bg-white dark:bg-gray-900 text-gray-900 dark:text-white">
    <h1 class="text-lg dark:text-blue-300">Content</h1>
  </div>
</template>
```

## Usage Examples

### Example: Component with Language Support

```vue
<script setup>
import { useI18n } from '@/composables/useI18n'

const { t, isRTL } = useI18n()
</script>

<template>
  <div :dir="isRTL ? 'rtl' : 'ltr'">
    <h1>{{ t('stories.title') }}</h1>
    <button>{{ t('common.save') }}</button>
  </div>
</template>

<style scoped>
/* RTL styles will automatically be handled by dir attribute */
</style>
```

### Example: Settings Page Link

```vue
<script setup>
import { useRouter } from 'vue-router'

const router = useRouter()

function openSettings() {
  router.push({ name: 'settings' })
}
</script>

<template>
  <button @click="openSettings" class="px-4 py-2 rounded">
    Open Settings
  </button>
</template>
```

## Dark Mode Implementation

### How it Works
1. Settings store manages `darkMode` boolean state
2. When toggled, adds/removes `dark` class to `<html>` element
3. Tailwind CSS applies `dark:*` prefixed styles
4. Settings persist across sessions via localStorage

### Tailwind Configuration
Already configured in `tailwind.config.js`:
```js
export default {
  darkMode: 'class',  // Uses class-based dark mode
  // ... rest of config
}
```

### Testing Dark Mode
```js
// In browser console
localStorage.setItem('app_settings', JSON.stringify({
  language: 'en',
  darkMode: true
}))
// Refresh page to see dark mode
```

## Available Translations

### Settings Keys
- `settings.title` - Settings heading
- `settings.appearance` - Appearance section
- `settings.language` - Language label
- `settings.darkMode` - Dark mode label
- `settings.darkModeEnabled` - Dark mode is on
- `settings.darkModeDisabled` - Light mode is on

### Navigation Keys
- `nav.dashboard` - Dashboard
- `nav.projects` - Projects
- `nav.settings` - Settings
- `nav.logout` - Logout

### Common Keys
- `common.save` - Save
- `common.cancel` - Cancel
- `common.delete` - Delete
- `common.loading` - Loading...

### Stories Keys
- `stories.title` - User Stories
- `stories.new` - New Story
- `stories.generate` - Generate Checklist

See `/src/lib/translations.js` for complete list.

## Adding More Translations

Edit `/src/lib/translations.js`:

```js
export const t = (key, lang = 'fr') => {
  const translations = {
    fr: {
      'my.new.key': 'Texte français',
      'my.other.key': 'Autre texte',
    },
    en: {
      'my.new.key': 'English text',
      'my.other.key': 'Another text',
    },
    ar: {
      'my.new.key': 'نص عربي',
      'my.other.key': 'نص آخر',
    },
  }
  // ... rest of function
}
```

## Adding New Languages

To add a new language (e.g., Spanish):

1. **Update Settings Store** (`/src/stores/settings.js`):
```js
const languages = [
  { code: 'fr', name: 'Français', flag: '🇫🇷' },
  { code: 'en', name: 'English', flag: '🇬🇧' },
  { code: 'ar', name: 'العربية', flag: '🇸🇦' },
  { code: 'es', name: 'Español', flag: '🇪🇸' },  // Add this
]
```

2. **Add translations** in `/src/lib/translations.js`:
```js
es: {
  'settings.title': 'Configuración',
  'common.save': 'Guardar',
  // ... rest of translations
}
```

3. **Handle RTL** if needed in `applyLanguage()`:
```js
const rtlLanguages = ['ar', 'he'] // Add more if needed
if (rtlLanguages.includes(language.value)) {
  html.dir = 'rtl'
}
```

## Troubleshooting

### Dark mode not working
- Ensure Tailwind config has `darkMode: 'class'`
- Check that `dark` class is on `<html>` element
- Clear browser cache

### Translations not showing
- Verify translation key exists in `/src/lib/translations.js`
- Check language setting: `settingsStore.language`
- Use browser console: `t('key', 'en')`

### Settings not persisting
- Check localStorage is enabled
- Verify `STORAGE_KEY` constant in settings store
- Check browser console for errors

### RTL layout issues
- Ensure `dir` attribute is set on parent elements
- Test with `isRTL` computed property
- Use Tailwind's RTL utilities where needed

## Performance Tips

1. **Lazy load translations** - Consider splitting by language
2. **Memoize translations** - Cache frequently used translations
3. **Debounce dark mode** - Already handled by store
4. **Preload settings** - Load on app init in main.js

## API Integration (Optional)

To sync settings with backend:

```js
// In settings store
async function saveSettingsToServer() {
  const response = await api.put('/api/user/settings', {
    language: language.value,
    darkMode: darkMode.value,
  })
  // Handle response
}

// Call in API after changing settings
watch([language, darkMode], () => {
  saveSettingsToServer()
})
```

## Browser Support

- All modern browsers (Chrome, Firefox, Safari, Edge)
- localStorage needed for persistence
- CSS custom properties for dark mode
- No IE11 support (uses CSS Grid and modern standards)
