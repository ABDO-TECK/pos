import { test, expect } from '@playwright/test'

test.describe('Login Page', () => {
  test('should show login form', async ({ page }) => {
    await page.goto('/login')
    await expect(page.locator('input[type="email"]')).toBeVisible()
    await expect(page.locator('input[type="password"]')).toBeVisible()
  })

  test('should show error on invalid credentials', async ({ page }) => {
    await page.goto('/login')
    await page.fill('input[type="email"]', 'wrong@test.com')
    await page.fill('input[type="password"]', 'wrongpass')
    await page.click('button[type="submit"]')
    await expect(page.locator('[role="status"]')).toBeVisible({ timeout: 5000 })
  })
})
