<template>
    <div id="vocabulary-search-box" class="border rounded-lg pa-2" :language="$props.language">
        <div class="search-result disabled" v-if="dictionaryApiSearchLoading">
            <div class="search-result-title">
                <div class="dictionary-title-icon mr-1" style="background-color: var(--v-primary-base);">
                    <v-icon small>mdi-translate</v-icon>
                </div>
                {{ $props.searchTerm }}
                <div class="search-result-word default-font" :title="$props.searchTerm">API 查询</div>
            </div>
            <div class="search-result-definition rounded pr-2">
                正在查询 <v-progress-circular indeterminate class="ml-1" size="20" width="3" color="primary" />
            </div>
        </div>

        <div class="search-result disabled" v-if="dictionarySearchLoading">
            <div class="search-result-title">
                <div class="dictionary-title-icon mr-1" style="background-color: var(--v-primary-base);">
                    <v-icon small>mdi-list-box</v-icon>
                </div>
                <span class="default-font" :title="$props.searchTerm">{{ $props.searchTerm }}</span>
                <div class="search-result-word">词典查询</div>
            </div>
            <div class="search-result-definition rounded pr-2">
                正在查询 <v-progress-circular indeterminate class="ml-1" size="20" width="3" color="primary" />
            </div>
        </div>

        <div class="search-result disabled" v-if="!dictionarySearchLoading && !dictionarySearchResultsFound">
            <div class="search-result-title default-font" :title="$props.searchTerm">
                <div class="dictionary-title-icon mr-1" style="background-color: var(--v-primary-base);">
                    <v-icon small>mdi-list-box</v-icon>
                </div>
                {{ $props.searchTerm }}
            </div>
            <div class="search-result-definition rounded pr-2">
                {{ dictionaryMessage }}
            </div>
        </div>

        <!-- Scrollable dictionary results -->
        <div class="dictionary-results-scroll">
            <div v-if="!dictionaryApiSearchLoading" class="search-result" v-for="(searchResult, searchResultIndex) in apiSearchResults" :key="`api-${searchResultIndex}`">
                <div class="search-result-title">
                    <div class="dictionary-title-icon mr-1" :style="{'background-color': searchResult.dictionaryColor}">
                        <v-icon small>mdi-translate</v-icon>
                    </div>
                    {{ searchResult.dictionary }}
                    <div class="search-result-word default-font" :title="$props.searchTerm">{{ $props.searchTerm }}</div>
                </div>
                <div
                    v-for="(definition, definitionIndex) in searchResult.definitions"
                    :key="`api-search-result-${searchResultIndex}-${definitionIndex}`"
                    class="search-result-definition rounded dictionary-definition-row"
                >
                    <div class="dictionary-definition-text" @click="addDefinitionToInput(definition)">
                        {{ definition }} <v-icon small>mdi-plus</v-icon>
                    </div>
                    <v-btn x-small outlined color="primary" class="ml-2" @click.stop="addDefinitionAsSense(definition, $props.searchTerm, searchResult.dictionary)" title="保存后会加入词义复习">
                        + 添加为新释义
                    </v-btn>
                </div>
            </div>

            <div class="search-result jmdict" v-for="(searchResult, searchresultIndex) in searchResults" :key="searchresultIndex">
                <template v-if="searchResult.dictionary !== 'JMDict'">
                    <div v-for="(record, recordIndex) in searchResult.records" :key="recordIndex">
                        <div class="search-result-title" :title="record.word">
                            <div class="dictionary-title-icon mr-1" :style="{'background-color': searchResult.color}">
                                <v-icon small>mdi-list-box</v-icon>
                            </div>
                            {{ searchResult.dictionary }}<div class="search-result-word" :title="record.word"> {{ record.word }} </div>
                        </div>
                        <div
                            v-for="(definition, definitionIndex) in record.definitions"
                            :key="definitionIndex"
                            class="search-result-definition rounded dictionary-definition-row"
                        >
                            <div class="dictionary-definition-text" @click="addDefinitionToInput(definition)">
                                {{ definition }} <v-icon small>mdi-plus</v-icon>
                            </div>
                            <v-btn x-small outlined color="primary" class="ml-2" @click.stop="addDefinitionAsSense(definition, record.word, searchResult.dictionary)" title="保存后会加入词义复习">
                                + 添加为新释义
                            </v-btn>
                        </div>
                    </div>
                </template>

                <template v-if="searchResult.dictionary == 'JMDict'">
                    <div v-for="(record, recordIndex) in searchResult.records" :key="recordIndex">
                        <div class="search-result-title" :title="record.word">
                            <div class="dictionary-title-icon mr-1" :style="{'background-color': searchResult.color}">
                                <v-icon small>mdi-list-box</v-icon>
                            </div>
                            {{ searchResult.dictionary }}<div class="search-result-word default-font" :title="record.word"> {{ record.word }} </div>
                        </div>
                        <div class="search-result-definition rounded dictionary-definition-row" v-for="(definition, definitionIndex) in record.definitions" :key="definitionIndex">
                            <div class="dictionary-definition-text" @click="addDefinitionToInput(definition)">
                                {{ definition }} <v-icon small>mdi-plus</v-icon>
                            </div>
                            <v-btn x-small outlined color="primary" class="ml-2" @click.stop="addDefinitionAsSense(definition, record.word, searchResult.dictionary)" title="保存后会加入词义复习">
                                + 添加为新释义
                            </v-btn>
                        </div>

                        <template v-if="record.otherForms.length">
                            <div class="vocab-box-subheader">其他形式：</div>
                            <div class="d-flex flex-wrap default-font">
                                <div v-for="(form, formIndex) in record.otherForms" :key="formIndex">
                                    {{ form }}<span class="mr-2" v-if="formIndex < record.otherForms.length - 1">, </span>
                                </div>
                            </div>
                        </template>
                    </div>
                </template>
            </div>
        </div>
    </div>
