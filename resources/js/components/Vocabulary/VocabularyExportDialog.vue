<template>
    <v-dialog v-model="value" persistent scrollable width="900px">
        <v-card
            id="vocabulary-export-dialog"
            class="rounded-lg"
        >
            <!-- Title bar -->
            <v-card-title>
                <span class="text-h5">词汇导出</span>
                <v-spacer></v-spacer>
                <v-btn icon @click="close">
                    <v-icon>mdi-close</v-icon>
                </v-btn>
            </v-card-title>

            <v-card-text>
                <!-- Field selection switches -->
                <label class="font-weight-bold mt-2">选择要导出的字段</label>
                <div class="d-flex flex-wrap">
                    <!-- Lemma switch -->
                    <v-checkbox
                        v-model="fields.lemma"
                        hide-details
                        class="vocabulary-export-switch my-1"
                        color="primary"
                        label="词元"
                        @change="fieldSwitchChange"
                    ></v-checkbox>

                    <!-- Word switch -->
                    <v-checkbox
                        v-model="fields.word"
                        hide-details
                        class="vocabulary-export-switch my-1"
                        color="primary"
                        label="单词"
                        @change="fieldSwitchChange"
                    ></v-checkbox>

                    <!-- Lemma reading switch -->
                    <v-checkbox
                        v-model="fields.lemmaReading"
                        hide-details
                        class="vocabulary-export-switch my-1"
                        color="primary"
                        label="词元读音"
                        @change="fieldSwitchChange"
                    ></v-checkbox>

                    <!-- Reading switch -->
                    <v-checkbox
                        v-model="fields.reading"
                        hide-details
                        class="vocabulary-export-switch my-1"
                        color="primary"
                        label="读音"
                        @change="fieldSwitchChange"
                    ></v-checkbox>

                    <!-- Translation switch -->
                    <v-checkbox
                        v-model="fields.translation"
                        hide-details
                        class="vocabulary-export-switch my-1"
                        color="primary"
                        label="释义"
                        @change="fieldSwitchChange"
                    ></v-checkbox>

                    <!-- Stage switch -->
                    <v-checkbox
                        v-model="fields.stage"
                        hide-details
                        class="vocabulary-export-switch my-1"
                        color="primary"
                        label="等级"
                        @change="fieldSwitchChange"
                    ></v-checkbox>

                    <!-- Added to srs switch -->
                    <v-checkbox
                        v-model="fields.addedToSrs"
                        hide-details
                        class="vocabulary-export-switch my-1"
                        color="primary"
                        label="加入复习日期"
                        @change="fieldSwitchChange"
                    ></v-checkbox>

                    <!-- Read count switch -->
                    <v-checkbox
                        v-model="fields.readCount"
                        hide-details
                        class="vocabulary-export-switch my-1"
                        color="primary"
                        label="阅读次数"
                        @change="fieldSwitchChange"
                    ></v-checkbox>

                    <!-- Lookup count switch -->
                    <v-checkbox
                        v-model="fields.lookupCount"
                        hide-details
                        class="vocabulary-export-switch my-1"
                        color="primary"
                        label="查询次数"
                        @change="fieldSwitchChange"
                    ></v-checkbox>
                </div>

                <!-- Sample -->
                <label class="font-weight-bold mt-6">预览</label>
                <v-simple-table fixed-header id="vocabulary-export-sample-table" class="border rounded-lg" height="260px">
                    <thead>
                        <tr>
                            <th v-if="fields.lemma">词元</th>
                            <th v-if="fields.word">单词</th>
                            <th v-if="fields.lemmaReading">词元读音</th>
                            <th v-if="fields.reading">读音</th>
                            <th v-if="fields.translation">释义</th>
                            <th v-if="fields.stage">等级</th>
                            <th v-if="fields.addedToSrs">加入复习</th>
                            <th v-if="fields.readCount">阅读次数</th>
                            <th v-if="fields.lookupCount">查询次数</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(sampleWord, wordIndex) in $props.sampleWords.slice(0, 10)" :key="wordIndex">
                            <td class="default-font" v-if="fields.lemma">{{ sampleWord.base_word }}</td>
                            <td class="default-font" v-if="fields.word">
                                <!-- Word -->
                                <template v-if="sampleWord.type == 'word'">
                                    {{ sampleWord.word }}
                                </template>

                                <!-- Language with spaces -->
                                <template v-if="sampleWord.type == 'phrase' && languageSpaces">
                                    {{ JSON.parse(sampleWord.word).join(' ') }}
                                </template>

                                <!-- Language without spaces -->
                                <template v-if="sampleWord.type == 'phrase' && !languageSpaces">
                                    {{ JSON.parse(sampleWord.word).join('') }}
                                </template>
                            </td>
                            <td class="default-font" v-if="fields.lemmaReading">{{ sampleWord.base_word_reading }}</td>
                            <td class="default-font" v-if="fields.reading">{{ sampleWord.reading }}</td>
                            <td v-if="fields.translation">{{ sampleWord.translation }}</td>
                            <td v-if="fields.stage">{{ sampleWord.stage }}</td>
                            <td v-if="fields.addedToSrs">{{ sampleWord.added_to_srs }}</td>
                            <td v-if="fields.readCount">{{ sampleWord.read_count }}</td>
                            <td v-if="fields.lookupCount">{{ sampleWord.lookup_count }}</td>
                        </tr>
                    </tbody>
                </v-simple-table>

            </v-card-text>

            <!-- Action buttons -->
            <v-card-actions>
                <v-checkbox
                    v-model="fields.selectAll"
                    hide-details
                    class="select-all-switch vocabulary-export-switch my-1"
                    color="primary"
                    label="全选"
                    @change="selectAll"
                ></v-checkbox>
                <v-checkbox
                    v-model="fields.selectAll"
                    hide-details
                    class="select-all-switch-small vocabulary-export-switch my-1"
                    color="primary"
                    label="全部"
                    @change="selectAll"
                ></v-checkbox>

                <v-spacer></v-spacer>
                <v-btn rounded text @click="close">取消</v-btn>
                <v-btn
                    rounded
                    depressed
                    color="primary"
                    :disabled="!fields.any"
                    @click="exportToCsv"
                >导出</v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>
