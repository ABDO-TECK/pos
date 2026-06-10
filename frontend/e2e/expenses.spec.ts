import { test, expect } from '@playwright/test'

async function login(page: any) {
  await page.goto('/login')
  await page.fill('input[type="email"]', 'admin@pos.com')
  await page.fill('input[type="password"]', 'password')
  await page.click('button[type="submit"]')
  await page.waitForURL('**/pos', { timeout: 10000 })
}

test.describe('Expenses Page', () => {
  test.beforeEach(async ({ page }) => {
    await login(page)
    await page.goto('/expenses')
  })

  test('should show expenses table', async ({ page }) => {
    await expect(page.locator('table')).toBeVisible({ timeout: 10000 })
  })

  test('should open add expense modal', async ({ page }) => {
    // Assuming there's a button with text "إضافة مصروف" or an icon for add
    const addButton = page.getByRole('button', { name: /إضافة|add/i }).first()
    await addButton.click()
    
    // Check if modal opens (look for input field for amount)
    await expect(page.locator('input[type="number"]')).toBeVisible()
  })
})
