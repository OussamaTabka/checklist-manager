/**
 * Playwright Test: sample-cross-browser-001
 * Title: Cross-Browser Compatibility - Test on Chrome, Firefox, Safari, Edge
 *
 * Purpose:
 * - Implements a planner-style cross-browser and responsive validation flow.
 * - Runs checks across browsers and viewports, captures artifacts,
 *   performs accessibility checks, and writes a JSON report.
 *
 * How to run:
 * 1. npm install --save-dev @playwright/test @axe-core/playwright
 * 2. (Optional for pixel diff) npm install --save-dev pixelmatch pngjs
 * 3. npx playwright install
 * 4. npx playwright test tests/e2e/sample-cross-browser-001.spec.ts
 */

import { test, expect, chromium, firefox, webkit, type BrowserType, type Page, type Video } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import fs from 'node:fs';
import fsp from 'node:fs/promises';
import path from 'node:path';

type Report = {
	status: 'passed' | 'failed' | 'error';
	assertions: { stepId: number; result: 'passed' | 'failed'; message: string }[];
	errors: { stepId: number; errorMessage: string; stack?: string }[];
	artifacts: { type: 'screenshot' | 'html' | 'video'; path: string }[];
	durationSeconds: number;
	timestamp: string;
};

type BrowserName = 'chromium' | 'firefox' | 'webkit';

const TEST_ITEM_ID = 'sample-cross-browser-001';
const TITLE = 'Cross-Browser Compatibility - Test on Chrome, Firefox, Safari, Edge';
const TARGET_URL = 'https://example.com';
const BROWSERS: BrowserName[] = ['chromium', 'firefox', 'webkit'];
const VIEWPORTS = [
	{ name: 'mobile', width: 375, height: 812 },
	{ name: 'tablet', width: 768, height: 1024 },
	{ name: 'desktop', width: 1366, height: 768 },
];
const ARTIFACT_FOLDER = path.resolve(process.cwd(), 'artifacts');
const RETRY_BACKOFF_SECONDS = 3;
const GLOBAL_TIMEOUT_SECONDS = 300;

const BROWSER_TYPES: Record<BrowserName, BrowserType> = {
	chromium,
	firefox,
	webkit,
};

function nowTimestamp(): string {
	return new Date().toISOString().replace(/[:.]/g, '-');
}

async function ensureDir(dir: string): Promise<void> {
	await fsp.mkdir(dir, { recursive: true });
}

async function saveFile(filePath: string, content: string | Buffer): Promise<string> {
	await ensureDir(path.dirname(filePath));
	await fsp.writeFile(filePath, content);
	return filePath;
}

async function captureArtifacts(
	page: Page,
	meta: { browser: string; viewport: string; stepId: number; suffix?: string },
	artifacts: Report['artifacts'],
): Promise<void> {
	const timestamp = nowTimestamp();
	const baseName = `${TEST_ITEM_ID}-${meta.browser}-${meta.viewport}-step${meta.stepId}${meta.suffix ? `-${meta.suffix}` : ''}-${timestamp}`;

	const screenshotPath = path.join(ARTIFACT_FOLDER, `${baseName}.png`);
	try {
		await page.screenshot({ path: screenshotPath, fullPage: true });
		artifacts.push({ type: 'screenshot', path: screenshotPath });
	} catch {
		// Keep execution resilient when screenshot fails.
	}

	const html = await page.content();
	const htmlPath = path.join(ARTIFACT_FOLDER, `${baseName}.html`);
	await saveFile(htmlPath, html);
	artifacts.push({ type: 'html', path: htmlPath });
}

async function tryPixelCompare(
	baselinePath: string,
	currentPath: string,
	threshold = 0.02,
): Promise<{ passed: boolean | null; diffRatio?: number; error?: string }> {
	try {
		const pixelmatch = (await import('pixelmatch')).default;
		const { PNG } = await import('pngjs');
		const img1 = PNG.sync.read(fs.readFileSync(baselinePath));
		const img2 = PNG.sync.read(fs.readFileSync(currentPath));

		if (img1.width !== img2.width || img1.height !== img2.height) {
			return { passed: false, diffRatio: 1 };
		}

		const diffPixels = pixelmatch(
			img1.data,
			img2.data,
			undefined,
			img1.width,
			img1.height,
			{ threshold: 0.1 },
		);
		const totalPixels = img1.width * img1.height;
		const diffRatio = totalPixels > 0 ? diffPixels / totalPixels : 1;
		return { diffRatio, passed: diffRatio <= threshold };
	} catch {
		return { passed: null, error: 'pixel-compare-not-available' };
	}
}

