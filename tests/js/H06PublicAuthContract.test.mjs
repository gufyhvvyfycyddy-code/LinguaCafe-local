import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const userController = fs.readFileSync(path.join(root, 'app/Http/Controllers/UserController.php'), 'utf8');
const loginRequest = fs.readFileSync(path.join(root, 'app/Http/Requests/Auth/LoginRequest.php'), 'utf8');
const authRoutes = fs.readFileSync(path.join(root, 'routes/auth.php'), 'utf8');
const webRoutes = fs.readFileSync(path.join(root, 'routes/web.php'), 'utf8');
const registeredUserController = path.join(root, 'app/Http/Controllers/Auth/RegisteredUserController.php');
const loginForm = fs.readFileSync(path.join(root, 'resources/js/components/Login/LoginForm.vue'), 'utf8');

const legacyLoginRequest = path.join(root, 'app/Http/Requests/Users/AuthenticateUserRequest.php');

test('public login has one active request-policy owner', () => {
    assert.match(userController, /use App\\Http\\Requests\\Auth\\LoginRequest;/);
    assert.match(userController, /authenticateUser\(LoginRequest \$request\)/);
    assert.match(userController, /\$request->authenticate\(\)/);
    assert.equal(fs.existsSync(legacyLoginRequest), false);
});

test('login policy uses two independent Laravel rate-limiter keys and generic public error envelopes', () => {
    assert.match(loginRequest, /login:account:'\.Str::transliterate/);
    assert.match(loginRequest, /login:ip:'\.\$this->ip\(\)/);
    assert.match(loginRequest, /RateLimiter::tooManyAttempts\(\$this->accountKey\(\), 5\)/);
    assert.match(loginRequest, /RateLimiter::tooManyAttempts\(\$this->ipKey\(\), 25\)/);
    assert.match(loginRequest, /RateLimiter::hit\(\$this->accountKey\(\), 60\)/);
    assert.match(loginRequest, /RateLimiter::hit\(\$this->ipKey\(\), 60\)/);
    assert.match(loginRequest, /RateLimiter::clear\(\$this->accountKey\(\)\)/);
    assert.match(loginRequest, /'code' => 'INVALID_CREDENTIALS'/);
    assert.match(loginRequest, /'code' => 'LOGIN_RATE_LIMITED'/);
});

test('successful login still rotates the session and preserves the existing response contract', () => {
    assert.match(userController, /\$request->session\(\)->regenerate\(\)/);
    assert.match(userController, /User has been logged in successfully/);
});

test('public password reset and email verification routes remain intentionally unexposed', () => {
    assert.doesNotMatch(authRoutes, /Route::post\('\/forgot-password'/);
    assert.doesNotMatch(authRoutes, /Route::post\('\/reset-password'/);
    assert.doesNotMatch(authRoutes, /Route::get\('\/verify-email/);
    assert.match(authRoutes, /outbound-mail\/recovery flow/);
});

test('POST /login is guarded by the guest middleware', () => {
    assert.match(webRoutes, /Route::post\('\/login',[^)]*\)->middleware\('guest'\)/);
});

test('duplicate Auth RegisteredUserController has been removed', () => {
    assert.equal(fs.existsSync(registeredUserController), false);
});

test('B-010 public login/setup/register pages do not fetch protected font settings', () => {
    const source = fs.readFileSync(path.join(root, 'resources/js/components/Layout.vue'), 'utf8');
    const script = source.match(/<script>([\s\S]*?)<\/script>/)?.[1];
    assert.ok(script, 'Layout Vue script must exist');
    const sandbox = {
        FontTypeService: class {
            constructor() { sandbox.fontRequests++; }
        },
        fontRequests: 0,
    };
    vm.runInNewContext(
        script.replace(/^\s*import [^\n]*\r?\n/gm, '').replace('export default', 'globalThis.layout ='),
        sandbox,
    );
    for (const publicPath of ['/login', '/setup', '/register']) {
        sandbox.layout.mounted.call({ $router: { currentRoute: { path: publicPath } }, selectedLanguage: 'english' });
    }
    assert.equal(sandbox.fontRequests, 0, 'public routes must not instantiate the authenticated font service');
    sandbox.layout.mounted.call({ $router: { currentRoute: { path: '/books' } }, selectedLanguage: 'english' });
    assert.equal(sandbox.fontRequests, 1, 'authenticated reader still loads fonts');
    assert.match(webRoutes, /Route::get\('\/fonts\/get-fonts-for-language\/\{language\}'/);
});

test('O-003 public auth screens do not start realtime sockets; private screens keep existing behavior', () => {
    const source = fs.readFileSync(path.join(root, 'resources/js/vuex/Shared.js'), 'utf8');
    const code = source.replace(/^\s*import [^\n]*\r?\n/gm, '')
        .replace('export default', 'globalThis.shared =');
    for (const page of ['/login', '/setup', '/register', '/books']) {
        let connections = 0;
        class EchoStub {
            constructor() {
                connections++;
                this.connector = { pusher: { connection: { bind() {} } } };
            }
        }
        const sandbox = { window: { location: { pathname: page } }, Pusher: {}, Echo: EchoStub, console };
        vm.runInNewContext(code, sandbox);
        const state = sandbox.shared.state();
        assert.ok(state.echo, 'Vuex state has a safe echo object');
        assert.equal(connections, page === '/books' ? 1 : 0, page);
    }
});

test('login and account-creation fields provide password-manager autocomplete hints', () => {
    assert.match(loginForm, /autocomplete="username"/);
    assert.match(loginForm, /autocomplete="current-password"/);
    assert.match(loginForm, /autocomplete="email"/);
    assert.match(loginForm, /autocomplete="new-password"/);
});
