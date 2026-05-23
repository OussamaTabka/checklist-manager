<script setup>
import { ref, computed, watch } from 'vue'
import { useSettingsStore } from '@/stores/settings'
import { t } from '@/lib/translations'
import { translatePhrase } from '@/lib/runtimeTranslations'
import { ArrowLeft, Check, Globe, Moon, Palette, Camera, UserRound, Trash2 } from 'lucide-vue-next'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useToastStore } from '@/stores/toast'

const router = useRouter()
const route = useRoute()
const auth = useAuthStore()
const settingsStore = useSettingsStore()
const toast = useToastStore()

const isSavingProfile = ref(false)
const selectedPhotoFile = ref(null)
const photoPreviewUrl = ref('')
const removeProfilePhoto = ref(false)
const profileForm = ref({
  name: '',
})

function tr(text) {
  return translatePhrase(text, settingsStore.language)
}

const currentLanguageLabel = computed(() => {
  const lang = settingsStore.languages.find((item) => item.code === settingsStore.language)
  return lang ? `${lang.flag} ${lang.name}` : 'Français'
})

const settingsCopy = computed(() => {
  switch (auth.primaryRole) {
    case 'admin':
      return {
        kicker: tr('Admin Preferences'),
        title: tr('Platform Settings'),
        description: tr('Adjust the interface language and appearance for administration work.'),
      }
    case 'testeur':
      return {
        kicker: tr('Execution Preferences'),
        title: tr('Tester Settings'),
        description: tr('Tune the workspace for faster reading, execution, and day-to-day testing comfort.'),
      }
    default:
      return {
        kicker: tr('Workspace Preferences'),
        title: t('settings.title', settingsStore.language),
        description: tr('Configure language and display behavior for your project-management workspace.'),
      }
  }
})

function showSavedMessage(message) {
  toast.success(message, 2500)
}

function hydrateProfileForm() {
  profileForm.value.name = auth.user?.name || ''
  photoPreviewUrl.value = auth.user?.profile_photo_url || ''
  selectedPhotoFile.value = null
  removeProfilePhoto.value = false
}

function changeLanguage(langCode) {
  settingsStore.setLanguage(langCode)
  showSavedMessage(t('msg.saved', settingsStore.language))
}

function toggleDarkMode() {
  settingsStore.toggleDarkMode()
  const message = settingsStore.darkMode
    ? t('settings.darkModeEnabled', settingsStore.language)
    : t('settings.darkModeDisabled', settingsStore.language)

  showSavedMessage(message)
}

function goBack() {
  router.back()
}

function onProfilePhotoSelected(event) {
  const [file] = event.target.files || []
  if (!file) {
    return
  }

  selectedPhotoFile.value = file
  removeProfilePhoto.value = false
  photoPreviewUrl.value = URL.createObjectURL(file)
}

function clearSelectedPhoto() {
  selectedPhotoFile.value = null
  removeProfilePhoto.value = true
  photoPreviewUrl.value = ''
}

async function saveProfile() {
  isSavingProfile.value = true

  try {
    const payload = new FormData()
    payload.append('name', profileForm.value.name.trim())

    if (selectedPhotoFile.value) {
      payload.append('profile_photo', selectedPhotoFile.value)
    }

    if (removeProfilePhoto.value) {
      payload.append('remove_profile_photo', '1')
    }

    await auth.updateProfile(payload)
    hydrateProfileForm()
    showSavedMessage(tr('Profile updated successfully'))
  } catch (error) {
    toast.error(error?.message || tr('Unable to update profile'))
  } finally {
    isSavingProfile.value = false
  }
}

watch(
  () => auth.user,
  () => {
    hydrateProfileForm()
  },
  { immediate: true },
)
</script>

