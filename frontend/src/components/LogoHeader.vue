<script setup>
function refreshPage() {
  if (typeof window !== 'undefined') {
    window.location.reload()
  }
}
</script>

<template>
  <button class="logo-header" type="button" @click="refreshPage" title="Refresh page">
    <div class="icon" aria-hidden="true">
      <svg viewBox="0 0 48 48" fill="none" xmlns="http://www.w3.org/2000/svg">
        <rect x="11" y="7" width="26" height="34" rx="7" fill="url(#paper)" />
        <rect x="11" y="7" width="26" height="34" rx="7" stroke="url(#border)" stroke-width="1.4" />
        <path d="M18 6.5C18 4.84315 19.3431 3.5 21 3.5H27C28.6569 3.5 30 4.84315 30 6.5V9H18V6.5Z" fill="url(#clip)" />
        <path d="M17.75 17H30.25" stroke="#294FA6" stroke-width="1.9" stroke-linecap="round" />
        <path d="M17.75 23.5H25.5" stroke="#294FA6" stroke-width="1.9" stroke-linecap="round" />
        <path d="M17.75 30H27.25" stroke="#294FA6" stroke-width="1.9" stroke-linecap="round" />
        <circle cx="30.5" cy="24" r="5.5" fill="#1F6FEB" />
        <path d="M28.1 24.1L29.75 25.75L33.1 22.45" stroke="white" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" />
        <defs>
          <linearGradient id="paper" x1="24" y1="7" x2="24" y2="41" gradientUnits="userSpaceOnUse">
            <stop stop-color="#FFFFFF" />
            <stop offset="1" stop-color="#EEF4FF" />
          </linearGradient>
          <linearGradient id="border" x1="11" y1="7" x2="38" y2="42" gradientUnits="userSpaceOnUse">
            <stop stop-color="#C7D8F7" />
            <stop offset="1" stop-color="#DCE7FA" />
          </linearGradient>
          <linearGradient id="clip" x1="24" y1="3.5" x2="24" y2="9" gradientUnits="userSpaceOnUse">
            <stop stop-color="#0F172A" />
            <stop offset="1" stop-color="#294FA6" />
          </linearGradient>
        </defs>
      </svg>
    </div>

    <div class="wordmark">
      <div class="brand-name">
        <span class="part1">Intelli</span><span class="part2">Test</span>
      </div>
      <span class="brand-subtitle">QA workflow studio</span>
    </div>
  </button>
</template>

<style scoped>
.logo-header {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  border: 0;
  background: transparent;
  padding: 0;
  cursor: pointer;
}

.icon {
  width: 42px;
  height: 42px;
  background: linear-gradient(135deg, #6366F1, #8B5CF6);
  border: 1px solid rgba(148, 163, 184, 0.18);
  border-radius: 12px;
  display: flex;
  align-items: center;
  justify-content: center;
  box-shadow: 0 16px 32px -24px rgba(15, 23, 42, 0.28);
  flex-shrink: 0;
  position: relative;
  overflow: hidden;
}

.icon::after {
  content: '';
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  height: 18px;
  border-radius: 12px 12px 0 0;
  background: linear-gradient(to bottom, rgba(255, 255, 255, 0.78), transparent);
}

.icon svg {
  width: 28px;
  height: 28px;
  position: relative;
  z-index: 1;
}

.wordmark {
  display: flex;
  flex-direction: column;
  gap: 0px;
}

.brand-name {
  font-family: "Space Grotesk", -apple-system, "Segoe UI", sans-serif;
  font-size: 1.02rem;
  font-weight: 800;
  letter-spacing: -0.06em;
  line-height: 1;
  display: flex;
  align-items: baseline;
}

.part1 {
  color: #0f172a;
}

.part2 {
  background: linear-gradient(180deg, #1f6feb, #174fbb);
  -webkit-background-clip: text;
  -webkit-text-fill-color: transparent;
  background-clip: text;
}

.brand-subtitle {
  font-family: "Sora", -apple-system, "Segoe UI", sans-serif;
  font-size: 0.68rem;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: #64748b;
  font-weight: 700;
}

.logo-header:focus-visible {
  outline: 2px solid rgba(31, 111, 235, 0.45);
  outline-offset: 4px;
  border-radius: 0.75rem;
}
</style>
