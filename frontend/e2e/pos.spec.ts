import { test, expect } from '@playwright/test'

// Helper: تسجيل الدخول
async function login(page: import('@playwright/test').Page) {
  await page.goto('/login')
  await page.fill('input[type="email"], input[name="email"]', 'admin@pos.com')
  await page.fill('input[type="password"]', 'password')
  const responsePromise = page.waitForResponse(response => response.url().includes('/login') && response.status() === 200)
  await page.click('button[type="submit"]')
  await responsePromise
}

test.describe('POS Page', () => {
  test.beforeEach(async ({ page }) => {
    await login(page)
  })

  test('should load POS page', async ({ page }) => {
    // تأكد من وجود حقل البحث / الباركود
    await expect(page.locator('input[placeholder*="باركود" i], input[placeholder*="barcode" i], input[type="search"]').first()).toBeVisible({ timeout: 5000 })
  })
})
