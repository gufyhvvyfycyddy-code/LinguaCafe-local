import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const read = relative => fs.readFileSync(path.join(root, relative), 'utf8');

const admin = read('resources/js/components/Admin/AdminDictionarySettings.vue');
const searchBox = read('resources/js/components/Text/VocabularySearchBox.vue');
const textBlock = read('resources/js/components/Text/TextBlockGroup.vue');
const hoverBox = read('resources/js/components/Text/VocabularyHoverBox.vue');
const api = read('resources/js/services/ReaderLookupApi.js');
const responsePolicy = read('resources/js/services/ReaderLookupResponsePolicy.js');

test('admin dictionary list exposes row health and always closes loading on failure', () => {
    assert.equal(admin.includes("text: '健康状态'"), true);
    assert.equal(admin.includes('item.health.status'), true);
    assert.equal(admin.includes('需要修复'), true);
    assert.equal(admin.includes('.finally(() => {'), true);
    assert.equal(admin.includes('this.loading = false'), true);
    assert.equal(admin.includes('console.log(this.dictionaries)'), false);
});

test('search box distinguishes no result, degraded warning, and total outage', () => {
    assert.equal(searchBox.includes('dictionaryWarnings'), true);
    assert.equal(searchBox.includes('部分词典暂时不可用'), true);
    assert.equal(searchBox.includes('词典服务暂时不可用'), true);
    assert.equal(searchBox.includes('requestSequence'), true);
    assert.equal(searchBox.includes('currentRequestSequence'), true);
    assert.equal(searchBox.includes('envelope.results'), true);
    assert.equal(searchBox.includes('Array.isArray(data[dictionaryIndex].jmdictRecords)'), true);
    assert.equal(searchBox.includes("name == 'JMDict'"), false);
});

test('reader hover and inflection paths consume envelopes and close failures', () => {
    assert.equal(textBlock.includes('response.data.inflections'), true);
    assert.equal(textBlock.includes('response.data.warnings'), true);
    assert.equal(textBlock.includes('searchReaderInflections(term).then'), true);
    assert.equal(textBlock.includes('currentInflectionSearchTerm'), true);
    assert.equal(textBlock.includes('this.currentInflectionSearchTerm !== term'), true);
    assert.equal(textBlock.includes('this.$store.state.hoverVocabularyBox.dictionarySearchTerm !== term'), true);
    assert.equal(textBlock.includes("value: 'dictionary-unavailable'"), true);
    assert.equal(hoverBox.includes('dictionary-unavailable'), true);
    assert.equal(hoverBox.includes('部分词典暂时不可用'), true);
});

test('lookup transport and response policy expose stable degraded-availability helpers', () => {
    assert.equal(api.includes("axios.post('/dictionaries/search'"), true);
    assert.equal(api.includes('searchReaderDictionary'), true);
    assert.equal(responsePolicy.includes('resolveReaderLookupEnvelope'), true);
    assert.equal(responsePolicy.includes('normalizeDictionaryWarnings'), true);
    assert.equal(responsePolicy.includes('DICTIONARY_LOOKUP_UNAVAILABLE'), true);
});
