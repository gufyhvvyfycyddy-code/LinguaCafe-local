export function resolveLoginError(error) {
    const response = error?.response;
    const status = response?.status;
    const code = response?.data?.error?.code;

    if (status === 401 || code === 'INVALID_CREDENTIALS') {
        return '邮箱或密码不正确。';
    }

    if (status === 419) {
        return '登录状态已过期，请刷新页面后重试。';
    }

    if (status === 422) {
        return '请检查邮箱和密码格式。';
    }

    if (status === 429 || code === 'LOGIN_RATE_LIMITED') {
        return '登录尝试次数过多，请稍后再试。';
    }

    if (status === 503 && code === 'RESTORE_WRITE_FENCE_ACTIVE') {
        return '系统正在恢复数据，请稍后再登录。';
    }

    if (status === 503) {
        return '系统暂时无法登录，请稍后重试。';
    }

    if (!response) {
        return '无法连接服务器，请检查网络后重试。';
    }

    return '登录失败，请稍后重试。';
}
