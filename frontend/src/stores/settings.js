import { ref, watch, computed } from 'vue'
import { defineStore } from 'pinia'

export const useSettingsStore = defineStore('settings', () => {
  const STORAGE_KEY = 'app_settings'
  
  const language = ref('fr')
  const darkMode = ref(false)
  
  const languages = [
    { code: 'fr', name: 'Français', flag: '🇫🇷' },
    { code: 'en', name: 'English', flag: '🇬🇧' },
    { code: 'ar', name: 'العربية', flag: '🇸🇦' },
  ]

  function loadSettings() {
    const stored = localStorage.getItem(STORAGE_KEY)
    if (stored) {
      try {
        const settings = JSON.parse(stored)
        language.value = settings.language || 'fr'
        darkMode.value = settings.darkMode || false
      } catch (e) {
        console.error('Failed to load settings:', e)
      }
    }
    
    // Apply dark mode on load
    applyDarkMode()
    // Apply language and document direction on load
    applyLanguage()
  }

  function saveSettings() {
    const settings = {
      language: language.value,
      darkMode: darkMode.value,
    }
    localStorage.setItem(STORAGE_KEY, JSON.stringify(settings))
  }

  function setLanguage(lang) {
    language.value = lang
    applyLanguage()
    saveSettings()
  }

  function toggleDarkMode() {
    darkMode.value = !darkMode.value
    applyDarkMode()
    saveSettings()
  }

  function applyDarkMode() {
    const html = document.documentElement
    if (darkMode.value) {
      html.classList.add('dark')
    } else {
      html.classList.remove('dark')
    }
  }

  function applyLanguage() {
    const html = document.documentElement
    html.lang = language.value
    
    // For RTL languages
    if (language.value === 'ar') {
      html.dir = 'rtl'
      document.body.style.direction = 'rtl'
    } else {
      html.dir = 'ltr'
      document.body.style.direction = 'ltr'
    }
  }

  const currentLanguageName = computed(() => {
    const lang = languages.find(l => l.code === language.value)
    return lang?.name || 'Français'
  })

  const currentLanguageFlag = computed(() => {
    const lang = languages.find(l => l.code === language.value)
    return lang?.flag || '🇫🇷'
  })

  // Watch for dark mode changes to apply them
  watch(darkMode, () => {
    applyDarkMode()
  })

  // Watch for language changes
  watch(language, () => {
    applyLanguage()
  })

  return {
    language,
    darkMode,
    languages,
    currentLanguageName,
    currentLanguageFlag,
    loadSettings,
    saveSettings,
    setLanguage,
    toggleDarkMode,
  }
})
