import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';

import {
    requestErrorMessage,
    requestValidationErrorMessage,
} from '../../resources/js/services/UiTextService.js';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const editUserSource = fs.readFileSync(
    path.join(root, 'resources/js/components/Admin/AdminEditUserDialog.vue'),
    'utf8',
);
const changePasswordSource = fs.readFileSync(
    path.join(root, 'resources/js/components/UserSettings/ChangePasswordDialog.vue'),
    'utf8',
);

test('shared request errors understand structured error envelopes without rendering objects', () => {
    assert.equal(requestErrorMessage({
        response: { data: { error: { code: 'RESTORE_WRITE_FENCE_ACTIVE', message: '系统正在恢复数据库。' } } },
    }), '系统正在恢复数据库。');
    assert.equal(requestErrorMessage({ response: { data: {} } }, '请求失败。'), '请求失败。');
    assert.notEqual(requestErrorMessage({ response: { data: {} } }, '请求失败。'), '[object Object]');
});

test('validation errors are flattened without HTML', () => {
    assert.equal(requestValidationErrorMessage({
        response: {
            data: {
                errors: {
                    email: ['邮箱已被使用。'],
                    name: ['姓名太短。', '姓名格式不正确。'],
                },
            },
        },
    }), '邮箱已被使用。\n姓名太短。\n姓名格式不正确。');
});

test('structured domain messages and primitive payloads are supported', () => {
    assert.equal(requestValidationErrorMessage({
        response: { data: { error: { message: '系统必须保留管理员。' } } },
    }), '系统必须保留管理员。');
    assert.equal(requestValidationErrorMessage({
        response: { data: { message: '请求已失效。' } },
    }), '请求已失效。');
    assert.equal(requestValidationErrorMessage({ response: { data: '服务暂时不可用。' } }), '服务暂时不可用。');
});

test('network and unknown failures use safe fallbacks', () => {
    assert.equal(requestValidationErrorMessage({}, '保存失败，请稍后重试。'), '保存失败，请稍后重试。');
    assert.equal(requestValidationErrorMessage({ message: 'Network Error' }, '保存失败，请稍后重试。'), '保存失败，请稍后重试。');
    assert.equal(requestValidationErrorMessage({ response: { data: {} } }, '保存失败，请稍后重试。'), '保存失败，请稍后重试。');
});

test('user dialogs use the shared policy and do not render server text as HTML', () => {
    for (const source of [editUserSource, changePasswordSource]) {
        assert.match(source, /requestValidationErrorMessage/);
        assert.doesNotMatch(source, /error\.response\.data\.errors/);
        assert.doesNotMatch(source, /v-html=/);
    }
});

test('password change visibly requires the current password and sends it to the protected endpoint', () => {
    assert.match(changePasswordSource, /v-model="currentPassword"/);
    assert.match(changePasswordSource, /autocomplete="current-password"/);
    assert.match(changePasswordSource, /current_password:\s*this\.currentPassword/);
    assert.match(changePasswordSource, /请输入当前密码/);

});