<script setup>
import { computed, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { localizeError, localizeMessage } from '@/lib/localization'
import { useAuthStore } from '@/stores/auth'
import { useSettingsStore } from '@/stores/settings'
import { useToastStore } from '@/stores/toast'
import LogoHeader from '@/components/LogoHeader.vue'

const auth = useAuthStore()
const settings = useSettingsStore()
const toast = useToastStore()
const route = useRoute()
const router = useRouter()

const form = reactive({
  email: '',
  password: '',
})

const errorMessage = ref('')
const isSubmitting = ref(false)
const infoMessage = computed(() => (typeof route.query.message === 'string' ? route.query.message : ''))

watch(
  infoMessage,
  async (message) => {
    if (!message) {
      return
    }

    toast.success(localizeMessage(message, settings.language))

    const nextQuery = { ...route.query }
    delete nextQuery.message
    await router.replace({ query: nextQuery })
  },
  { immediate: true },
)

async function onSubmit() {
  errorMessage.value = ''
  isSubmitting.value = true

  try {
    await auth.login({ ...form })
    await router.push({ name: 'dashboard' })
  } catch (error) {
    errorMessage.value = localizeError(error, 'error_generic', settings.language)
  } finally {
    isSubmitting.value = false
  }
}
</script>

<template>
  <section class="page page-auth-sm">
    <div class="login-brand-row">
      <LogoHeader />
    </div>

    <div class="card stack">
      <div>
        <h1>Connexion</h1>
        <p class="muted">Utilisez vos identifiants pour accéder Ã  IntelliTest.</p>
      </div>

      <p v-if="errorMessage" class="error" data-testid="login-msg-error">{{ errorMessage }}</p>
      <form class="stack" @submit.prevent="onSubmit">
        <div class="field">
          <label>Email</label>
          <input
            v-model="form.email"
            type="email"
            required
            autocomplete="email"
            data-testid="login-input-email"
          />
        </div>

        <div class="field">
          <label>Mot de passe</label>
          <input
            v-model="form.password"
            type="password"
            required
            autocomplete="current-password"
            data-testid="login-input-password"
          />
        </div>

        <div class="auth-link-row">
          <RouterLink :to="{ name: 'forgot-password' }" class="muted">Mot de passe oublié ?</RouterLink>
        </div>

        <button
          class="btn btn-primary"
          type="submit"
          :disabled="isSubmitting"
          data-testid="login-btn-submit"
        >
          {{ isSubmitting ? 'Connexion en cours...' : 'Se connecter' }}
        </button>
      </form>
    </div>
  </section>
</template>

<style scoped>
.login-brand-row {
  width: 100%;
  display: flex;
  justify-content: flex-start;
  margin-bottom: 1rem;
}
</style>
