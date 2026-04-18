import './bootstrap';
import { createApp } from 'vue';
import RunTestsButton from './components/RunTestsButton.vue';

const mountPoint = document.getElementById('app');

if (mountPoint) {
	const app = createApp({
		methods: {
			handleTestsComplete(payload) {
				if (typeof window.handleTestsComplete === 'function') {
					window.handleTestsComplete(payload);
					return;
				}

				console.log('✅ tests-complete', payload);
			},
			handleTestsFailed(message) {
				if (typeof window.handleTestsFailed === 'function') {
					window.handleTestsFailed(message);
					return;
				}

				console.error('❌ tests-failed', message);
			},
		},
	});

	app.component('RunTestsButton', RunTestsButton);
	app.mount(mountPoint);
}
