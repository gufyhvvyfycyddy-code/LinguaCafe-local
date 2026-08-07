export const languageNames = {
    english: '英语',
    japanese: '日语',
    chinese: '中文',
    spanish: '西班牙语',
    french: '法语',
    german: '德语',
    korean: '韩语',
    italian: '意大利语',
    russian: '俄语',
    portuguese: '葡萄牙语',
    norwegian: '挪威语',
    thai: '泰语',
    welsh: '威尔士语',
};

export function languageName(language) {
    return languageNames[String(language || '').toLowerCase()] || language;
}

export function requestErrorMessage(error, fallback = '请求失败，请稍后重试。') {
    const data = error?.response?.data;

    if (typeof data?.error?.message === 'string' && data.error.message.trim() !== '') {
        return data.error.message;
    }

    if (typeof data?.message === 'string' && data.message.trim() !== '') {
        return data.message;
    }

    if (typeof data === 'string' && data.trim() !== '') {
        return data;
    }

    if (typeof error?.message === 'string' && error.message.trim() !== '') {
        return error.message;
    }

    return fallback;
}

export function requestValidationErrorMessage(error, fallback = '保存失败，请稍后重试。') {
    const data = error?.response?.data;
    const errors = data?.errors;

    if (errors && typeof errors === 'object') {
        const messages = Object.values(errors).flatMap((value) => {
            if (Array.isArray(value)) return value;
            if (typeof value === 'string') return [value];
            return [];
        }).filter((value) => typeof value === 'string' && value.trim() !== '');

        if (messages.length > 0) return messages.join('\n');
    }

    if (typeof data?.error?.message === 'string' && data.error.message.trim() !== '') {
        return data.error.message;
    }

    if (typeof data?.message === 'string' && data.message.trim() !== '') {
        return data.message;
    }

    if (typeof data === 'string' && data.trim() !== '') {
        return data;
    }

    return fallback;
}

export function formatChineseMonth(momentDate) {
    return `${momentDate.year()}年${momentDate.month() + 1}月`;
}

export const goalNames = {
    Reviews: '复习',
    Reading: '阅读',
    'New words': '新词',
    review: '复习',
    read_words: '阅读',
    learn_words: '新词',
    reviews_due: '到期复习',
};

export function goalName(name) {
    return goalNames[name] || name;
}