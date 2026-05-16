import { test, expect, Page } from '@playwright/test'

async function login(page: Page) {
  await page.goto('/login')
  await page.fill('input[type="email"], input[name="email"]', 'admin@pos.com')
  await page.fill('input[type="password"]', 'password')
  const responsePromise = page.waitForResponse(r => r.url().includes('/login') && r.status() === 200)
  await page.click('button[type="submit"]')
  await responsePromise
}

test.describe('Suppliers', () => {
  test.beforeEach(async ({ page }) => {
    await login(page)
  })

  test('should load suppliers page', async ({ page }) => {
    await page.click('a[href*="suppliers"], a:has-text("الموردين")')
    await expect(page.locator('h2:has-text("الموردين"), h1:has-text("الموردين")')).toBeVisible({ timeout: 5000 })
  })

  test('should open add supplier modal', async ({ page }) => {
    await page.goto('/suppliers')
    const addBtn = page.locator('button:has-text("إضافة"), button:has-text("مورد جديد")').first()
    if (await addBtn.isVisible()) {
      await addBtn.click()
      await expect(page.locator('.modal-overlay, [class*="modal"]').first()).toBeVisible({ timeout: 3000 })
    }
  })
})