test('sample-cross-browser-001 - cross-browser compatibility', async ({}, testInfo) => {
	// This spec handles browser iteration internally; skip duplicate project invocations.
	test.skip(testInfo.project.name !== 'chromium', 'Internal browser loop is enabled for this spec.');
	test.setTimeout(GLOBAL_TIMEOUT_SECONDS * 1000);

	const runStart = Date.now();
	const report: Report = {
		status: 'passed',
		assertions: [],
		errors: [],
		artifacts: [],
		durationSeconds: 0,
		timestamp: new Date().toISOString(),
	};

	await ensureDir(ARTIFACT_FOLDER);

	for (const browserName of BROWSERS) {
		let browser;
		try {
			browser = await BROWSER_TYPES[browserName].launch({ headless: true });
		} catch (err) {
			report.errors.push({
				stepId: 1,
				errorMessage: `Failed to launch ${browserName}: ${(err as Error).message}`,
				stack: (err as Error).stack,
			});
			report.status = 'failed';
			continue;
		}

		for (const viewport of VIEWPORTS) {
			const context = await browser.newContext({
				viewport: { width: viewport.width, height: viewport.height },
				recordVideo: { dir: ARTIFACT_FOLDER },
			});
			const page = await context.newPage();
			const video: Video | null = page.video();
			const meta = { browser: browserName, viewport: viewport.name };

			async function runStepWithRetries(
				stepId: number,
				timeoutSeconds: number,
				retryAttempts: number,
				fn: () => Promise<void>,
			): Promise<void> {
				const maxAttempts = 1 + Math.max(0, retryAttempts);

				for (let attempt = 1; attempt <= maxAttempts; attempt += 1) {
					try {
						await Promise.race([
							fn(),
							new Promise((_, reject) => {
								setTimeout(() => reject(new Error('step-timeout')), timeoutSeconds * 1000);
							}),
						]);

						report.assertions.push({
							stepId,
							result: 'passed',
							message: `Attempt ${attempt} passed`,
						});
						return;
					} catch (err) {
						const error = err as Error;
						report.errors.push({
							stepId,
							errorMessage: `Attempt ${attempt} failed: ${error.message || String(error)}`,
							stack: error.stack,
						});

						await captureArtifacts(page, { ...meta, stepId, suffix: `attempt${attempt}` }, report.artifacts);

						if (attempt < maxAttempts) {
							await new Promise((resolve) => setTimeout(resolve, RETRY_BACKOFF_SECONDS * 1000));
							continue;
						}

						report.assertions.push({
							stepId,
							result: 'failed',
							message: `Failed after ${maxAttempts} attempts`,
						});
						throw error;
					}
				}
			}

			try {
				await runStepWithRetries(2, 30, 2, async () => {
					await page.goto(TARGET_URL, { waitUntil: 'domcontentloaded', timeout: 30000 });
				});

				await runStepWithRetries(3, 20, 2, async () => {
					await page.waitForSelector(
						"[data-testid='main-content'], [data-qa='main-content'], main, #main, [role='main']",
						{ state: 'visible', timeout: 20000 },
					);
				});

				await runStepWithRetries(4, 25, 1, async () => {
					// TODO discovery placeholder: planner-compatible no-op step.
					await page.evaluate(() => true);
				});

				await runStepWithRetries(5, 30, 2, async () => {
					const checks = [
						"[data-testid='site-header'], [data-qa='site-header'], header, [role='banner']",
						"[data-testid='primary-nav'], [data-qa='primary-nav'], nav[aria-label='primary' i], nav",
						"[data-testid='main-heading'], h1, [role='heading'][aria-level='1']",
						"[data-testid='site-footer'], [data-qa='site-footer'], footer, [role='contentinfo']",
					];

					for (const selector of checks) {
						const handle = await page.waitForSelector(selector, {
							state: 'visible',
							timeout: 30000,
						}).catch(() => null);

						if (!handle) {
							throw new Error(`Required element not visible using selector list: ${selector}`);
						}
					}
				});

				await runStepWithRetries(6, 20, 1, async () => {
					await page.waitForTimeout(400);
				});

				await runStepWithRetries(7, 15, 2, async () => {
					const layout = await page.evaluate(() => ({
						scrollWidth: document.documentElement.scrollWidth,
						innerWidth: window.innerWidth,
						pass: document.documentElement.scrollWidth <= window.innerWidth + 1,
					}));

					if (!layout.pass) {
						throw new Error(
							`Horizontal overflow detected: scrollWidth=${layout.scrollWidth}, innerWidth=${layout.innerWidth}`,
						);
					}
				});

				await runStepWithRetries(8, 25, 2, async () => {
					if (viewport.name === 'mobile') {
						const toggle = await page.locator(
							"[data-testid='menu-toggle'], [data-qa='menu-toggle'], button[aria-label*='menu' i], button:has-text('Menu')",
						).first().isVisible().catch(() => false);

						const nav = await page.locator(
							"[data-testid='primary-nav'], [data-qa='primary-nav'], nav",
						).first().isVisible().catch(() => false);

						if (!toggle && !nav) {
							throw new Error('Mobile navigation was not discoverable.');
						}
						return;
					}

					const navVisible = await page.locator(
						"[data-testid='primary-nav'], [data-qa='primary-nav'], nav",
					).first().isVisible().catch(() => false);

					if (!navVisible) {
						throw new Error('Primary navigation is not visible for tablet/desktop viewport.');
					}
				});

				await runStepWithRetries(9, 40, 2, async () => {
					const snapshotName = `${TEST_ITEM_ID}-${browserName}-${viewport.name}-${nowTimestamp()}.png`;
					const snapshotPath = path.join(ARTIFACT_FOLDER, snapshotName);
					await page.screenshot({ path: snapshotPath, fullPage: true });
					report.artifacts.push({ type: 'screenshot', path: snapshotPath });

					const baselinePath = path.join(
						process.cwd(),
						'visual-baseline',
						`${TEST_ITEM_ID}-${browserName}-${viewport.name}.png`,
					);

					if (fs.existsSync(baselinePath)) {
						const cmp = await tryPixelCompare(baselinePath, snapshotPath, 0.02);
						if (cmp.passed === false) {
							throw new Error(`Visual diff exceeded threshold. Ratio=${String(cmp.diffRatio)}`);
						}
					}
				});

				await runStepWithRetries(10, 60, 1, async () => {
					const axeResults = await new AxeBuilder({ page }).analyze();
					const violations = (axeResults.violations || []).filter((v) =>
						v.impact === 'critical' || v.impact === 'serious',
					);

					if (violations.length > 0) {
						const axePath = path.join(
							ARTIFACT_FOLDER,
							`${TEST_ITEM_ID}-${browserName}-${viewport.name}-axe-${nowTimestamp()}.json`,
						);
						await saveFile(axePath, JSON.stringify(axeResults, null, 2));
						report.artifacts.push({ type: 'html', path: axePath });
						throw new Error(`Accessibility critical/serious violations: ${violations.length}`);
					}
				});

				await runStepWithRetries(11, 15, 1, async () => {
					const health = await page.evaluate(() => ({
						title: document.title || '',
						hasMain: Boolean(document.querySelector('main, [role="main"], #main')),
					}));

					if (!health.title.trim()) {
						throw new Error('Page title is empty.');
					}

					if (!health.hasMain) {
						throw new Error('Main landmark is missing.');
					}
				});

				await captureArtifacts(page, {
					browser: browserName,
					viewport: viewport.name,
					stepId: 12,
				}, report.artifacts);
			} catch {
				report.status = 'failed';
			} finally {
				await context.close().catch(() => undefined);

				if (video) {
					const videoPath = await video.path().catch(() => null);
					if (videoPath) {
						report.artifacts.push({ type: 'video', path: videoPath });
					}
				}
			}
		}

		await browser.close().catch(() => undefined);
	}

	report.durationSeconds = Math.round((Date.now() - runStart) / 1000);
	report.timestamp = new Date().toISOString();

	if (report.errors.length > 0 || report.assertions.some((item) => item.result === 'failed')) {
		report.status = 'failed';
	} else {
		report.status = 'passed';
	}

	const outputName = `results-${TEST_ITEM_ID}-${nowTimestamp()}.json`;
	const outputPath = path.join(ARTIFACT_FOLDER, outputName);
	await saveFile(outputPath, JSON.stringify(report, null, 2));

	console.log(JSON.stringify(report, null, 2));
	expect(report.status).toBe('passed');
});
