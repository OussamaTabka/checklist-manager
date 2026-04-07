import { test, expect } from '@playwright/test';

test('test', async ({ page }) => {
    await page.goto('http://localhost:5173');
    const heading = page.locator('h1');
    await expect(heading).toHaveText('Vite + React');
    

});