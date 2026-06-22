<template>
    <v-container id="vocabulary">
        <vocabulary-edit-dialog
            v-if="vocabularyEditDialog.active"
            v-model="vocabularyEditDialog.active"
            :item-id="vocabularyEditDialog.itemId"
            :item-type="vocabularyEditDialog.itemType"
            :language-spaces="languageSpaces"
            :language="$props.language"
            @saved="loadVocabularySearchPage"
        />

        <vocabulary-export-dialog
            v-if="vocabularyExportDialog.active"
            v-model="vocabularyExportDialog.active"
            :sample-words="words"
            :language="$props.language"
            :language-spaces="languageSpaces"
            @export-to-csv="exportToCsv"
        />

        <vocabulary-import-dialog
            v-if="vocabularyImportDialog.active"
            v-model="vocabularyImportDialog.active"
        />

        <v-card outlined class="rounded-lg px-4 pb-4 my-4" :loading="loading">
            <div class="subheader my-4 d-flex">
                词汇
                <v-spacer />
                <v-chip id="search-result-info" color="foreground" class="pl-1">
                    <v-icon class="mr-1" right>mdi-text-box-check</v-icon>{{ wordCount }} 条结果
                </v-chip>
            </div>
            <v-alert v-if="error" dense outlined type="error">{{ error }}</v-alert>

            <div id="vocabulary-search-field" class="mb-6">
                <v-btn rounded depressed color="primary" @click="applyFilter('text')">
                    <v-icon>mdi-magnify</v-icon> 搜索
                </v-btn>
                <v-text-field class="pt-0" rounded filled dense hide-details placeholder="搜索词条" v-model="filters.text" @keyup.enter="applyFilter('text')" />
            </div>

            <v-container fluid>
                <v-row id="filters" :class="{'hidden': filtersHidden}">
                    <v-menu offset-y>
                        <template v-slot:activator="{ on, attrs }">
                            <v-btn class="filter-menu pl-3 pr-2 mx-1" color="foreground" rounded depressed v-bind="attrs" v-on="on">
                                等级
                                <v-icon>{{ attrs['aria-expanded'] === 'true' ? 'mdi-chevron-up' : 'mdi-chevron-down' }}</v-icon>
                            </v-btn>
                        </template>
                        <v-list class="filter-popup pa-0" dense>
                            <v-list-item-group color="primary">
                                <v-list-item :class="{'v-list-item--active': filters.stage == -999}" @click="applyFilter('stage', -999)">全部</v-list-item>
                                <v-list-item :class="{'v-list-item--active': filters.stage == 2}" @click="applyFilter('stage', 2)">新词</v-list-item>
                                <v-list-item :class="{'v-list-item--active': filters.stage == 1}" @click="applyFilter('stage', 1)">已忽略</v-list-item>
                                <v-list-item :class="{'v-list-item--active': filters.stage == 0}" @click="applyFilter('stage', 0)">已掌握</v-list-item>
                                <v-list-item v-for="stage in [-1,-2,-3,-4,-5,-6,-7]" :key="stage" :class="{'v-list-item--active': filters.stage == stage}" @click="applyFilter('stage', stage)">{{ stage * -1 }}</v-list-item>
                            </v-list-item-group>
                        </v-list>
                    </v-menu>

                    <v-menu right offset-y v-if="books.length">
                        <template v-slot:activator="{ on, attrs }">
                            <v-btn class="filter-menu pl-3 pr-2 mx-1" color="foreground" rounded depressed v-bind="attrs" v-on="on">
                                书籍
                                <v-icon>{{ attrs['aria-expanded'] === 'true' ? 'mdi-chevron-up' : 'mdi-chevron-down' }}</v-icon>
                            </v-btn>
                        </template>
                        <v-list class="filter-popup pa-0" dense>
                            <v-list-item-group color="primary">
                                <v-list-item :class="{'v-list-item--active': filters.book == -1}" @click="applyFilter('book', -1, -1)">全部</v-list-item>
                                <v-list-item v-for="(book, index) in books" :key="index" :class="{'default-font': true, 'v-list-item--active': filters.book == book.id}" @click="applyFilter('book', book.id, index)">{{ book.name }}</v-list-item>
                            </v-list-item-group>
                        </v-list>
                    </v-menu>

                    <v-menu offset-y v-if="filters.bookIndex !== -1 && books.length">
                        <template v-slot:activator="{ on, attrs }">
                            <v-btn class="filter-menu pl-3 pr-2 mx-1" color="foreground" rounded depressed v-bind="attrs" v-on="on">
                                章节
                                <v-icon>{{ attrs['aria-expanded'] === 'true' ? 'mdi-chevron-up' : 'mdi-chevron-down' }}</v-icon>
                            </v-btn>
                        </template>
                        <v-list class="filter-popup pa-0" dense>
                            <v-list-item-group color="primary">
                                <v-list-item :class="{'v-list-item--active': filters.chapter == -1}" @click="applyFilter('chapter', -1)">全部</v-list-item>
                                <v-list-item v-for="(chapter, index) in books[filters.bookIndex].chapters" :key="index" :class="{'default-font': true, 'v-list-item--active': filters.chapter == chapter.id}" @click="applyFilter('chapter', chapter.id, index)">{{ chapter.name }}</v-list-item>
                            </v-list-item-group>
                        </v-list>
                    </v-menu>

                    <v-menu offset-y>
                        <template v-slot:activator="{ on, attrs }">
                            <v-btn class="filter-menu pl-3 pr-2 mx-1" color="foreground" rounded depressed v-bind="attrs" v-on="on">
                                释义
                                <v-icon>{{ attrs['aria-expanded'] === 'true' ? 'mdi-chevron-up' : 'mdi-chevron-down' }}</v-icon>
                            </v-btn>
                        </template>
                        <v-list class="filter-popup pa-0" dense>
                            <v-list-item-group color="primary">
                                <v-list-item :class="{'v-list-item--active': filters.translation == 'any'}" @click="applyFilter('translation', 'any')">全部</v-list-item>
                                <v-list-item :class="{'v-list-item--active': filters.translation == 'not empty'}" @click="applyFilter('translation', 'not empty')">非空</v-list-item>
                            </v-list-item-group>
                        </v-list>
                    </v-menu>

                    <v-menu offset-y>
                        <template v-slot:activator="{ on, attrs }">
                            <v-btn class="filter-menu pl-3 pr-2 mx-1" color="foreground" rounded depressed v-bind="attrs" v-on="on">
                                短语
                                <v-icon>{{ attrs['aria-expanded'] === 'true' ? 'mdi-chevron-up' : 'mdi-chevron-down' }}</v-icon>
                            </v-btn>
                        </template>
                        <v-list class="filter-popup pa-0" dense>
                            <v-list-item-group color="primary">
                                <v-list-item :class="{'v-list-item--active': filters.phrases == 'both'}" @click="applyFilter('phrases', 'both')">全部</v-list-item>
                                <v-list-item :class="{'v-list-item--active': filters.phrases == 'only words'}" @click="applyFilter('phrases', 'only words')">仅单词</v-list-item>
                                <v-list-item :class="{'v-list-item--active': filters.phrases == 'only phrases'}" @click="applyFilter('phrases', 'only phrases')">仅短语</v-list-item>
                            </v-list-item-group>
                        </v-list>
                    </v-menu>

                    <v-menu offset-y>
                        <template v-slot:activator="{ on, attrs }">
                            <v-btn class="filter-menu pl-3 pr-2 mx-1" color="foreground" rounded depressed v-bind="attrs" v-on="on">
                                排序
                                <v-icon>{{ attrs['aria-expanded'] === 'true' ? 'mdi-chevron-up' : 'mdi-chevron-down' }}</v-icon>
                            </v-btn>
                        </template>
                        <v-list class="filter-popup pa-0" dense>
                            <v-list-item-group color="primary">
                                <v-list-item :class="{'v-list-item--active': filters.orderBy == 'words'}" @click="applyFilter('orderBy', 'words')"><v-icon class="mr-1">mdi-sort-alphabetical-ascending</v-icon>词条</v-list-item>
                                <v-list-item :class="{'v-list-item--active': filters.orderBy == 'words desc'}" @click="applyFilter('orderBy', 'words desc')"><v-icon class="mr-1">mdi-sort-alphabetical-descending</v-icon>词条</v-list-item>
                                <v-list-item :class="{'v-list-item--active': filters.orderBy == 'stage'}" @click="applyFilter('orderBy', 'stage')"><v-icon class="mr-1">mdi-sort-numeric-ascending</v-icon>等级</v-list-item>
                                <v-list-item :class="{'v-list-item--active': filters.orderBy == 'stage desc'}" @click="applyFilter('orderBy', 'stage desc')"><v-icon class="mr-1">mdi-sort-numeric-descending</v-icon>等级</v-list-item>
                            </v-list-item-group>
                        </v-list>
                    </v-menu>

                    <v-btn class="filter-menu show-filters px-3" rounded depressed @click="filtersHidden = !filtersHidden">
                        <v-icon small class="mr-1">{{ filtersHidden ? 'mdi-eye' : 'mdi-eye-off' }}</v-icon>{{ filtersHidden ? '显示筛选' : '隐藏筛选' }}
                    </v-btn>

                    <v-spacer />

                    <v-menu offset-y>
                        <template v-slot:activator="{ on, attrs }">
                            <v-btn class="filter-menu export pl-3 pr-2" color="foreground" rounded depressed v-bind="attrs" v-on="on">
                                <v-icon small class="mr-1">mdi-file-download</v-icon>数据
                                <v-icon>{{ attrs['aria-expanded'] === 'true' ? 'mdi-chevron-up' : 'mdi-chevron-down' }}</v-icon>
                            </v-btn>
                        </template>
                        <v-list class="filter-popup pa-0" dense>
                            <v-list-item @click="openExportDialog" :disabled="loading"><v-icon class="mr-1">mdi-file-delimited</v-icon>导出</v-list-item>
                            <v-list-item @click="openImportDialog" :disabled="loading"><v-icon class="mr-1">mdi-file-delimited</v-icon>导入</v-list-item>
                        </v-list>
                    </v-menu>
                </v-row>
            </v-container>
        </v-card>

        <v-simple-table id="vocabulary-list" class="py-0 no-hover border rounded-lg" dense>
            <thead>
                <tr>
                    <th class="select" v-if="batchSelectEnabled">
                        <v-checkbox
                            :value="allSelected"
                            :indeterminate="someSelected && !allSelected"
                            @change="toggleSelectAll"
                            hide-details
                            dense
                            class="ma-0 pa-0"
                        />
                    </th>
                    <th class="word">词条</th>
                    <th class="reading" v-if="isCjkLanguage">读音</th>
                    <th class="word-with-reading">词条</th>
                    <th class="stage px-1">等级</th>
                    <th class="translation">释义</th>
                    <th class="actions">操作</th>
                </tr>
            </thead>
            <tbody>
                <tr v-if="!loading && words.length === 0">
                    <td :colspan="colspan" class="text-center py-6">暂无词汇</td>
                </tr>
                <tr v-for="(word, index) in words" :key="index">
                    <td class="select" v-if="batchSelectEnabled">
                        <v-checkbox
                            :value="selectedIds.has(word.id)"
                            :disabled="word.type !== 'word'"
                            @change="toggleWord(word)"
                            hide-details
                            dense
                            class="ma-0 pa-0"
                        />
                    </td>
                    <td class="word default-font">{{ displayWord(word) }}</td>
                    <td class="reading default-font" v-if="isCjkLanguage">{{ word.reading }}</td>
                    <td class="word-with-reading default-font">
                        <ruby>{{ displayWord(word) }}<rt v-if="isCjkLanguage">{{ word.reading }}</rt></ruby>
                    </td>
                    <td class="stage px-1" :stage="word.stage">
                        <div v-if="word.stage < 0" class="highlighted-word">{{ word.stage * -1 }}</div>
                        <div v-if="word.stage === 0">0</div>
                        <div v-if="word.stage === 1">X</div>
                        <div v-if="word.stage === 2" class="new-word">新词</div>
                    </td>
                    <td class="translation">{{ word.translation }}</td>
                    <td class="actions">
                        <v-btn icon title="编辑" @click="editItem(word.id, word.type == 'word' ? 'Word' : 'Phrase')">
                            <v-icon>mdi-pencil</v-icon>
                        </v-btn>
                        <template v-if="word.type == 'word'">
                            <v-btn icon title="忽略" @click="setWordStage(word, 1)">
                                <v-icon>mdi-eye-off</v-icon>
                            </v-btn>
                            <v-btn icon title="标为已知" @click="setWordStage(word, 0)">
                                <v-icon>mdi-check</v-icon>
                            </v-btn>
                            <v-btn icon title="删除词条" color="error" @click="deleteWord(word)">
                                <v-icon>mdi-delete</v-icon>
                            </v-btn>
                        </template>
                    </td>
                </tr>
            </tbody>
        </v-simple-table>

        <!-- Batch actions -->
        <div v-if="batchSelectEnabled && (selectedIds.size > 0 || selectedAllMatching)" class="d-flex align-center flex-wrap mt-3 px-2">
            <span class="mr-3" v-if="!selectedAllMatching">已选择本页 {{ selectedIds.size }} 个词。</span>
            <span class="mr-3" v-if="selectedAllMatching">已选择全部 {{ allMatchingCount }} 个匹配词。</span>
            <v-btn
                v-if="selectedIds.size > 0 && !selectedAllMatching && wordCount > selectedIds.size"
                small
                rounded
                text
                color="primary"
                :loading="countingAllMatches"
                @click="selectAllMatching"
            >
                选择全部 {{ wordCount }} 个匹配结果
            </v-btn>
            <v-btn small rounded color="warning" class="mr-2" @click="batchIgnore" :loading="batchProcessing">
                <v-icon small class="mr-1">mdi-eye-off</v-icon>批量忽略
            </v-btn>
            <v-btn small rounded color="error" class="mr-2" @click="batchDelete" :loading="batchProcessing">
                <v-icon small class="mr-1">mdi-delete</v-icon>批量彻底删除
            </v-btn>
            <v-btn small rounded text @click="clearSelection">取消选择</v-btn>
        </div>

        <div class="px-2">
            <v-pagination class="my-6" v-model="currentPage" :length="pageCount" :total-visible="10" prev-icon="mdi-menu-left" next-icon="mdi-menu-right" @input="moveToPage(currentPage)" />
        </div>
    </v-container>
