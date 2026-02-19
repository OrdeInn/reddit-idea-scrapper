/**
 * Deletes a subreddit via the UI (navigates to its page, clicks Remove, confirms).
 * @param {import('@playwright/test').Page} page
 * @param {string} subredditId
 */
export async function deleteSubreddit(page, subredditId) {
    await page.goto(`/subreddits/${subredditId}`)
    await page.getByRole('button', { name: /Remove subreddit/i }).click()
    await page.getByRole('button', { name: /^Delete$/i }).click()
    await page.waitForURL('/', { timeout: 5000 })
}

/**
 * Extracts the subreddit ID from a /subreddits/:id URL.
 * Returns null if the URL doesn't match.
 * @param {string} url
 * @returns {string|null}
 */
export function extractSubredditId(url) {
    return url.match(/\/subreddits\/(\d+)/)?.[1] ?? null
}
