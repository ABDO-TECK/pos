import { test, expect } from '@playwright/test'

async function login(page: any) {
  await page.goto('/login')
  await page.fill('input[type="email"]', 'admin@pos.com')
  await page.fill('input[type="password"]', 'password')
  await page.click('button[type="submit"]')
  await page.waitForURL('**/pos', { timeout: 10000 })
}

test.describe('Settings Page', () => {
  test.beforeEach(async ({ page }) => {
    await login(page)
    await page.goto('/settings')
  })

  test('should show settings form and load data', async ({ page }) => {
    const storeNameInput = page.locator('input[placeholder="اسم المحل"]')
    await expect(storeNameInput).toBeVisible({ timeout: 10000 })
  })
})
