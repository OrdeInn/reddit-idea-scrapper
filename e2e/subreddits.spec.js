import { test, expect } from '@playwright/test'
import { deleteSubreddit, extractSubredditId } from './helpers.js'

test.describe('Subreddit Management', () => {
    let subredditId = null

    /**
     * Create a subreddit via the modal and navigate to its page.
     * Stores the created subreddit ID so afterEach can clean it up.
     */
    async function createAndNavigateToSubreddit(page, name) {
        await page.goto('/')
        await page.getByRole('button', { name: /Add Subreddit/i }).first().click()

        const input = page.getByRole('dialog').locator('input').first()
        await input.fill(name)
        await page.getByRole('dialog').getByRole('button', { name: /Add|Submit|Save/i }).click()

        await page.waitForURL(/\/subreddits\/\d+/, { timeout: 8000 })
        subredditId = extractSubredditId(page.url())
    }

    test.afterEach(async ({ page }) => {
        if (subredditId) {
            await deleteSubreddit(page, subredditId)
            subredditId = null
        }
    })

    test('subreddit page shows the correct name', async ({ page }) => {
        const name = 'e2etestname' + Date.now()
        await createAndNavigateToSubreddit(page, name)

        await expect(page.getByText(new RegExp(name, 'i'))).toBeVisible()
    })

    test('subreddit page shows Start Scan button', async ({ page }) => {
        const name = 'e2etestscan' + Date.now()
        await createAndNavigateToSubreddit(page, name)

        await expect(page.getByRole('button', { name: /Start Scan|Scan/i }).first()).toBeVisible()
    })

    test('scan config modal opens when clicking Start Scan', async ({ page }) => {
        const name = 'e2etestmodal' + Date.now()
        await createAndNavigateToSubreddit(page, name)

        await page.getByRole('button', { name: /Start Scan|Scan/i }).first().click()

        await expect(page.getByRole('dialog')).toBeVisible({ timeout: 3000 })
    })

    test('navigating back to dashboard shows the added subreddit', async ({ page }) => {
        const name = 'e2etestback' + Date.now()
        await createAndNavigateToSubreddit(page, name)

        await page.goto('/')

        await expect(page.getByText(new RegExp(name, 'i'))).toBeVisible()
    })

    test('can delete a subreddit', async ({ page }) => {
        const name = 'e2etestdelete' + Date.now()
        await createAndNavigateToSubreddit(page, name)

        await page.getByRole('button', { name: /Remove subreddit/i }).click()
        await page.getByRole('button', { name: /^Delete$/i }).click()

        await page.waitForURL('/', { timeout: 5000 })

        // Deleted via UI — no afterEach cleanup needed
        subredditId = null

        // Verify the subreddit card link is gone from the dashboard
        // (toast flash messages may still contain the name, so we target links specifically)
        await expect(page.getByRole('link', { name: new RegExp(`r/${name}`, 'i') })).not.toBeVisible()
    })
})
