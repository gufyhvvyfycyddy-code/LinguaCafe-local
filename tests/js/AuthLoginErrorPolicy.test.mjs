import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';

import { resolveLoginError } from '../../resources/js/services/AuthLoginErrorPolicy.js';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const loginFormSource = fs.readFileSync(
    path.join(root, 'resources/js/components/Login/LoginForm.vue'),
    'utf8',
);

test('invalid credentials have a specific message', () => {
    assert.equal(resolveLoginError({
        response: {
            status: 401,
            data: { error: { code: 'INVALID_CREDENTIALS' } },
        },
    }), '邮箱或密码不正确。');
});

test('expired CSRF sessions are not mislabeled as bad passwords', () => {
    assert.equal(resolveLoginError({ response: { status: 419, data: {} } }), '登录状态已过期，请刷新页面后重试。');
});

test('validation failures use an input-specific message', () => {
    assert.equal(resolveLoginError({ response: { status: 422, data: {} } }), '请检查邮箱和密码格式。');
});

test('database recovery has an actionable message', () => {
    assert.equal(resolveLoginError({
        response: {
            status: 503,
            data: { error: { code: 'RESTORE_WRITE_FENCE_ACTIVE' } },
        },
    }), '系统正在恢复数据，请稍后再登录。');
});

test('other temporary unavailability does not claim the password is wrong', () => {
    assert.equal(resolveLoginError({ response: { status: 503, data: {} } }), '系统暂时无法登录，请稍后重试。');
});

test('network failures and unknown server failures have separate messages', () => {
    assert.equal(resolveLoginError({}), '无法连接服务器，请检查网络后重试。');
    assert.equal(resolveLoginError({ response: { status: 500, data: {} } }), '登录失败，请稍后重试。');
});

test('login form delegates rejected requests to the error policy', () => {
    assert.equal(loginFormSource.includes("import { resolveLoginError } from '../../services/AuthLoginErrorPolicy';"), true);
    assert.equal(loginFormSource.includes('.catch((error) => {'), true);
    assert.equal(loginFormSource.includes('this.error = resolveLoginError(error);'), true);
});
