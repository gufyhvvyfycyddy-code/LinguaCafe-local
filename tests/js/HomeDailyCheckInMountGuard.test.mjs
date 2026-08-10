import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const home = readFileSync(
    new URL('../../resources/js/components/Home/Home.vue', import.meta.url),
    'utf8',
);
const checkIn = readFileSync(
    new URL('../../resources/js/components/Home/HomeDailyCheckIn.vue', import.meta.url),
    'utf8',
);
const app = readFileSync(new URL('../../resources/js/app.js', import.meta.url), 'utf8');
const layout = readFileSync(new URL('../../resources/js/components/Layout.vue', import.meta.url), 'utf8');

assert.match(checkIn, /export\s+default/, 'The locally imported HomeDailyCheckIn component must exist.');

assert.match(
    home,
    /import\s+HomeDailyCheckIn\s+from\s+['"]\.\/HomeDailyCheckIn\.vue['"];?/,
    'Home must import HomeDailyCheckIn locally.',
);
assert.match(
    home,
    /components\s*:\s*\{[\s\S]*?HomeDailyCheckIn[\s\S]*?\}/,
    'Home must register HomeDailyCheckIn locally.',
);

const passwordBlock = home.indexOf('<template v-if="!passwordChanged">');
const passwordBlockEnd = home.indexOf('</template>', passwordBlock);
const dailyCheckIn = home.indexOf('<home-daily-check-in>');
const calendar = home.indexOf('<calendar');
const goals = home.indexOf('<goals');
const statistics = home.indexOf('<statistics');
const about = home.indexOf('关于');

assert.ok(passwordBlock >= 0 && passwordBlockEnd > passwordBlock, 'Home must keep the conditional password reminder.');
assert.ok(dailyCheckIn > passwordBlockEnd, 'HomeDailyCheckIn must mount after the password reminder block.');
assert.ok(dailyCheckIn < calendar, 'HomeDailyCheckIn must mount before Calendar.');
assert.ok(calendar < goals && goals < statistics && statistics < about, 'Legacy Home sections must keep Calendar → Goals → Statistics → About order.');
assert.equal((home.match(/<home-daily-check-in\b/g) || []).length, 1, 'HomeDailyCheckIn must mount exactly once.');

for (const [name, pattern] of [
    ['Calendar', /<calendar\b/g],
    ['Goals', /<goals\b/g],
    ['Statistics', /<statistics\b/g],
]) {
    const mounts = home.match(pattern) || [];
    assert.equal(mounts.length, 1, `${name} must remain mounted exactly once on Home.`);
}

assert.doesNotMatch(app, /HomeDailyCheckIn|home-daily-check-in/, 'HomeDailyCheckIn must not be globally registered in app.js.');
assert.doesNotMatch(layout, /HomeDailyCheckIn|home-daily-check-in/, 'Layout must not own the HomeDailyCheckIn mount.');

console.log('Home Daily Check-In mount guard passed.');
