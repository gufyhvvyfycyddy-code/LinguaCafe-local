import http from 'k6/http';
import { check } from 'k6';
import exec from 'k6/execution';
import { SharedArray } from 'k6/data';
import { Trend } from 'k6/metrics';

export function splitScenarioVus(totalVus) {
    if (!Number.isInteger(totalVus) || totalVus < 1) {
        throw new Error('H02_INVALID_VUS: H02_VUS must be an integer >= 1');
    }

    const perScenarioVus = Math.floor(totalVus / 3);
    const remainder = totalVus % 3;

    return [0, 1, 2].map((index) => perScenarioVus + (index < remainder ? 1 : 0));
}

const baseUrl = (__ENV.H02_BASE_URL || '').replace(/\/$/, '');
const summaryPath = __ENV.H02_K6_SUMMARY_PATH || 'h02-k6-summary.json';
const loginPageDuration = new Trend('h03_login_page_duration', true);
const loginPostDuration = new Trend('h03_login_post_duration', true);
const readingDuration = new Trend('h03_reading_duration', true);
const lookupDuration = new Trend('h03_lookup_duration', true);
const senseReviewDuration = new Trend('h03_sense_review_duration', true);
const vus = Number(__ENV.H02_VUS || '1');
const [readingVus, lookupVus, senseReviewVus] = splitScenarioVus(vus);

if (!baseUrl) {
    throw new Error('H02_BASE_URL is required');
}

const fixtures = new SharedArray('h02-representative-fixtures', function () {
    const fixturesJson = __ENV.H02_FIXTURES_JSON || '';
    const fixturesPath = __ENV.H02_FIXTURES_PATH || '';
    if ((fixturesJson === '') === (fixturesPath === '')) {
        throw new Error('Exactly one of H02_FIXTURES_JSON or H02_FIXTURES_PATH is required');
    }

    return JSON.parse(fixturesPath !== '' ? open(fixturesPath) : fixturesJson);
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
    loginPageDuration.add(loginPage.timings.duration);

    if (!check(loginPage, {
        'login page loads': (response) => response.status === 200,
    })) {
        throw new Error(`GET /login failed with status ${loginPage.status}`);
    }

    const loginXsrfCookies = loginPage.cookies['XSRF-TOKEN'] || [];
    if (!loginXsrfCookies[0] || !loginXsrfCookies[0].value) {
        throw new Error('XSRF-TOKEN cookie was not found on the login page');
    }

    const csrfToken = decodeURIComponent(loginXsrfCookies[0].value);
    const loginResponse = http.post(`${baseUrl}/login`, JSON.stringify({
        email: fixture.email,
        password: fixture.password,
        remember: true,
    }), {
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': csrfToken,
        },
        tags: { flow: 'h02_login' },
    });
    loginPostDuration.add(loginResponse.timings.duration);

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
            'X-XSRF-TOKEN': csrfToken,
        },
        tags: { flow },
    };
}

function assertSuccessful(response, label) {
    if (!check(response, {
        [label]: (result) => result.status >= 200 && result.status < 300,
    })) {
        const body = String(response.body || '').replace(/\s+/g, ' ').slice(0, 500);
        throw new Error(`${label}: status ${response.status}; body ${body}`);
    }
}

export function reading() {
    const fixture = fixtureForCurrentVu();
    const csrfToken = login(fixture);
    const response = http.post(
        `${baseUrl}/chapters/get/reader`,
        JSON.stringify({ chapterId: fixture.chapter_id }),
        requestParams('h02_reading', csrfToken)
    );
    readingDuration.add(response.timings.duration);

    assertSuccessful(response, 'reading request succeeds');
}

export function lookup() {
    const fixture = fixtureForCurrentVu();
    const csrfToken = login(fixture);
    const lookupUrl = `${baseUrl}/senses/known-sense-lookup?lemma=${encodeURIComponent(fixture.lemma)}&language=${encodeURIComponent(fixture.language)}`;
    const response = http.get(lookupUrl, requestParams('h02_lookup', csrfToken));
    lookupDuration.add(response.timings.duration);

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
    senseReviewDuration.add(response.timings.duration);

    assertSuccessful(response, 'sense review rating succeeds');
}

const scenarios = {};

if (readingVus > 0) {
    scenarios.reading = {
        executor: 'per-vu-iterations',
        vus: readingVus,
        iterations: 1,
        exec: 'reading',
    };
}
if (lookupVus > 0) {
    scenarios.lookup = {
        executor: 'per-vu-iterations',
        vus: lookupVus,
        iterations: 1,
        exec: 'lookup',
    };
}
if (senseReviewVus > 0) {
    scenarios.senseReview = {
        executor: 'per-vu-iterations',
        vus: senseReviewVus,
        iterations: 1,
        exec: 'senseReview',
    };
}

export const options = {
    scenarios,
    summaryTrendStats: ['avg', 'min', 'med', 'max', 'p(90)', 'p(95)', 'p(99)', 'count'],
    thresholds: {
        http_req_failed: ['rate==0'],
        checks: ['rate==1'],
    },
};

export function handleSummary(data) {
    return {
        [summaryPath]: JSON.stringify(data, null, 2),
    };
}