</template>

<script>
export default {
    data() {
        return {
            loading: false,
            error: '',
            filtersHidden: true,
            visiblePopup: '',
            words: [],
            wordCount: 0,
            books: [],
            pageCount: 1,
            currentPage: 1,
            vocabularyExportDialog: { active: false },
            vocabularyImportDialog: { active: false },
            vocabularyEditDialog: { active: false, itemId: -1, itemType: 'Word' },
            filters: {
                bookIndex: -1,
                stage: -999,
                book: -1,
                chapter: -1,
                translation: 'any',
                phrases: 'both',
                orderBy: 'words',
                text: ''
            },
            languageSpaces: true,
            // batch selection
            selectedIds: new Set(),
            selectedAllMatching: false,
            allMatchingCount: 0,
            countingAllMatches: false,
            batchProcessing: false,
        };
    },
    props: {
        language: String
    },
    computed: {
        isCjkLanguage() {
            return this.$props.language == 'japanese' || this.$props.language == 'chinese';
        },
        batchSelectEnabled() {
            return !this.loading;
        },
        colspan() {
            let count = this.isCjkLanguage ? 6 : 5;
            if (this.batchSelectEnabled) count++;
            return count;
        },
        allSelected() {
            if (!this.words.length) return false;
            const selectableWords = this.words.filter(w => w.type === 'word');
            return selectableWords.length > 0 && selectableWords.every(w => this.selectedIds.has(w.id));
        },
        someSelected() {
            return this.words.some(w => w.type === 'word' && this.selectedIds.has(w.id));
        },
    },
    mounted() {
        this.loading = true;
        document.getElementById('app').addEventListener('scroll', () => { this.visiblePopup = ''; });
        document.getElementById('app').addEventListener('click', () => { this.visiblePopup = ''; });

        if (this.$route.params.text !== undefined) {
            this.filters.text = (this.$route.params.text == 'anytext') ? '' : this.$route.params.text;
            this.filters.stage = this.$route.params.stage;
            this.filters.book = this.$route.params.book;
            this.filters.chapter = this.$route.params.chapter;
            this.filters.translation = this.$route.params.translation;
            this.filters.phrases = this.$route.params.phrases;
            this.filters.orderBy = this.$route.params.orderBy;
            this.currentPage = parseInt(this.$route.params.page);
        }

        this.loadVocabularySearchPage();
    },
    methods: {
        loadVocabularySearchPage() {
            this.loading = true;
            this.error = '';
            axios.post('/vocabulary/search', {
                text: (this.filters.text == '') ? 'anytext' : this.filters.text,
                book: parseInt(this.filters.book),
                chapter: parseInt(this.filters.chapter),
                stage: parseInt(this.filters.stage),
                translation: this.filters.translation,
                phrases: this.filters.phrases,
                orderBy: this.filters.orderBy,
                page: this.currentPage,
            }).then((response) => {
                const data = response.data;
                this.filters.bookIndex = data.bookIndex;
                this.words = data.words;
                this.books = data.books;
                this.pageCount = data.pageCount;
                this.currentPage = parseInt(data.currentPage);
                this.wordCount = data.wordCount;
                this.languageSpaces = data.languageSpaces;

                if (this.filters.text == 'anytext') {
                    this.filters.text = '';
                }
            }).catch((error) => {
                this.words = [];
                this.books = [];
                this.wordCount = 0;
                this.pageCount = 1;
                this.error = error?.response?.data?.message || error?.response?.data || '词汇加载失败。';
            }).finally(() => {
                this.loading = false;
            });
        },
        openExportDialog() {
            this.vocabularyExportDialog.active = true;
        },
        openImportDialog() {
            this.vocabularyImportDialog.active = true;
        },
        exportToCsv(fields) {
            const text = this.filters.text !== '' ? this.filters.text : 'anytext';
            axios.post('/vocabulary/export-to-csv', {
                fields: fields,
                text: text,
                stage: parseInt(this.filters.stage),
                book: parseInt(this.filters.book),
                chapter: parseInt(this.filters.chapter),
                translation: this.filters.translation,
                phrases: this.filters.phrases,
                orderBy: this.filters.orderBy
            }).then((response) => {
                const url = window.URL.createObjectURL(new Blob([response.data]));
                const link = document.createElement('a');
                link.href = url;
                link.setAttribute('download', 'vocabulary.csv');
                document.body.appendChild(link);
                link.click();
            }).catch((error) => {
                this.error = error?.response?.data?.message || error?.response?.data || '导出 CSV 失败。';
            });
        },
        editItem(itemId, itemType) {
            this.vocabularyEditDialog.active = true;
            this.vocabularyEditDialog.itemId = itemId;
            this.vocabularyEditDialog.itemType = itemType;
        },
        setWordStage(word, stage) {
            axios.post('/vocabulary/word/update', { id: word.id, stage: stage })
                .then(() => this.loadVocabularySearchPage())
                .catch((error) => {
                    this.error = error?.response?.data?.message || error?.response?.data || '词条更新失败。';
                });
        },
        deleteWord(word) {
            if (!window.confirm(`确定要彻底删除词条“${word.word}”吗？删除后它会从词汇页消失，但不会删除历史复习日志。`)) {
                return;
            }

            axios.post('/vocabulary/word/delete', { id: word.id })
                .then(() => this.loadVocabularySearchPage())
                .catch((error) => {
                    this.error = error?.response?.data?.message || error?.response?.data || '词条删除失败。';
                });
        },
        applyFilter(filter, newValue = -1, newBookIndex = -1) {
            this.clearSelection();

            if (filter !== 'text') {
                this.filters[filter] = newValue;
            }

            if (filter == 'book') {
                this.filters.chapter = -1;
                this.filters.bookIndex = newBookIndex;
            }

            const text = this.filters.text !== '' ? encodeURI(this.filters.text) : 'anytext';
            const url = '/vocabulary/search'
                + '/' + text
                + '/' + this.filters.stage
                + '/' + this.filters.book
                + '/' + this.filters.chapter
                + '/' + encodeURI(this.filters.translation)
                + '/' + encodeURI(this.filters.phrases)
                + '/' + encodeURI(this.filters.orderBy)
                + '/1';

            if (this.$router.currentRoute.path !== url) {
                this.$router.push(url);
            }
        },
        moveToPage(page) {
            this.clearSelection();

            const text = this.filters.text !== '' ? encodeURI(this.filters.text) : 'anytext';
            this.$router.push('/vocabulary/search'
                + '/' + text
                + '/' + this.filters.stage
                + '/' + this.filters.book
                + '/' + this.filters.chapter
                + '/' + encodeURI(this.filters.translation)
                + '/' + encodeURI(this.filters.phrases)
                + '/' + encodeURI(this.filters.orderBy)
                + '/' + page);
        },
        displayWord(word) {
            if (word.type !== 'phrase') {
                return word.word;
            }

            const words = JSON.parse(word.word);
            return this.languageSpaces ? words.join(' ') : words.join('');
        },
        // --- batch selection ---
        toggleWord(word) {
            if (word.type !== 'word') {
                return;
            }

            this.selectedAllMatching = false;
            this.allMatchingCount = 0;

            if (this.selectedIds.has(word.id)) {
                this.selectedIds.delete(word.id);
            } else {
                this.selectedIds.add(word.id);
            }
            // force reactivity
            this.selectedIds = new Set(this.selectedIds);
        },
        toggleSelectAll() {
            this.selectedAllMatching = false;
            this.allMatchingCount = 0;

            if (this.allSelected) {
                this.clearSelection();
            } else {
                this.words.filter(w => w.type === 'word').forEach(w => this.selectedIds.add(w.id));
                this.selectedIds = new Set(this.selectedIds);
            }
        },
        clearSelection() {
            this.selectedIds = new Set();
            this.selectedAllMatching = false;
            this.allMatchingCount = 0;
        },
        currentFilterPayload() {
            return {
                text: (this.filters.text == '') ? 'anytext' : this.filters.text,
                book: parseInt(this.filters.book),
                chapter: parseInt(this.filters.chapter),
                stage: parseInt(this.filters.stage),
                translation: this.filters.translation,
                phrases: this.filters.phrases,
                orderBy: this.filters.orderBy,
            };
        },
        selectAllMatching() {
            this.countingAllMatches = true;
            axios.post('/vocabulary/words/bulk-hard-delete-count', {
                filters: this.currentFilterPayload(),
            }).then((response) => {
                this.allMatchingCount = response.data.count || 0;
                if (this.allMatchingCount === 0) {
                    this.error = '当前筛选条件下没有可彻底删除的单词。';
                    this.clearSelection();
                    return;
                }

                this.selectedAllMatching = true;
                this.error = '';
            }).catch((error) => {
                this.error = error?.response?.data?.message || error?.response?.data || '统计匹配词条失败。';
            }).finally(() => {
                this.countingAllMatches = false;
            });
        },
        batchIgnore() {
            if (this.selectedAllMatching) {
                this.error = '批量忽略只支持当前页已选词；如需跨页操作，请使用批量彻底删除。';
                return;
            }

            const ids = Array.from(this.selectedIds);
            if (!ids.length) return;
            if (!window.confirm(`确定要忽略已选的 ${ids.length} 个词条吗？这会将它们标为已忽略并停用复习卡。`)) {
                return;
            }

            this.batchProcessing = true;
            axios.post('/vocabulary/words/batch-ignore', { ids: ids })
                .then((response) => {
                    const ignored = response.data.ignored || 0;
                    this.error = '';
                    this.clearSelection();
                    this.loadVocabularySearchPage();
                })
                .catch((error) => {
                    this.error = error?.response?.data?.message || error?.response?.data || '批量忽略失败。';
                })
                .finally(() => {
                    this.batchProcessing = false;
                });
        },
        batchDelete() {
            const ids = Array.from(this.selectedIds);
            const deleteCount = this.selectedAllMatching ? this.allMatchingCount : ids.length;
            if (!deleteCount) return;

            const confirmMessage = this.selectedAllMatching
                ? `确定要彻底删除当前筛选条件下的全部 ${deleteCount} 个匹配词吗？此操作会跨页生效，词条会从词汇页消失，但不会删除历史复习日志。`
                : `确定要彻底删除已选的 ${deleteCount} 个词条吗？词条会从词汇页消失，但不会删除历史复习日志。`;

            if (!window.confirm(confirmMessage)) {
                return;
            }

            this.batchProcessing = true;
            const request = this.selectedAllMatching
                ? axios.post('/vocabulary/words/bulk-hard-delete', { filters: this.currentFilterPayload() })
                : axios.post('/vocabulary/words/batch-hard-delete', { ids: ids });

            request
                .then((response) => {
                    const deleted = response.data.deleted || 0;
                    this.error = '';
                    this.clearSelection();
                    this.loadVocabularySearchPage();
                })
                .catch((error) => {
                    this.error = error?.response?.data?.message || error?.response?.data || '批量彻底删除失败。';
                })
                .finally(() => {
                    this.batchProcessing = false;
                });
        },
    }
}
</script>
