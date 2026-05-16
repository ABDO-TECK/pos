import { test, expect } from '@playwright/test'

async function login(page: import('@playwright/test').Page) {
  await page.goto('/login')
  await page.fill('input[type="email"], input[name="email"]', 'admin@pos.com')
  await page.fill('input[type="password"]', 'password')
  const rp = page.waitForResponse(r => r.url().includes('/login') && r.status() === 200)
  await page.click('button[type="submit"]')
  await rp
}

test.describe('Customers Page', () => {
  test.beforeEach(async ({ page }) => {
    await login(page)
    await page.goto('/customers')
  })

  test('should load customers page', async ({ page }) => {
    await expect(page.locator('h1:has-text("العملاء"), h2:has-text("العملاء")')).toBeVisible({ timeout: 5000 })
  })

  test('should open add customer dialog', async ({ page }) => {
    const addBtn = page.locator('button:has-text("إضافة"), button:has-text("عميل جديد")').first()
    if (await addBtn.isVisible()) {
      await addBtn.click()
      await expect(page.locator('.modal, [class*="modal"]').first()).toBeVisible({ timeout: 3000 })
    }
  })
})