</template>

<script>
export default {
    props: {
        language: String,
        anyApiDictionaryEnabled: Boolean,
        searchTerm: String
    },
    watch: {
        searchTerm() {
            this.makeSearchRequest();
        }
    },
    data() {
        return {
            searchResults: [],
            dictionarySearchLoading: false,
            dictionaryApiSearchLoading: false,
            dictionarySearchResultsFound: true,
            dictionaryMessage: '暂无词典结果。',
            apiSearchResults: [],
        };
    },
    mounted() {
        this.makeSearchRequest();
    },
    methods: {
        addDefinitionToInput(definition) {
            this.$emit('addDefinitionToInput', definition);
        },
        addDefinitionAsSense(definition, word, dictionary) {
            this.$emit('addDefinitionAsSense', {
                definition: definition,
                word: word,
                dictionary: dictionary,
                pos: this.inferPartOfSpeech(definition),
            });
        },
        inferPartOfSpeech(definition) {
            const value = (definition || '').trim().toLowerCase();
            const rules = [
                { match: /^(v\.|vi\.|vt\.|verb\b)/, pos: 'verb' },
                { match: /^(n\.|noun\b)/, pos: 'noun' },
                { match: /^(adj\.|a\.|adjective\b)/, pos: 'adjective' },
                { match: /^(adv\.|adverb\b)/, pos: 'adverb' },
                { match: /^(prep\.|preposition\b)/, pos: 'preposition' },
                { match: /^(conj\.|conjunction\b)/, pos: 'conjunction' },
            ];

            const rule = rules.find(item => item.match.test(value));
            return rule ? rule.pos : 'other';
        },
        makeSearchRequest() {
            this.searchResults = [];
            this.apiSearchResults = [];
            this.dictionaryMessage = '暂无词典结果。';
            if (this.$props.searchTerm == '') {
                return;
            }

            this.dictionarySearchLoading = true;
            this.dictionarySearchResultsFound = false;
            axios.post('/dictionaries/search', {
                language: this.$props.language,
                term: this.$props.searchTerm
            }).then((response) => {
                this.processVocabularySearchResults(response.data);
                if (!this.dictionarySearchResultsFound && (!Array.isArray(response.data) || response.data.length === 0)) {
                    this.dictionaryMessage = '词典未配置，请先导入或配置词典数据。';
                }
            }).catch(() => {
                this.searchResults = [];
                this.dictionarySearchResultsFound = false;
                this.dictionaryMessage = '词典查询失败，请检查词典配置。';
            }).finally(() => {
                this.dictionarySearchLoading = false;
            });

            if (this.$props.anyApiDictionaryEnabled) {
                this.dictionaryApiSearchLoading = true;
                axios.post('/dictionaries/api/search', {
                    language: this.$props.language,
                    term: this.$props.searchTerm
                }).then((response) => {
                    this.apiSearchResults = response.data;
                }).catch(() => {
                    this.apiSearchResults = [];
                }).finally(() => {
                    this.dictionaryApiSearchLoading = false;
                });
            }
        },
        processVocabularySearchResults(data) {
            this.searchResults = [];

            for (let dictionaryIndex = 0; dictionaryIndex < data.length; dictionaryIndex++) {
                if (data[dictionaryIndex].name == 'JMDict') {
                    const searchResult = {
                        dictionary: data[dictionaryIndex].name,
                        color: data[dictionaryIndex].color,
                        records: []
                    };

                    for (let jmdictIndex = 0; jmdictIndex < data[dictionaryIndex].jmdictRecords.length; jmdictIndex++) {
                        const jmdictRecord = data[dictionaryIndex].jmdictRecords[jmdictIndex];
                        searchResult.records.push({
                            word: jmdictRecord.words.length ? jmdictRecord.words[0] : '',
                            otherForms: data[dictionaryIndex].jmdictRecords[jmdictIndex].words,
                            definitions: data[dictionaryIndex].jmdictRecords[jmdictIndex].definitions,
                        });
                    }

                    if (searchResult.records.length) {
                        this.dictionarySearchResultsFound = true;
                    }

                    this.searchResults.push(searchResult);
                } else {
                    const searchResult = {
                        dictionary: data[dictionaryIndex].name,
                        color: data[dictionaryIndex].color,
                        records: []
                    };

                    for (let recordIndex = 0; recordIndex < data[dictionaryIndex].records.length; recordIndex++) {
                        searchResult.records.push({
                            word: data[dictionaryIndex].records[recordIndex].word,
                            definitions: data[dictionaryIndex].records[recordIndex].definitions,
                        });
                    }

                    if (searchResult.records.length) {
                        this.dictionarySearchResultsFound = true;
                    }

                    this.searchResults.push(searchResult);
                }
            }
        }
    }
}
</script>

<style scoped>
.dictionary-results-scroll {
    max-height: 300px;
    overflow-y: auto;
}

.dictionary-definition-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
}

.dictionary-definition-text {
    flex: 1;
    min-width: 0;
}
</style>
