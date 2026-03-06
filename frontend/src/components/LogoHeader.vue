<script setup></script>

<template>
  <div class="logo-header">
    <!-- Icon -->
    <div class="icon">
      <svg viewBox="0 0 58 58" fill="none" xmlns="http://www.w3.org/2000/svg">
        <!-- Clipboard body -->
        <rect x="5" y="10" width="48" height="44" rx="6" fill="white" fill-opacity="0.13" stroke="white" stroke-opacity="0.22" stroke-width="1.2"/>
        <!-- Clip top -->
        <rect x="19" y="5" width="20" height="10" rx="5" fill="white" fill-opacity="0.18"/>
        <rect x="23" y="7" width="12" height="6" rx="3" fill="#1E40AF"/>

        <!-- Row 1 checked -->
        <rect x="12" y="23" width="22" height="3" rx="1.5" fill="white" fill-opacity="0.35"/>
        <circle cx="46" cy="24.5" r="6.5" fill="#10B981"/>
        <polyline points="42.5,24.5 45.5,27.5 49.5,21.5" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>

        <!-- Row 2 checked -->
        <rect x="12" y="33" width="17" height="3" rx="1.5" fill="white" fill-opacity="0.35"/>
        <circle cx="46" cy="34.5" r="6.5" fill="#10B981"/>
        <polyline points="42.5,34.5 45.5,37.5 49.5,31.5" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>

        <!-- Row 3 pending -->
        <rect x="12" y="43" width="20" height="3" rx="1.5" fill="white" fill-opacity="0.35"/>
        <circle cx="46" cy="44.5" r="6.5" stroke="white" stroke-opacity="0.55" stroke-width="2" fill="white" fill-opacity="0.1"/>
        <rect x="42" y="43" width="8" height="3" rx="1.5" fill="white" fill-opacity="0.65"/>
      </svg>
    </div>

    <!-- Wordmark -->
    <div class="wordmark">
      <div class="brand-name">
        <span class="part1">Intelli</span><span class="part2">Test</span>
      </div>
    </div>
  </div>
</template>

<style scoped>
.logo-header {
  display: flex;
  align-items: center;
  gap: 0.65rem;
}

.icon {
  width: 40px;
  height: 40px;
  background: linear-gradient(135deg, #2563eb 0%, #1e40af 100%);
  border-radius: 8px;
  display: flex;
  align-items: center;
  justify-content: center;
  box-shadow: 0 4px 12px rgba(37, 99, 235, 0.3);
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
  height: 20px;
  border-radius: 8px 8px 0 0;
  background: linear-gradient(to bottom, rgba(255, 255, 255, 0.15), transparent);
}

.icon svg {
  width: 24px;
  height: 24px;
  position: relative;
  z-index: 1;
}

.wordmark {
  display: flex;
  flex-direction: column;
  gap: 0px;
}

.brand-name {
  font-family: 'Plus Jakarta Sans', -apple-system, 'Segoe UI', sans-serif;
  font-size: 1rem;
  font-weight: 700;
  letter-spacing: -0.05em;
  line-height: 1;
  display: flex;
  align-items: baseline;
}

.part1 {
  color: white;
}

.part2 {
  background: linear-gradient(135deg, #87ceeb, #87ceeb);
  -webkit-background-clip: text;
  -webkit-text-fill-color: transparent;
  background-clip: text;
}
</style>
