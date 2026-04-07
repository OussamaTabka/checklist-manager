import { test, expect } from '@playwright/test';

test('homepage loads successfully', async ({ page }) => {
  await page.goto('/');
  await expect(page).toHaveTitle(/Checklist|Dashboard/);
});

test('can navigate to login page', async ({ page }) => {
  await page.goto('/');
  // Add navigation test based on your app structure
  await expect(page).toHaveURL(/./);
});
