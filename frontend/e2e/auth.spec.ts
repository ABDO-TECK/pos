import { test, expect } from '@playwright/test'

test.describe('Authentication', () => {
  test('should show login page', async ({ page }) => {
    await page.goto('/login')
    await expect(page.locator('input[type="email"], input[name="email"], input[placeholder*="email" i]')).toBeVisible()
    await expect(page.locator('input[type="password"]')).toBeVisible()
  })

  test('should reject invalid credentials', async ({ page }) => {
    await page.goto('/login')
    await page.fill('input[type="email"], input[name="email"]', 'wrong@test.com')
    await page.fill('input[type="password"]', 'wrongpassword')
    await page.click('button[type="submit"]')
    // يجب أن تظهر رسالة خطأ (toast أو inline)
    await expect(page.locator('[role="status"]')).toBeVisible({ timeout: 5000 })
  })

  test('should redirect unauthenticated users to login', async ({ page }) => {
    await page.goto('/')
    await page.waitForURL('**/login**')
    expect(page.url()).toContain('/login')
  })
})