</template>

<script>
    export default {
        props: {
            value : Boolean,
            language: String,
            languageSpaces: Boolean,
            sampleWords: Array
        },
        emits: ['input'],
        data: function() {
            return {
                fields: {
                    selectAll: false,
                    lemma: false,
                    word: false,
                    lemmaReading: false,
                    reading: false,
                    translation: false,
                    stage: false,
                    addedToSrs: false,
                    readCount: false,
                    lookupCount: false
                },
                saving: false,
            };
        },
        mounted: function() {
        },
        methods: {
            selectAll() {
                this.fields.lemma = this.fields.selectAll;
                this.fields.word = this.fields.selectAll;
                this.fields.lemmaReading = this.fields.selectAll;
                this.fields.reading = this.fields.selectAll;
                this.fields.translation = this.fields.selectAll;
                this.fields.stage = this.fields.selectAll;
                this.fields.addedToSrs = this.fields.selectAll;
                this.fields.readCount = this.fields.selectAll;
                this.fields.lookupCount = this.fields.selectAll;
                this.fieldSwitchChange();
            },
            fieldSwitchChange() {
                if (
                    this.fields.lemma ||
                    this.fields.word ||
                    this.fields.lemmaReading ||
                    this.fields.reading ||
                    this.fields.translation ||
                    this.fields.stage ||
                    this.fields.addedToSrs ||
                    this.fields.readCount ||
                    this.fields.lookupCount
                ) {
                    this.fields.any = true;
                } else {
                    this.fields.any = false;
                }
                if (this.fields.selectAll === true && Object.values(this.fields).some(el => el === false)) {
                    this.fields.selectAll = false;
                }
            },
            exportToCsv() {
                this.$emit('export-to-csv', {
                    lemma: {
                        export: this.fields.lemma,
                        headerName: 'Lemma',
                        searchObjectProperty: 'base_word'
                    },
                    word: {
                        export: this.fields.word,
                        headerName: 'Word',
                        searchObjectProperty: 'word'
                    },
                    lemmaReading: {
                        export: this.fields.lemmaReading,
                        headerName: 'Lemma reading',
                        searchObjectProperty: 'base_word_reading'
                    },
                    reading: {
                        export: this.fields.reading,
                        headerName: 'Reading',
                        searchObjectProperty: 'reading'
                    },
                    translation: {
                        export: this.fields.translation,
                        headerName: 'Translation',
                        searchObjectProperty: 'translation'
                    },
                    stage: {
                        export: this.fields.stage,
                        headerName: 'Stage',
                        searchObjectProperty: 'stage'
                    },
                    addedToSrs: {
                        export: this.fields.addedToSrs,
                        headerName: 'Added to srs',
                        searchObjectProperty: 'added_to_srs'
                    },
                    readCount: {
                        export: this.fields.readCount,
                        headerName: 'Read count',
                        searchObjectProperty: 'read_count'
                    },
                    lookupCount: {
                        export: this.fields.lookupCount,
                        headerName: 'Lookup count',
                        searchObjectProperty: 'lookup_count'
                    },
                });
                this.$emit('input', false);
            },
            close() {
                this.$emit('input', false);
            }
        }
    }
</script>
