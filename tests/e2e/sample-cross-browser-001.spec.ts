import { test, expect } from '@playwright/test';

const TARGET_URL = 'https://example.com';

test('sample-cross-browser-001 - cross-browser compatibility', async ({ page }) => {
	await page.goto(TARGET_URL, { waitUntil: 'domcontentloaded', timeout: 15000 });

	await expect(page).toHaveTitle(/Example Domain/);
	await expect(page.getByRole('heading', { name: 'Example Domain' })).toBeVisible();

	const hasHorizontalOverflow = await page.evaluate(
		() => document.documentElement.scrollWidth > window.innerWidth + 1,
	);
	expect(hasHorizontalOverflow).toBeFalsy();
});
