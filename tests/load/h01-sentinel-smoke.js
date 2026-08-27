import http from 'k6/http';
import { check, sleep } from 'k6';

const baseUrl = (__ENV.H01_BASE_URL || '').replace(/\/$/, '');
const summaryPath = __ENV.H01_K6_SUMMARY_PATH || 'h01-k6-summary.json';
const vus = Number.parseInt(__ENV.H01_VUS || '4', 10);
const duration = __ENV.H01_DURATION || '3s';

if (!baseUrl) {
    throw new Error('H01_BASE_URL is required');
}

export const options = {
    vus,
    duration,
    summaryTrendStats: ['avg', 'min', 'med', 'max', 'p(90)', 'p(95)', 'p(99)', 'count'],
    thresholds: {
        http_req_failed: ['rate==0'],
        checks: ['rate==1'],
    },
};

export default function () {
    const response = http.get(`${baseUrl}/__testing/acceptance-sentinel`, {
        tags: { flow: 'h01_sentinel_smoke' },
    });

    check(response, {
        'testing sentinel is healthy': (res) => {
            if (res.status !== 200) {
                return false;
            }

            try {
                const body = res.json();
                return body.environment === 'testing'
                    && body.database_is_testing === true
                    && body.sentinel_present === true;
            } catch (_) {
                return false;
            }
        },
    });

    sleep(0.05);
}

export function handleSummary(data) {
    return {
        [summaryPath]: JSON.stringify(data, null, 2),
    };
}
