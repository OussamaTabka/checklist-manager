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
      <svg viewBox="0 0 56 56" fill="none" xmlns="http://www.w3.org/2000/svg">
        <!-- clipboard body -->
        <rect x="10" y="12" width="36" height="38" rx="6" fill="url(#body)" />
        <rect x="10" y="12" width="36" height="38" rx="6" fill="rgba(255,255,255,0.12)" />
        <!-- clip at top -->
        <rect x="21" y="9" width="14" height="8" rx="3" fill="#94A3B8" />
        <rect x="23" y="11" width="10" height="4" rx="2" fill="#CBD5E1" />
        <!-- row 1: green check circle + line -->
        <circle cx="19" cy="26" r="4.5" fill="#22C55E" />
        <path d="M17 26l1.5 1.5 3-3" stroke="white" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
        <rect x="26" y="24.5" width="14" height="3" rx="1.5" fill="rgba(255,255,255,0.55)" />
        <!-- row 2: green check circle + line -->
        <circle cx="19" cy="35" r="4.5" fill="#22C55E" />
        <path d="M17 35l1.5 1.5 3-3" stroke="white" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
        <rect x="26" y="33.5" width="10" height="3" rx="1.5" fill="rgba(255,255,255,0.55)" />
        <!-- row 3: grey minus circle + line -->
        <circle cx="19" cy="44" r="4.5" fill="#94A3B8" />
        <path d="M16.5 44h5" stroke="white" stroke-width="1.5" stroke-linecap="round"/>
        <rect x="26" y="42.5" width="12" height="3" rx="1.5" fill="rgba(255,255,255,0.35)" />
        <defs>
          <linearGradient id="body" x1="10" y1="12" x2="46" y2="50" gradientUnits="userSpaceOnUse">
            <stop stop-color="#3B82F6" />
            <stop offset="1" stop-color="#1D4ED8" />
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
  background: linear-gradient(145deg, #4F8EF7, #2563EB);
  border: 1px solid rgba(37, 99, 235, 0.3);
  border-radius: 12px;
  display: flex;
  align-items: center;
  justify-content: center;
  box-shadow: 0 8px 20px -8px rgba(37, 99, 235, 0.45);
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
  width: 34px;
  height: 34px;
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
  color: #0F172A;
}

:global(.dark) .part1 {
  color: #F1F5F9;
}

.part2 {
  color: #7EC8E3;
  -webkit-text-fill-color: #7EC8E3;
}

.brand-subtitle {
  font-family: "Sora", -apple-system, "Segoe UI", sans-serif;
  font-size: 0.68rem;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: #64748b;
  font-weight: 700;
}

:global(.dark) .brand-subtitle {
  color: #94A3B8;
}

.logo-header:focus-visible {
  outline: 2px solid rgba(5, 150, 105, 0.45);
  outline-offset: 4px;
  border-radius: 0.75rem;
}
</style>
