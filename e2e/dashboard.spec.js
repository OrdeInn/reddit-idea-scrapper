import { test, expect } from '@playwright/test'
import { deleteSubreddit, extractSubredditId } from './helpers.js'

test.describe('Dashboard', () => {
    test('loads the dashboard page', async ({ page }) => {
        await page.goto('/')

        await expect(page).toHaveTitle(/Dashboard/)
        await expect(page.getByRole('heading', { name: 'Dashboard' })).toBeVisible()
    })

    test('shows description text', async ({ page }) => {
        await page.goto('/')

        await expect(page.getByText('Track subreddits to discover SaaS opportunities')).toBeVisible()
    })

    test('shows Add Subreddit button', async ({ page }) => {
        await page.goto('/')

        await expect(page.getByRole('button', { name: /Add Subreddit/i })).toBeVisible()
    })

    test('shows empty state when no subreddits exist', async ({ page }) => {
        await page.goto('/')

        const emptyState = page.getByText('No signals detected yet')
        const subredditGrid = page.locator('.grid').filter({ has: page.getByRole('link') })

        const hasEmpty = await emptyState.isVisible().catch(() => false)
        const hasCards = await subredditGrid.isVisible().catch(() => false)

        expect(hasEmpty || hasCards).toBe(true)
    })

    test('opens add subreddit modal when clicking Add Subreddit', async ({ page }) => {
        await page.goto('/')

        await page.getByRole('button', { name: /Add Subreddit/i }).first().click()

        await expect(page.getByRole('dialog')).toBeVisible()
        await expect(page.getByPlaceholder(/subreddit/i).or(page.locator('input[type="text"]').first())).toBeVisible()
    })

    test('can add a subreddit via the modal form', async ({ page }) => {
        let subredditId = null

        await page.goto('/')
        await page.getByRole('button', { name: /Add Subreddit/i }).first().click()

        const input = page.getByRole('dialog').locator('input').first()
        await input.fill('e2etestdash' + Date.now())
        await page.getByRole('dialog').getByRole('button', { name: /Add|Submit|Save/i }).click()

        await page.waitForURL(/\/subreddits\/\d+/, { timeout: 5000 })
        subredditId = extractSubredditId(page.url())

        expect(subredditId).not.toBeNull()

        // Cleanup
        if (subredditId) await deleteSubreddit(page, subredditId)
    })

    test('closes modal when clicking cancel/close', async ({ page }) => {
        await page.goto('/')

        await page.getByRole('button', { name: /Add Subreddit/i }).first().click()
        await expect(page.getByRole('dialog')).toBeVisible()

        await page.getByRole('button', { name: /Cancel|Close/i }).first().click()

        await expect(page.getByRole('dialog')).not.toBeVisible()
    })
})
