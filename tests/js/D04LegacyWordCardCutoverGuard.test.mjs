import assert from 'node:assert/strict'
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..')

const files = {
    reviewCardService: 'app/Services/ReviewCardService.php',
    reviewController: 'app/Http/Controllers/ReviewController.php',
    initializeReviewCards: 'app/Console/Commands/InitializeReviewCards.php',
    fsrsDoctor: 'app/Console/Commands/FsrsDoctor.php',
    protectionService: 'app/Services/LegacyWordCardMigrationProtectionService.php',
    migrateCommand: 'app/Console/Commands/MigrateLegacyWordCards.php',
    vocabularyService: 'app/Services/VocabularyService.php',
    chapterService: 'app/Services/ChapterService.php',
    userService: 'app/Services/UserService.php',
    goal: 'app/Models/Goal.php',
}

function readRequired(relativePath) {
    const absolutePath = path.join(repoRoot, relativePath)
    assert.ok(fs.existsSync(absolutePath), `${relativePath}: required D-04 source file must exist`)
    return fs.readFileSync(absolutePath, 'utf8')
}

function methodBody(source, methodName, file) {
    const match = new RegExp(`function\\s+${methodName}\\s*\\(`).exec(source)
    assert.ok(match, `${file}: ${methodName}() must exist`)

    const open = source.indexOf('{', match.index)
    assert.notEqual(open, -1, `${file}: ${methodName}() body must be readable`)

    let depth = 0
    for (let index = open; index < source.length; index += 1) {
        if (source[index] === '{') depth += 1
        if (source[index] === '}') depth -= 1
        if (depth === 0) return source.slice(match.index, index + 1)
    }

    assert.fail(`${file}: ${methodName}() body must have balanced braces`)
}

