import http from 'k6/http';
import { check } from 'k6';
import exec from 'k6/execution';
import { SharedArray } from 'k6/data';

const baseUrl = (__ENV.H02_BASE_URL || '').replace(/\/$/, '');
const vus = Number.parseInt(__ENV.H02_VUS || '1', 10);

if (!baseUrl) {
    throw new Error('H02_BASE_URL is required');
}

const fixtures = new SharedArray('h02-representative-fixtures', function () {
    if (!__ENV.H02_FIXTURES_JSON) {
        throw new Error('H02_FIXTURES_JSON is required');
    }

    return JSON.parse(__ENV.H02_FIXTURES_JSON);
});

function fixtureForCurrentVu() {
    const vu = exec.vu;
    const fixture = fixtures[vu.idInTest - 1];

    if (!fixture) {
        throw new Error(`No H-02 fixture for VU ${vu.idInTest}`);
    }

    return fixture;
}

function login(fixture) {
    const loginPage = http.get(`${baseUrl}/login`, {
        headers: { Accept: 'text/html' },
        tags: { flow: 'h02_login_page' },
    });

    if (!check(loginPage, {
        'login page loads': (response) => response.status === 200,
    })) {
        throw new Error(`GET /login failed with status ${loginPage.status}`);
    }

    const tokenMatch = loginPage.body.match(
        /<meta[^>]*name=["']csrf-token["'][^>]*content=["']([^"']+)["']/i
    );

    if (!tokenMatch) {
        throw new Error('CSRF token was not found on the login page');
    }

    const csrfToken = tokenMatch[1];
    const loginResponse = http.post(`${baseUrl}/login`, JSON.stringify({
        email: fixture.email,
        password: fixture.password,
        remember: true,
    }), {
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': csrfToken,
        },
        tags: { flow: 'h02_login' },
    });

    if (!check(loginResponse, {
        'web login succeeds': (response) => response.status >= 200 && response.status < 400,
    })) {
        throw new Error(`POST /login failed with status ${loginResponse.status}`);
    }

    const refreshedXsrfCookies = loginResponse.cookies['XSRF-TOKEN'] || [];
    if (!refreshedXsrfCookies[0] || !refreshedXsrfCookies[0].value) {
        throw new Error('The login response did not refresh the XSRF-TOKEN cookie');
    }

    return decodeURIComponent(refreshedXsrfCookies[0].value);
}

function requestParams(flow, csrfToken) {
    return {
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': csrfToken,
        },
        tags: { flow },
    };
}

function assertSuccessful(response, label) {
    check(response, {
        [label]: (result) => result.status >= 200 && result.status < 300,
    });
}

export function reading() {
    const fixture = fixtureForCurrentVu();
    const csrfToken = login(fixture);
    const response = http.post(
        `${baseUrl}/chapters/get/reader`,
        JSON.stringify({ chapterId: fixture.chapter_id }),
        requestParams('h02_reading', csrfToken)
    );

    assertSuccessful(response, 'reading request succeeds');
}

export function lookup() {
    const fixture = fixtureForCurrentVu();
    const csrfToken = login(fixture);
    const lookupUrl = `${baseUrl}/senses/known-sense-lookup?lemma=${encodeURIComponent(fixture.lemma)}&language=${encodeURIComponent(fixture.language)}`;
    const response = http.get(lookupUrl, requestParams('h02_lookup', csrfToken));

    assertSuccessful(response, 'lookup request succeeds');
}

export function senseReview() {
    const fixture = fixtureForCurrentVu();
    const csrfToken = login(fixture);
    const response = http.post(
        `${baseUrl}/reviews/senses/${fixture.review_card_id}/rate`,
        JSON.stringify({ rating: 'good' }),
        requestParams('h02_sense_review', csrfToken)
    );

    assertSuccessful(response, 'sense review rating succeeds');
}

export const options = {
    scenarios: {
        reading: {
            executor: 'per-vu-iterations',
            vus,
            iterations: 1,
            exec: 'reading',
        },
        lookup: {
            executor: 'per-vu-iterations',
            vus,
            iterations: 1,
            exec: 'lookup',
        },
        senseReview: {
            executor: 'per-vu-iterations',
            vus,
            iterations: 1,
            exec: 'senseReview',
        },
    },
};
