import assert from 'node:assert/strict'
import fs from 'node:fs'
import path from 'node:path'

const root = process.cwd()
const components = [
    'resources/js/components/Text/VocabularySideBox.vue',
    'resources/js/components/Text/VocabularyBox.vue',
    'resources/js/components/Text/VocabularyBottomSheet.vue',
]

function inputChangedBody(source, file) {
    const match = source.match(/^\s+inputChanged\(inputName = ''\) \{/m)
    assert.ok(match, `${file}: inputChanged must exist`)
    const start = match.index

    let depth = 0
    for (let index = source.indexOf('{', start); index < source.length; index += 1) {
        if (source[index] === '{') depth += 1
        if (source[index] === '}') depth -= 1
        if (depth === 0) return source.slice(start, index + 1)
    }

    assert.fail(`${file}: inputChanged boundary must be readable`)
}

const violations = []

for (const file of components) {
    const source = fs.readFileSync(path.join(root, file), 'utf8')
    const inputChanged = inputChangedBody(source, file)

    assert.match(inputChanged, /\$emit\(['"]updateVocabBoxData['"]/, `${file}: translation updates must still be emitted`)
    assert.match(source, /setStage\(0\)/, `${file}: explicit known action must remain`)
    assert.match(source, /setStage\(1\)/, `${file}: explicit ignored action must remain`)

    if (/setStage\s*\(|\$emit\(['"]setStage['"]/.test(inputChanged)) {
        violations.push(`${file}: inputChanged must not change EncounteredWord stage`)
    }
}

const senses = fs.readFileSync(path.join(root, 'resources/js/components/Text/WordSensesList.vue'), 'utf8')
const reader = fs.readFileSync(path.join(root, 'resources/js/components/Text/TextBlockGroup.vue'), 'utf8')
assert.match(senses, /response\.data\.updated_word/, 'manual sense response must keep updated_word')
assert.match(senses, /\$emit\(['"]word-learning-updated['"]/, 'manual sense must emit the backend word update')
assert.match(reader, /onWordLearningUpdated\s*\(payload\)/, 'reader must keep consuming backend word updates')

assert.deepEqual(violations, [], violations.join('\n'))
console.log('EncounteredWord stage authority guard passed for 3 translation editors.')