<template>
  <section class="page stack settings-page-pro">
    <div class="settings-hero">
      <div class="settings-hero-copy-wrap">
        <div class="settings-hero-copy">
          <p class="story-kicker">{{ settingsCopy.kicker }}</p>
          <h1>{{ settingsCopy.title }}</h1>
          <p>{{ settingsCopy.description }}</p>
        </div>
      </div>

      <div class="settings-hero-side">
        <article class="settings-panel-pro settings-darkmode-hero-card">
          <div class="settings-panel-head">
            <div class="settings-panel-icon bg-amber-50 text-amber-700">
              <Moon :size="18" />
            </div>
            <div>
              <h2>{{ t('settings.darkMode', settingsStore.language) }}</h2>
              <p>
                {{
                  settingsStore.darkMode
                    ? t('settings.darkModeEnabled', settingsStore.language)
                    : t('settings.darkModeDisabled', settingsStore.language)
                }}
              </p>
            </div>
          </div>

          <div class="settings-theme-row">
            <div class="settings-theme-preview">
              <Palette :size="18" />
              <span>{{ settingsStore.darkMode ? tr('Dark canvas enabled') : tr('Light canvas enabled') }}</span>
            </div>

            <button
              @click="toggleDarkMode"
              :class="['settings-toggle', { 'settings-toggle-on': settingsStore.darkMode }]"
            >
              <span />
            </button>
          </div>
        </article>
      </div>
    </div>

    <div class="settings-grid-pro">
      <article class="settings-panel-pro" :id="route.query.section === 'profile' ? 'profile-section' : undefined">
        <div class="settings-panel-head">
          <div class="settings-panel-icon bg-slate-100 text-slate-700">
            <UserRound :size="18" />
          </div>
          <div>
            <h2>{{ tr('Profile') }}</h2>
            <p>{{ tr('Update your visible identity in the workspace.') }}</p>
          </div>
        </div>

        <div class="profile-settings-layout">
          <div class="profile-photo-stack">
            <div class="profile-photo-frame">
              <img v-if="photoPreviewUrl" :src="photoPreviewUrl" alt="Profile preview" class="profile-photo-preview" />
              <span v-else>{{ (profileForm.name || auth.user?.email || 'U').charAt(0).toUpperCase() }}</span>
            </div>

            <div class="actions">
              <label class="btn btn-secondary btn-sm profile-upload-btn">
                <Camera :size="16" />
                <span>{{ tr('Change photo') }}</span>
                <input type="file" accept="image/*" class="sr-only" @change="onProfilePhotoSelected" />
              </label>
              <button
                v-if="photoPreviewUrl"
                type="button"
                class="btn btn-secondary btn-sm"
                @click="clearSelectedPhoto"
              >
                <Trash2 :size="16" />
                <span>{{ tr('Remove photo') }}</span>
              </button>
            </div>
          </div>

          <div class="stack">
            <div class="field">
              <label>{{ tr('Full name') }}</label>
              <input v-model="profileForm.name" type="text" maxlength="255" :placeholder="tr('Your name')" />
            </div>

            <div class="field">
              <label>{{ tr('Email') }}</label>
              <input :value="auth.user?.email || ''" type="email" disabled />
            </div>

            <div class="actions">
              <button type="button" class="btn btn-primary" :disabled="isSavingProfile" @click="saveProfile">
                <Check :size="16" />
                <span>{{ isSavingProfile ? tr('Saving...') : tr('Save profile') }}</span>
              </button>
            </div>
          </div>
        </div>
      </article>

      <article class="settings-panel-pro">
        <div class="settings-panel-head">
          <div class="settings-panel-icon bg-blue-50 text-blue-700">
            <Globe :size="18" />
          </div>
          <div>
            <h2>{{ t('settings.language', settingsStore.language) }}</h2>
            <p>{{ currentLanguageLabel }}</p>
          </div>
        </div>

        <div class="settings-language-grid">
          <button
            v-for="lang in settingsStore.languages"
            :key="lang.code"
            @click="changeLanguage(lang.code)"
            class="settings-choice-card"
            :class="{ 'settings-choice-active': settingsStore.language === lang.code }"
          >
            <span class="settings-choice-flag">{{ lang.flag }}</span>
            <strong>{{ lang.name }}</strong>
          </button>
        </div>
      </article>

    </div>
  </section>
</template>

<style scoped>
.settings-hero {
  display: grid;
  grid-template-columns: minmax(0, 1.3fr) minmax(280px, 0.72fr);
  gap: 1rem;
  align-items: start;
}

.settings-hero-copy-wrap {
  display: grid;
  gap: 1rem;
  min-width: 0;
}

.settings-hero-side {
  min-width: 0;
}

.settings-darkmode-hero-card {
  height: 100%;
}

.profile-settings-layout {
  display: grid;
  grid-template-columns: 220px 1fr;
  gap: 1.25rem;
  align-items: start;
}

.profile-photo-stack {
  display: grid;
  gap: 0.9rem;
}

.profile-photo-frame {
  width: 164px;
  height: 164px;
  border-radius: 1.4rem;
  background: linear-gradient(180deg, rgba(31, 111, 235, 0.18), rgba(15, 23, 42, 0.08));
  color: #174fbb;
  display: grid;
  place-items: center;
  font-size: 3rem;
  font-weight: 800;
  border: 1px solid rgba(100, 116, 139, 0.18);
  overflow: hidden;
}

.profile-photo-preview {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.profile-upload-btn {
  position: relative;
  overflow: hidden;
}

@media (max-width: 900px) {
  .settings-hero {
    grid-template-columns: 1fr;
  }

  .profile-settings-layout {
    grid-template-columns: 1fr;
  }

  .profile-photo-frame {
    width: 120px;
    height: 120px;
  }
}
</style>
