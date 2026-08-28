import assert from 'node:assert/strict'
import fs from 'node:fs'
import test from 'node:test'

const account = fs.readFileSync('resources/js/components/UserSettings/UserSettingsAccount.vue', 'utf8')
const routes = fs.readFileSync('routes/web.php', 'utf8')
const privacy = fs.readFileSync('docs/release/mobile-privacy-and-data-deletion.md', 'utf8')
const mobileUi = fs.readFileSync('mobile/src/ui.ts', 'utf8')

test('account settings exposes a distinct password-confirmed permanent deletion flow', () => {
    assert.match(account, /永久删除账号/)
    assert.match(account, /delete my account/)
    assert.match(account, /type="password"/)
    assert.match(account, /axios\.delete\('\/users\/account'/)
    assert.match(account, /window\.location\.assign\('\/login'\)/)
    assert.match(account, /恢复备份/)
    assert.match(routes, /Route::delete\('\/users\/account'/)
})

test('account deletion does not reuse the narrower english-data deletion endpoint', () => {
    const accountDeleteMethod = account.match(/deleteAccount\(\) \{[\s\S]*?\n            \}/)?.[0] ?? ''
    assert.match(accountDeleteMethod, /\/users\/account/)
    assert.doesNotMatch(accountDeleteMethod, /delete-language-data/)
})

test('privacy copy describes web self-service deletion and backup retention honestly', () => {
    assert.match(privacy, /Web.*account deletion|account deletion.*Web/i)
    assert.match(privacy, /backup/i)
    assert.match(privacy, /not.*rewrite|not rewritten|不会.*改写/i)
})

test('mobile device revoke still clears local credentials and offline state when the server is unavailable', () => {
    const logout = mobileUi.match(/private async logout\(\): Promise<void> \{[\s\S]*?\n  \}/)?.[0] ?? ''
    assert.match(logout, /await this\.api\?\.revoke\(deviceUuid\)/)
    assert.match(logout, /catch \{[\s\S]*Local credential deletion must still complete/)
    assert.match(logout, /this\.offlineRepository\?\.clear\(\)/)
    assert.match(logout, /this\.mediaCache\.clear\(\)/)
    assert.match(logout, /await clearToken\(\)/)
    assert.match(logout, /this\.api = null/)
    assert.match(logout, /this\.bootstrap = null/)
})

test('current mobile clients remain existing-account login only', () => {
    const login = mobileUi.match(/private renderLogin\([\s\S]*?\n  \}/)?.[0] ?? ''
    assert.match(login, /id="login-form"/)
    assert.doesNotMatch(login, /create account|sign up|register/i)
    assert.match(privacy, /Android and iOS clients[\s\S]*do not offer or link to account creation/i)
})
