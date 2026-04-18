/**
 * Vue 3 Application Entry Point
 * Initializes Vue app and registers global components
 */

import { createApp } from 'vue';
import RunTestsButton from './components/RunTestsButton.vue';

// Create Vue app instance
const app = createApp({
  // Optional: Add root component template or use a default one
  template: '<div id="app"><slot /></div>',
});

/**
 * Register Global Components
 * These components are available throughout your application
 * without needing to import them in individual views
 */

// Register RunTestsButton component
// Usage in Blade: <run-tests-button :test-cases="@json($testCases)" :checklist-id="{{ $checklist->id }}" />
app.component('RunTestsButton', RunTestsButton);

/**
 * You can add more global components here as needed:
 * 
 * import MyComponent from './components/MyComponent.vue';
 * app.component('MyComponent', MyComponent);
 */

/**
 * Optional: Add global properties or methods
 */
app.config.globalProperties.$appName = 'Checklist Manager';

/**
 * Mount the app to the DOM element with id="app"
 * Make sure your Blade template has a <div id="app"></div>
 * and includes @vite(['resources/js/app.js'])
 */
app.mount('#app');

// Export app for debugging or further configuration
export default app;
