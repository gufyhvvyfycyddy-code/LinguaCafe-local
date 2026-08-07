<template>
    <v-card 
        outlined 
        id="vocab-hover-box" 
        :class="{
            'd-flex': true,
            'flex-wrap': true,
            'rounded-lg': true,
            'pa-3': true,
            'arrow-top': arrowPosition == 'top',
        }"
        :style="{
            'position': 'absolute',
            'left': positionLeft + 'px',
            'top': positionTop + 'px',
        }"
    >
        <div class="header d-flex justify-space-between">
            <!-- Reading -->
            <div v-if="reading.length" class="selected-font">
                {{ reading }}
            </div>

            <!-- Stage -->
            <div class="stage rounded-pill px-4" v-if="stage !== null" :stage="stage">
                {{ stage * -1 }}
            </div>
        </div>

        <!-- Translations -->
        <ul class="mb-0 pl-0">
            <!-- User translations -->
            <li v-for="(translation, translationIndex) in userTranslationList" :key="'user-' + translationIndex">
                <v-icon small>mdi-account-edit</v-icon> {{ translation }}
            </li>

            <!-- Dictionary translations loading -->
            <template v-if="dictionaryTranslation === 'loading'">
                <li>
                    <v-progress-circular indeterminate class="mx-1" size="10" width="2" color="primary"></v-progress-circular> searching
                </li>
            </template>

            <!-- Dictionary translations -->
            <template v-if="dictionaryTranslationList.length">
                <li v-for="(translation, translationIndex) in dictionaryTranslationList" :key="'dictionary-' + translationIndex">
                    <v-icon small>mdi-list-box</v-icon> {{ translation }}
                </li>
            </template>

            <li v-if="dictionaryWarning" class="warning--text">
                <v-icon small color="warning">mdi-alert-outline</v-icon>
                部分词典暂时不可用
            </li>

            <li v-if="dictionaryTranslation === 'dictionary-unavailable'" class="error--text">
                <v-icon small color="error">mdi-alert-circle-outline</v-icon>
                词典服务暂时不可用
            </li>

            <li v-if="dictionaryTranslation === 'dictionary-not-configured'" class="text--secondary">
                <v-icon small>mdi-database-off-outline</v-icon>
                尚未配置本地词典
            </li>
    
            <!-- Api translations -->
            <template v-if="displayedApiTranslations.length">
                <li key="api-translation" v-for="(translation, index) in displayedApiTranslations" :key="'api-' + index">
                    <v-icon small>mdi-translate</v-icon> {{ translation }}
                </li>
            </template>

            <li v-if="apiDictionaryWarning" class="warning--text">
                <v-icon small color="warning">mdi-alert-outline</v-icon>
                部分在线词典暂时不可用
            </li>

            <li v-if="apiTranslations.includes('dictionary-unavailable')" class="error--text">
                <v-icon small color="error">mdi-alert-circle-outline</v-icon>
                在线词典服务暂时不可用
            </li>

            <!-- Api translations loading -->
            <template v-if="apiTranslations.length && apiTranslations[0] === 'loading'">
                <li>
                    <v-progress-circular indeterminate class="mx-1" size="10" width="2" color="#92B9E2"></v-progress-circular> searching
                </li>
            </template>
            
        </ul>
    </v-card>
</template>

<script>
    import { mapState } from 'vuex';
    
    export default {
        data: function() {
            return {
                userTranslationList: [],
                dictionaryTranslationList: [],
                dictionaryWarning: false,
            }
        },
        computed: {
            ...mapState({
                arrowPosition: state => state.hoverVocabularyBox.arrowPosition,
                positionLeft: state => state.hoverVocabularyBox.positionLeft,
                positionTop: state => state.hoverVocabularyBox.positionTop,
                reading: state => state.hoverVocabularyBox.reading,
                stage: state => state.hoverVocabularyBox.stage,
                dictionaryTranslation: state => state.hoverVocabularyBox.dictionaryTranslation,
                apiTranslations: state => state.hoverVocabularyBox.apiTranslations,
            }),
            displayedApiTranslations() {
                return this.apiTranslations.filter(value => ![
                    'loading',
                    'error',
                    'dictionary-warning',
                    'dictionary-unavailable',
                ].includes(value));
            },
            apiDictionaryWarning() {
                return this.apiTranslations.includes('dictionary-warning');
            },
        },
        props: {
        },
        mounted() {
            this.translationList = []; 
            this.dictionaryList = []; 

            if (this.$store.state.hoverVocabularyBox.userTranslation.length) {
                this.userTranslationList = this.$store.state.hoverVocabularyBox.userTranslation.split(';');
            }

            const dictionaryTranslation = this.$store.state.hoverVocabularyBox.dictionaryTranslation;
            if (dictionaryTranslation.startsWith('dictionary-warning:')) {
                this.dictionaryWarning = true;
                this.dictionaryTranslationList = dictionaryTranslation
                    .slice('dictionary-warning:'.length)
                    .split(';')
                    .filter(Boolean);
            } else if (
                dictionaryTranslation.length
                && !['loading', 'dictionary-search-disabled', 'dictionary-unavailable', 'dictionary-not-configured'].includes(dictionaryTranslation)
            ) {
                this.dictionaryTranslationList = dictionaryTranslation.split(';').filter(Boolean);
            }
        },
        methods: {
        }
    }
</script>