function protectionCallIndex(source) {
    const match = /\$this->\w*protection\w*->\w+\s*\(/i.exec(source)
    return match?.index ?? -1
}

function firstIndex(source, patterns) {
    const indexes = patterns
        .map((pattern) => pattern.exec(source)?.index ?? -1)
        .filter((index) => index >= 0)
    return indexes.length > 0 ? Math.min(...indexes) : -1
}

function withoutComments(source) {
    return source
        .replace(/\/\*[\s\S]*?\*\//g, '')
        .replace(/^\s*\/\/.*$/gm, '')
}

let passed = 0
let failed = 0

function test(name, fn) {
    try {
        fn()
        passed += 1
        console.log(`PASS: ${name}`)
    } catch (error) {
        failed += 1
        console.error(`FAIL: ${name}`)
        console.error(`  ${error.message}`)
        process.exitCode = 1
    }
}

const reviewCardService = readRequired(files.reviewCardService)
const reviewController = readRequired(files.reviewController)
const initializeReviewCards = readRequired(files.initializeReviewCards)
const fsrsDoctor = readRequired(files.fsrsDoctor)
const protectionService = readRequired(files.protectionService)
const migrateCommand = readRequired(files.migrateCommand)
const vocabularyService = readRequired(files.vocabularyService)
const chapterService = readRequired(files.chapterService)
const userService = readRequired(files.userService)
const goal = readRequired(files.goal)

const ensureWordCard = methodBody(reviewCardService, 'ensureWordCard', files.reviewCardService)
const recordReviewWithLog = methodBody(reviewCardService, 'recordReviewWithLog', files.reviewCardService)
const rateReviewCard = methodBody(reviewController, 'rateReviewCard', files.reviewController)
const initializeHandle = methodBody(initializeReviewCards, 'handle', files.initializeReviewCards)
const doctorWordCards = methodBody(fsrsDoctor, 'checkWordCards', files.fsrsDoctor)
const doctorSenseCards = methodBody(fsrsDoctor, 'checkSenseCards', files.fsrsDoctor)
const updateWord = methodBody(vocabularyService, 'updateWord', files.vocabularyService)
const hardDeleteWordsByIds = methodBody(vocabularyService, 'hardDeleteWordsByIds', files.vocabularyService)
const importFromCsv = methodBody(vocabularyService, 'importFromCsv', files.vocabularyService)
const finishChapter = methodBody(chapterService, 'finishChapter', files.chapterService)
const deleteUserLanguageData = methodBody(userService, 'deleteUserLanguageData', files.userService)
const reviewGoalQuantity = methodBody(goal, 'getTodaysReviewGoalQuantity', files.goal)

test('ensureWordCard is lookup-only and cannot create a legacy word ReviewCard', () => {
    assert.match(ensureWordCard, /ReviewCard::TARGET_WORD/, 'ensureWordCard must remain explicitly scoped to TARGET_WORD compatibility lookup')
    assert.doesNotMatch(
        ensureWordCard,
        /firstOrCreate\s*\(|updateOrCreate\s*\(|ReviewCard::create\s*\(|new\s+ReviewCard\b|->save\s*\(|->insert\s*\(/,
        'ensureWordCard must not instantiate, firstOrCreate, create, save, or insert a legacy word card',
    )
})

test('recordReviewWithLog enforces TARGET_SENSE before scheduling and ReviewLog creation', () => {
    const senseIndex = firstIndex(recordReviewWithLog, [
        /ReviewCard::TARGET_SENSE/,
        /target_type[^\n;]*['"]sense['"]/,
    ])
    const scheduleIndex = recordReviewWithLog.search(/fsrsSchedulingService->schedule\s*\(/)
    const logIndex = recordReviewWithLog.search(/ReviewLog::create\s*\(/)

    assert.ok(senseIndex >= 0, 'recordReviewWithLog must contain an explicit Sense-only target guard/filter')
    assert.ok(scheduleIndex >= 0, 'recordReviewWithLog must still schedule formal Sense reviews')
    assert.ok(logIndex >= 0, 'recordReviewWithLog must still create the formal ReviewLog')
    assert.ok(senseIndex < scheduleIndex, 'TARGET_SENSE enforcement must occur before FSRS scheduling')
    assert.ok(senseIndex < logIndex, 'TARGET_SENSE enforcement must occur before ReviewLog creation')
})

test('ReviewController rateReviewCard exposes the D-04 422 refusal and delegates rating to ReviewCardService', () => {
    assert.match(rateReviewCard, /reviewCardService->recordReview\s*\(/, 'rateReviewCard must delegate formal rating to ReviewCardService')
    assert.match(rateReviewCard, /422/, 'rateReviewCard must expose the frozen 422 legacy-target refusal contract')
    assert.doesNotMatch(
        rateReviewCard,
        /FsrsSchedulingService|ReviewLog::(?:create|insert)|ReviewCard::(?:create|update|insert)|->schedule\s*\(/,
        'rateReviewCard must not bypass ReviewCardService for scheduling/log/card writes',
    )
})

test('reviews:initialize-cards keeps dry-run diagnostics but has no active legacy creation writer', () => {
    assert.match(initializeHandle, /dry-run/, 'InitializeReviewCards must retain the dry-run diagnostic path')
    assert.doesNotMatch(
        initializeHandle,
        /initializeExistingWords\s*\(|ensureWordCard\s*\(/,
        'InitializeReviewCards non-dry path must not call a legacy word-card creation loop',
    )
    assert.match(initializeHandle, /self::FAILURE|return\s+[1-9]\d*\s*;/, 'InitializeReviewCards non-dry refusal must be non-zero')
})

test('fsrs:doctor word fix cannot create a legacy word card while Sense fix remains', () => {
    assert.doesNotMatch(
        doctorWordCards,
        /ensureWordCard\s*\(|ReviewCard::(?:firstOrCreate|create)\s*\(|new\s+ReviewCard\b/,
        'FsrsDoctor word-card path must stay diagnostic-only even under --fix',
    )
    assert.match(doctorSenseCards, /ensureSenseCard\s*\(/, 'FsrsDoctor must retain confirmed Sense-card repair behavior')
})

test('LegacyWordCardMigrationProtectionService reuses the existing applied run/item ledger without parallel infrastructure', () => {
    assert.match(protectionService, /class\s+LegacyWordCardMigrationProtectionService\b/, 'D-04 protection service class must exist')
    assert.match(protectionService, /LegacyWordCardMigrationItem\b/, 'protection service must query the existing migration item ledger')
    assert.match(protectionService, /LegacyWordCardMigrationRun\b/, 'protection service must use the existing migration run ledger state')
    assert.match(protectionService, /applied/i, 'protection service must use applied-state semantics')
    assert.doesNotMatch(
        protectionService,
        /Schema::|Cache::|Redis::|lockForUpdate\s*\(|\bretry\s*\(|watchdog|self[-_ ]?heal/i,
        'protection service must not define a second table, lock/cache/retry system, watchdog, or self-heal path',
    )
})

test('VocabularyService protects explicit stage, hard-delete, and CSV stage boundaries', () => {
    assert.match(vocabularyService, /LegacyWordCardMigrationProtectionService\b/, 'VocabularyService must depend on the migration protection service')

    const updateProtection = protectionCallIndex(updateWord)
    const updateStage = updateWord.search(/setStage\s*\(/)
    assert.ok(updateProtection >= 0 && updateStage >= 0 && updateProtection < updateStage, 'updateWord must call protection before explicit setStage mutation')

    const deleteProtection = protectionCallIndex(hardDeleteWordsByIds)
    const firstDelete = hardDeleteWordsByIds.search(/->delete\s*\(/)
    assert.ok(deleteProtection >= 0 && firstDelete >= 0 && deleteProtection < firstDelete, 'hardDeleteWordsByIds must call protection before destructive deletion')

    const csvProtection = protectionCallIndex(importFromCsv)
    const csvStage = importFromCsv.search(/setStage\s*\(/)
    assert.ok(csvProtection >= 0 && csvStage >= 0 && csvProtection < csvStage, 'importFromCsv must call protection before CSV stage mutation')
})

test('ChapterService finishChapter protects migration-applied words before side effects', () => {
    assert.match(chapterService, /LegacyWordCardMigrationProtectionService\b/, 'ChapterService must depend on the migration protection service')
    const protectionIndex = protectionCallIndex(finishChapter)
    const firstSideEffect = firstIndex(finishChapter, [/DB::beginTransaction\s*\(/, /->save\s*\(/, /->update\s*\(/])
    assert.ok(protectionIndex >= 0, 'finishChapter must call the migration protection service')
    assert.ok(firstSideEffect >= 0 && protectionIndex < firstSideEffect, 'finishChapter protection must run before stage-changing/other side effects')
})

test('UserService deleteUserLanguageData protects migration-applied history before deletion', () => {
    assert.match(userService, /LegacyWordCardMigrationProtectionService\b/, 'UserService must depend on the migration protection service')
    const protectionIndex = protectionCallIndex(deleteUserLanguageData)
    const deleteIndex = deleteUserLanguageData.search(/->delete\s*\(/)
    assert.ok(protectionIndex >= 0, 'deleteUserLanguageData must call the migration protection service')
    assert.ok(deleteIndex >= 0 && protectionIndex < deleteIndex, 'deleteUserLanguageData protection must run before any destructive deletion')
})

test('reviews:migrate-legacy-word-cards is testing-only and delegates plan/apply/rollback to the recovery owner', () => {
    assert.match(migrateCommand, /reviews:migrate-legacy-word-cards/, 'controlled command must use the frozen signature name')
    assert.match(migrateCommand, /--apply\b/, 'controlled command must expose --apply')
    assert.match(migrateCommand, /--rollback(?:=|\b)/, 'controlled command must expose --rollback')
    assert.match(migrateCommand, /LegacyWordCardMigrationRecoveryService\b/, 'controlled command must delegate to the existing recovery owner')
    assert.match(migrateCommand, /environment\s*\([^)]*['"]testing['"]|environment\s*\(\s*\)\s*!==?\s*['"]testing['"]|environment\s*\(\s*\)\s*===?\s*['"]testing['"]/, 'mutation path must explicitly enforce the testing environment')
    assert.match(migrateCommand, /->plan\s*\(/, 'controlled command must delegate planning to the recovery owner')
    assert.match(migrateCommand, /->apply\s*\(/, 'controlled command must delegate apply to the recovery owner')
    assert.match(migrateCommand, /->rollback\s*\(/, 'controlled command must delegate rollback to the recovery owner')
    assert.doesNotMatch(
        migrateCommand,
        /LegacyWordCardMigrationClassifier|BackupService|DB::|->join\s*\(|selectRaw\s*\(|review_cards|word_senses|encountered_words/i,
        'controlled command must not duplicate classifier SQL/mapping or BackupService/repository implementation',
    )
})

test('Goal review quantity uses the existing confirmed eligible due Sense query authority only', () => {
    assert.match(reviewGoalQuantity, /SenseReviewQueryService|confirmedSenseCardQuery/, 'Goal review quantity must reuse SenseReviewQueryService authority')
    assert.match(reviewGoalQuantity, /confirmedSenseCardQuery\s*\(/, 'Goal review quantity must start from confirmed same-scope Sense cards')
    assert.match(reviewGoalQuantity, /senseReviewEligible\s*\(/, 'Goal review quantity must apply senseReviewEligible')
    assert.match(reviewGoalQuantity, /fsrs_due_at[^\n]*<=|where\s*\(\s*['"]review_cards\.fsrs_due_at['"]\s*,\s*['"]<=['"]/, 'Goal review quantity must keep the due-at-now filter')
    assert.doesNotMatch(reviewGoalQuantity, /TARGET_WORD|encountered_words/, 'Goal review quantity must not count legacy word cards')
})

test('targeted D-04 sources add no second ledger, watchdog, self-heal, fallback browser, or notification hook', () => {
    const targeted = withoutComments([
        reviewCardService,
        reviewController,
        initializeReviewCards,
        fsrsDoctor,
        protectionService,
        migrateCommand,
        vocabularyService,
        chapterService,
        userService,
        goal,
    ].join('\n'))

    assert.doesNotMatch(targeted, /Schema::create\s*\(|protected\s+\$table\s*=|migration_(?:ledger|records)|legacy_word_card_(?:ledger|records)/i, 'targeted sources must not define a second migration ledger/table')
    assert.doesNotMatch(targeted, /watchdog|self[-_ ]?heal|playwright|fallback\s+browser|notify\.ps1|Notification::|->notify\s*\(/i, 'targeted sources must not add watchdog/self-heal/browser fallback/notification hooks')
})

console.log(`\nD-04 static guard: ${passed} passed, ${failed} failed`)
if (failed > 0) process.exitCode = 1
