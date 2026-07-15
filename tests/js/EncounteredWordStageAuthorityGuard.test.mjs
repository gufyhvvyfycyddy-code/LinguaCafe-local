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

function methodBody(source, signature, file) {
    const start = source.indexOf(signature)
    assert.notEqual(start, -1, `${file}: ${signature} must exist`)
    let depth = 0
    for (let index = source.indexOf('{', start); index < source.length; index += 1) {
        if (source[index] === '{') depth += 1
        if (source[index] === '}') depth -= 1
        if (depth === 0) return source.slice(start, index + 1)
    }
    assert.fail(`${file}: ${signature} boundary must be readable`)
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

const wordSenseService = fs.readFileSync(path.join(root, 'app/Services/WordSenseService.php'), 'utf8')
const createManualSense = methodBody(wordSenseService, 'public function createManualSense(', 'WordSenseService.php')
assert.doesNotMatch(createManualSense, /setStage\s*\(\s*-7\s*\)/, 'manual sense must not call legacy setStage(-7)')
assert.match(createManualSense, /learningEnrollmentService->enrollFromConfirmedSense\s*\(/, 'manual sense must delegate reader enrollment')

const vocabularyService = fs.readFileSync(path.join(root, 'app/Services/VocabularyService.php'), 'utf8')
const updateWord = methodBody(vocabularyService, 'public function updateWord(', 'VocabularyService.php')
const stagePreparation = methodBody(updateWord, 'if ($wordStage !== null)', 'VocabularyService::updateWord stage preparation')
const secondStageStart = updateWord.indexOf('if ($wordStage !== null)', updateWord.indexOf('if ($wordStage !== null)') + 1)
assert.notEqual(secondStageStart, -1, 'VocabularyService::updateWord must gate legacy effects after the content save')
const stageEffects = methodBody(updateWord.slice(secondStageStart), 'if ($wordStage !== null)', 'VocabularyService::updateWord stage effects')
assert.match(stagePreparation, /setStage\s*\(/, 'explicit stage path must retain setStage before content fields are applied')
for (const call of ['ensureWordCard', 'disableWordCard', 'bridgeWordToSense']) {
    assert.match(stageEffects, new RegExp(`${call}\\s*\\(`), `explicit stage path must retain ${call}`)
}
const contentOnly = updateWord.replace(stagePreparation, '').replace(stageEffects, '')
for (const call of ['setStage', 'ensureWordCard', 'disableWordCard', 'bridgeWordToSense']) {
    assert.doesNotMatch(contentOnly, new RegExp(`${call}\\s*\\(`), `content-only path must not call ${call}`)
}

const enrollmentService = fs.readFileSync(path.join(root, 'app/Services/EncounteredWordLearningEnrollmentService.php'), 'utf8')
assert.match(enrollmentService, /stage\s*=\s*-1/, 'confirmed sense enrollment must use lowest reader stage')
assert.match(enrollmentService, /next_review\s*=\s*null/, 'enrollment must clear legacy next_review')
assert.match(enrollmentService, /added_to_srs\s*=\s*null/, 'enrollment must clear legacy added_to_srs')
assert.doesNotMatch(enrollmentService, /setStage|reviewIntervals|ensureWordCard|bridgeWordToSense|ReviewLog|Fsrs/i, 'enrollment service must not own legacy scheduling, cards, logs, or FSRS')

assert.deepEqual(violations, [], violations.join('\n'))
console.log('EncounteredWord stage authority guard passed for content edits, explicit legacy stages, and confirmed-sense enrollment.')
