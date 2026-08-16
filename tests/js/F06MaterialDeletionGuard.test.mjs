import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'

const read = (path) => readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8')

test('material deletion previews impact and requires explicit confirmation', () => {
    const dialog = read('resources/js/components/Library/DeleteBookDialog.vue')
    const library = read('resources/js/components/Library/Library.vue')
    const request = read('app/Http/Requests/Books/DeleteBookRequest.php')

    assert.match(dialog, /mode:\s*['"]preview['"]/)
    assert.match(dialog, /source_occurrence_count/)
    assert.match(dialog, /review_log_count/)
    assert.match(dialog, /reading_session_count/)
    assert.match(dialog, /v-model="acknowledged"/)
    assert.match(dialog, /this\.impact !== null && this\.acknowledged/)
    assert.match(library, /['"]mode['"]:\s*['"]delete['"]/)
    assert.match(library, /['"]confirmImpact['"]:\s*true/)
    assert.match(request, /exclude_unless:mode,delete\|required\|accepted/)
})

test('book deletion retains source and learning history rows', () => {
    const service = read('app/Services/BookService.php')
    const deleteBody = service.slice(service.indexOf('public function deleteBook'))

    for (const protectedModel of ['WordSenseOccurrence', 'ReviewCard', 'ReviewLog', 'ReadingSession']) {
        assert.doesNotMatch(deleteBody, new RegExp(`${protectedModel}[^;]*::[^;]*delete\\s*\\(`))
    }
    assert.match(deleteBody, /DB::transaction/)
    assert.match(deleteBody, /lockForUpdate/)
    assert.match(deleteBody, /Chapter[\s\S]*->delete\(\)/)
    assert.match(deleteBody, /\$book->delete\(\)/)
})
