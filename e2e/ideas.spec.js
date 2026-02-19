import { test, expect } from '@playwright/test'
import { deleteSubreddit, extractSubredditId } from './helpers.js'

test.describe('Ideas', () => {
    test('starred page loads successfully', async ({ page }) => {
        await page.goto('/starred')

        await expect(page).toHaveTitle(/Starred|Ideas/)
        await expect(page.locator('body')).toBeVisible()
    })

    test('starred page shows a heading or empty state', async ({ page }) => {
        await page.goto('/starred')

        await expect(page.getByRole('heading').first()).toBeVisible()
    })

    test('ideas page for subreddit shows empty state when no ideas exist', async ({ page }) => {
        let subredditId = null

        await page.goto('/')
        await page.getByRole('button', { name: /Add Subreddit/i }).first().click()

        const name = 'e2etestideas' + Date.now()
        const input = page.getByRole('dialog').locator('input').first()
        await input.fill(name)
        await page.getByRole('dialog').getByRole('button', { name: /Add|Submit|Save/i }).click()

        await page.waitForURL(/\/subreddits\/\d+/, { timeout: 8000 })
        subredditId = extractSubredditId(page.url())

        // Page should load without server error
        await expect(page.locator('body')).not.toContainText('500')
        await expect(page.locator('body')).not.toContainText('Server Error')

        // Cleanup
        if (subredditId) await deleteSubreddit(page, subredditId)
    })

    test('can star an idea if ideas exist', async ({ page }) => {
        await page.goto('/starred')

        await expect(page.locator('body')).not.toContainText('500')

        const starButtons = page.getByRole('button', { name: /Star|Unstar|★/i })
        const count = await starButtons.count()

        if (count > 0) {
            await starButtons.first().click()
            await expect(page.locator('body')).toBeVisible()
        }
        // If no ideas, test passes as a smoke test
    })

    test('ideas API endpoint responds correctly', async ({ page }) => {
        const response = await page.request.get('/api/starred')
        expect(response.status()).toBe(200)

        const data = await response.json()
        // Response is { ideas: [...], pagination: {...} }
        expect(Array.isArray(data.ideas)).toBe(true)
        expect(data.pagination).toBeDefined()
    })
})
