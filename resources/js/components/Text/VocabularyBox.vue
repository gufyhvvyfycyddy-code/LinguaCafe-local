<template>
    <v-card 
        id="vocab-box" 
        :class="{
            'new-phrase': type === 'new-phrase', 
            'rounded-lg': true,
            'd-flex': true
        }" 
        :style="{
            'top': positionTop + 'px', 
            'left': positionLeft + 'px',
            'width': width + 'px'
        }"
        @mouseup.stop=";"
    >   
        <!-- Vocab box content -->
        <div class="vocab-box-content pa-4 pb-1">
            <v-tabs-items v-model="tab">
                <!-- Word info page -->
                <v-tab-item :value="0">
                    <v-card-text class="pa-0">
                        <!-- Single word -->
                        <template v-if="type === 'word'">
                            <div class="vocab-box-subheader mb-2 mt-0"><span class="rounded-pill py-1 px-3">单词</span></div>
                            <!-- With base word -->
                            <div class="expression mb-2 text-center default-font" v-if="baseWord !== ''">
                                <ruby>
                                    {{ word }}
                                    <rt v-if="($props.language == 'japanese' || $props.language == 'chinese')">
                                        {{ reading }}
                                    </rt>
                                </ruby>
                                <v-icon color="text">mdi-arrow-right-thick</v-icon>
                                <ruby>
                                    {{ baseWord }}
                                    <rt v-if="($props.language == 'japanese' || $props.language == 'chinese')">
                                        {{ baseWordReading }}
                                    </rt>
                                </ruby>
                            </div>
                            
                            <!-- No base word -->
                            <div 
                                class="expression mb-2 text-center default-font" 
                                v-if="baseWord == ''"
                            >
                                <ruby>
                                    {{ word }}
                                    <rt v-if="($props.language == 'japanese' || $props.language == 'chinese')">
                                        {{ reading }}
                                    </rt>
                                </ruby>
                            </div>
                        </template>

                        <!-- Phrase -->
                        <template v-if="type !== 'word'">
                            <div class="vocab-box-subheader mb-2 mt-0"><span class="rounded-pill py-1 px-3">短语</span></div>
                            <!-- Phrase text -->
                            <div class="expression mb-2 default-font">
                                <template v-for="(word, index) in phrase" v-if="word.word !== 'NEWLINE'">
                                    <span :class="{'mr-2': word.spaceAfter}">{{ word.word }}</span>
                                </template>
                            </div>

                            <!-- Phrase reading -->
                            <template v-if="($props.language == 'japanese' || $props.language == 'chinese')">
                                <div class="vocab-box-subheader mb-2 mt-4"><span class="rounded-pill py-1 px-3">读音</span></div>
                                <div class="expression mb-2 mt-4 default-font">{{ reading }}</div>
                            </template>
                        </template>

                        <!-- Stage buttons-->
                        <template v-if="type !== 'new-phrase'">
                            <div class="vocab-box-subheader d-flex mb-2 mt-4">
                                <span class="rounded-pill py-1 px-3">等级</span>
                                <v-spacer />

                                <!-- Level info box -->
                                <v-menu offset-y left nudge-top="-12px">
                                    <template v-slot:activator="{ on, attrs }">
                                        <div>
                                            <v-icon class="mr-2" v-bind="attrs" v-on="on">mdi-help-circle-outline</v-icon>
                                        </div>
                                    </template>
                                    <v-card outlined class="rounded-lg pa-4" width="320px">
                                        单词或短语的等级表示你对它的熟悉程度。
                                        越接近 0，表示越接近学会，在复习中出现得也越少。<br><br>

                                        <v-icon class="mr-2">mdi-check</v-icon>
                                        表示已知词。<br>
                                        <v-icon class="mr-2">mdi-close</v-icon>
                                        表示已忽略词。已忽略词不会计入已学词统计。
                                    </v-card>
                                </v-menu>
                            </div>
                            <div id="vocab-box-stage-buttons" class="mb-4">
                                <v-btn :class="{'v-btn--active': stage == -7}" @click="setStage(-7)">7</v-btn>
                                <v-btn :class="{'v-btn--active': stage == -6}" @click="setStage(-6)">6</v-btn>
                                <v-btn :class="{'v-btn--active': stage == -5}" @click="setStage(-5)">5</v-btn>
                                <v-btn :class="{'v-btn--active': stage == -4}" @click="setStage(-4)">4</v-btn>
                                <v-btn :class="{'v-btn--active': stage == -3}" @click="setStage(-3)">3</v-btn>
                                <v-btn :class="{'v-btn--active': stage == -2}" @click="setStage(-2)">2</v-btn>
                                <v-btn :class="{'v-btn--active': stage == -1}" @click="setStage(-1)">1</v-btn>
                                <v-btn 
                                    :class="{'v-btn--active': stage == 0}"
                                    @click="setStage(0)" 
                                >
                                    <v-icon>mdi-check</v-icon>
                                </v-btn>
                                <v-btn 
                                    :class="{'v-btn--active': stage == 1}" 
                                    @click="setStage(1)" 
                                    v-if="type == 'word'"
                                >
                                    <v-icon>mdi-close</v-icon>
                                </v-btn>
                            </div>
                            <div v-if="type == 'word'" class="d-flex flex-wrap mb-3">
                                <v-btn small rounded depressed color="warning" class="mr-2 mb-2" @click="setStage(1)">忽略</v-btn>
                                <v-btn small rounded depressed color="success" class="mr-2 mb-2" @click="setStage(0)">标为已知</v-btn>
                                <v-btn small rounded depressed color="error" class="mr-2 mb-2" @click="deleteWord">回归为新词</v-btn>
                                <v-btn
                                    small
                                    rounded
                                    outlined
                                    color="primary"
                                    class="mb-2"
                                    :loading="aiStudyCardPendingLoading"
                                    @click="markAiStudyCardPending"
                                >
                                    <v-icon x-small class="mr-1">mdi-lightbulb-outline</v-icon>
                                    待 AI 解释
                                </v-btn>
                                <v-btn
                                    small
                                    rounded
                                    depressed
                                    color="info"
                                    class="mb-2"
                                    @click="openAiPendingListDialog"
                                >
                                    <v-icon x-small class="mr-1">mdi-format-list-bulleted</v-icon>
                                    待 AI 解释列表
                                </v-btn>
                            </div>
                            <v-alert
                                v-if="aiStudyCardPendingMessage"
                                dense
                                text
                                class="mb-3"
                                :type="aiStudyCardPendingError ? 'error' : 'success'"
                            >{{ aiStudyCardPendingMessage }}</v-alert>
                        </template>
                      
                        <!-- Search field -->
                        <v-text-field 
                            placeholder="词典搜索"
                            class="dictionary-search-field mt-2 mb-3 default-font"
                            filled
                            dense
                            rounded
                            width="100%"
                            hide-details
                            prepend-inner-icon="mdi-magnify"
                            :value="searchField"
                            @change="searchFieldChanged"
                            @keydown.stop=";"
                        ></v-text-field>

                        <div class="vocab-box-subheader d-flex mt-3">候选结果（AI + 词典）</div>
                        <!-- Search box -->
                        <vocabulary-search-box
                            :any-api-dictionary-enabled="$props.anyApiDictionaryEnabled"
                            :language="$props.language"
                            :searchTerm="searchField"
                            :ai-vocab-suggestions="aiVocabSuggestions"
                            :ai-phrase-suggestions="aiPhraseSuggestions"
                            :ai-lookup-loading="aiLookupLoading"
                            :ai-lookup-error="aiLookupError"
                            @addDefinitionToInput="addDefinitionToInput"
                            @addDefinitionAsSense="addDefinitionAsSense"
                            @use-vocab-suggestion="useAiSuggestion"
                            @use-phrase-suggestion="useAiPhraseSuggestion"
                        ></vocabulary-search-box>

                        <!-- Saved word senses -->
                        <word-senses-list ref="wordSensesList" v-if="type === 'word'" :study-base="studyBase" :base-word="baseWord" :lemma="baseWord || word" :surface="word" :word="word" :language="$props.language" :legacy-translation="translationText" @word-learning-updated="$emit('word-learning-updated', $event)" />
                    </v-card-text>

                    <v-card-actions v-if="type !== 'word'" class="mt-2 pl-0">
                        <v-spacer />
                        <v-btn 
                            small
                            rounded
                            color="success"
                            @click="addNewPhrase"
                            v-if="type == 'new-phrase'"
                        >保存短语</v-btn>
                        <v-btn 
                            small
                            rounded
                            color="error"
                            @click="deletePhrase"
                            v-if="type == 'phrase'"
                        >删除短语</v-btn>
                    </v-card-actions>
                </v-tab-item>

                <!-- Editing page -->
                <v-tab-item :value="1">
                    <v-card-text id="vocab-box-edit-page" class="pa-0">
                        <!-- Word text fields -->
                        <div class="d-flex" v-if="type == 'word'">
                            <v-text-field 
                                :class="{'default-font': true, 'mt-2': true, 'mb-2': ($props.language !== 'japanese' && $props.language !== 'chinese')}"
                                hide-details
                                label="词元"
                                filled
                                dense
                                rounded
                                v-model="baseWord"
                                @keyup="inputChanged"
                                @keydown.stop=";"
                            ></v-text-field>
                            <v-text-field 
                                :class="{'default-font': true, 'mt-2': true, 'mb-2': ($props.language !== 'japanese' && $props.language !== 'chinese')}"
                                hide-details
                                label="单词"
                                disabled
                                filled
                                dense
                                rounded
                                :value="word"
                                @keyup="inputChanged"
                                @keydown.stop=";"
                            ></v-text-field>
                        </div>

                        <!-- Reading fields -->
                        <div class="d-flex" v-if="type == 'word' && ($props.language == 'japanese' || $props.language == 'chinese')">
                            <v-text-field 
                                class="my-2 default-font"
                                hide-details
                                label="词元读音"
                                filled
                                dense
                                rounded
                                v-model="baseWordReading"
                                @keyup="inputChanged"
                                @keydown.stop=";"
                            ></v-text-field>
                            <v-text-field 
                                class="my-2 default-font"
                                hide-details
                                label="读音"
                                filled
                                dense
                                rounded
                                v-model="reading"
                                @keyup="inputChanged"
                                @keydown.stop=";"
                            ></v-text-field>
                        </div>

                        <!-- Phrase fields -->
                        <v-textarea
                            v-if="type !== 'word' && ($props.language == 'japanese' || $props.language == 'chinese')"
                            class="my-2 default-font"
                            label="读音"
                            filled
                            dense
                            no-resize
                            rounded
                            hide-details
                            height="100"
                            v-model="reading"
                            @keyup="inputChanged"
                            @keydown.stop=";"
                        ></v-textarea>
                    </v-card-text>
                </v-tab-item>

                <!-- Inflections tab -->
                <v-tab-item :value="2">
                    <v-simple-table
                        v-if="inflections.length"
                        class="border rounded-lg no-hover mx-auto default-font" 
                    >
                        <thead>
                            <tr>
                                <th class="text-center">形式</th>
                                <th class="text-center">肯定</th>
                                <th class="text-center">否定</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(inflection, index) in inflections" :key="index">
                                <td class="px-2">{{ inflection.name }}</td>
                                <td class="px-1 text-center">{{ inflection.affPlain }}</td>
                                <td class="px-1 text-center">{{ inflection.negPlain }}</td>
                            </tr>
                        </tbody>
                    </v-simple-table>
                </v-tab-item>
            </v-tabs-items>
        </div>

        <!-- Vocab box toolbar -->
        <div class="vocab-box-toolbar d-flex flex-column align-center flex-wrap pt-1 rounded-r-lg">
            <v-btn icon @click="close" title="关闭"><v-icon>mdi-close</v-icon></v-btn>
            <v-btn icon @click="tab = 1;" title="编辑" v-if="tab == 0"><v-icon>mdi-pencil</v-icon></v-btn>
            <v-btn icon @click="addSelectedWordToAnki" v-if="tab === 0 && type !== 'new-phrase'" title="发送到 Anki"><v-icon class="mr-1">mdi-cards</v-icon></v-btn>
            <v-btn icon v-if="tab == 0 && $props.textToSpeechAvailable" title="朗读" @click="textToSpeech"><v-icon>mdi-bullhorn</v-icon></v-btn>
            <v-btn icon @click="tab = 2;" title="显示变形" v-if="tab == 0 && inflections.length"><v-icon>mdi-list-box</v-icon></v-btn>
            <v-btn icon @click="tab = 0;" v-if="tab !== 0" title="返回"><v-icon>mdi-arrow-left</v-icon></v-btn>
        </div>

        <!-- V3: 待 AI 解释列表面板（含已取消视图 + 恢复按钮） -->
        <v-dialog v-model="aiPendingListDialog" max-width="640" scrollable>
            <v-card>
                <v-card-title class="d-flex align-center">
                    <v-icon small class="mr-2">mdi-format-list-bulleted</v-icon>
                    待 AI 解释的词
                    <v-spacer />
                    <v-btn icon small @click="aiPendingListDialog = false"><v-icon>mdi-close</v-icon></v-btn>
                </v-card-title>
                <v-card-text style="max-height: 60vh;">
                    <!-- V3: 待解释 / 已取消 切换 -->
                    <div class="d-flex align-center mt-2 mb-2">
                        <v-btn-toggle v-model="aiPendingListStatusFilter" dense mandatory>
                            <v-btn x-small value="pending">
                                待解释 ({{ aiPendingItems.length }})
                            </v-btn>
                            <v-btn x-small value="dismissed">
                                已取消 ({{ aiPendingDismissedItems.length }})
                            </v-btn>
                        </v-btn-toggle>
                    </div>

                    <v-alert
                        v-if="aiPendingListError"
                        dense
                        text
                        type="error"
                        class="mt-2"
                    >{{ aiPendingListError }}</v-alert>
                    <v-alert
                        v-if="aiPendingListMessage"
                        dense
                        text
                        type="success"
                        class="mt-2"
                    >{{ aiPendingListMessage }}</v-alert>

                    <div v-if="aiPendingListLoading" class="text-center pa-4">
                        <v-progress-circular indeterminate color="primary" />
                    </div>

                    <!-- 待解释列表 -->
                    <template v-else-if="aiPendingListStatusFilter === 'pending'">
                        <div v-if="aiPendingItems.length === 0" class="text-center text--secondary pa-4">
                            暂无待 AI 解释的词。
                        </div>
                        <v-list v-else dense>
                            <v-list-item v-for="item in aiPendingItems" :key="item.id" class="px-0">
                                <v-list-item-content>
                                    <v-list-item-title class="d-flex align-center">
                                        <span class="font-weight-medium default-font">{{ item.word }}</span>
                                        <span v-if="item.lemma && item.lemma !== item.word" class="text-caption text--secondary ml-2">({{ item.lemma }})</span>
                                    </v-list-item-title>
                                    <v-list-item-subtitle v-if="item.sentence_text" class="text-caption text--secondary mt-1" style="white-space: normal; line-height: 1.4;">
                                        {{ item.sentence_text }}
                                    </v-list-item-subtitle>
                                    <v-list-item-subtitle class="text-caption text--secondary mt-1">
                                        状态：{{ item.status === 'pending' ? '待解释' : item.status }}
                                        <span class="ml-2">| 添加于 {{ formatDate(item.created_at) }}</span>
                                    </v-list-item-subtitle>
                                </v-list-item-content>
                                <v-list-item-action>
                                    <v-btn
                                        x-small
                                        rounded
                                        depressed
                                        color="error"
                                        :loading="aiPendingDismissLoadingId === item.id"
                                        @click="dismissAiPendingItem(item.id)"
                                    >
                                        <v-icon x-small class="mr-1">mdi-close</v-icon>
                                        取消
                                    </v-btn>
                                </v-list-item-action>
                            </v-list-item>
                        </v-list>
                    </template>

                    <!-- V3: 已取消列表（含恢复按钮） -->
                    <template v-else-if="aiPendingListStatusFilter === 'dismissed'">
                        <div v-if="aiPendingDismissedItems.length === 0" class="text-center text--secondary pa-4">
                            暂无已取消的词。
                        </div>
                        <v-list v-else dense>
                            <v-list-item v-for="item in aiPendingDismissedItems" :key="item.id" class="px-0">
                                <v-list-item-content>
                                    <v-list-item-title class="d-flex align-center">
                                        <span class="font-weight-medium default-font">{{ item.word }}</span>
                                        <span v-if="item.lemma && item.lemma !== item.word" class="text-caption text--secondary ml-2">({{ item.lemma }})</span>
                                    </v-list-item-title>
                                    <v-list-item-subtitle v-if="item.sentence_text" class="text-caption text--secondary mt-1" style="white-space: normal; line-height: 1.4;">
                                        {{ item.sentence_text }}
                                    </v-list-item-subtitle>
                                    <v-list-item-subtitle class="text-caption text--secondary mt-1">
                                        状态：已取消
                                        <span class="ml-2">| 添加于 {{ formatDate(item.created_at) }}</span>
                                    </v-list-item-subtitle>
                                </v-list-item-content>
                                <v-list-item-action>
                                    <v-btn
                                        x-small
                                        rounded
                                        depressed
                                        color="success"
                                        :loading="aiPendingRestoreLoadingId === item.id"
                                        @click="restoreAiPendingItem(item.id)"
                                    >
                                        <v-icon x-small class="mr-1">mdi-restore</v-icon>
                                        恢复
                                    </v-btn>
                                </v-list-item-action>
                            </v-list-item>
                        </v-list>
                    </template>
                </v-card-text>
                <v-card-actions class="d-flex flex-column align-stretch pa-3">
                    <v-btn
                        block
                        color="primary"
                        :disabled="aiPendingItems.length === 0"
                        @click="openAiStudyCardPreview"
                    >
                        <v-icon small class="mr-1">mdi-rocket-launch</v-icon>
                        生成 AI 示意卡
                    </v-btn>
                    <div class="text-caption text--secondary text-center mt-2">
                        当前共 {{ aiPendingItems.length }} 个待解释词
                    </div>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- V3: 生成 AI 示意卡预览弹窗（真预览内容 + 勾选 + 安全生成包） -->
        <v-dialog v-model="aiStudyCardPreviewDialog" max-width="760" scrollable>
            <v-card>
                <v-card-title class="d-flex align-center">
                    <v-icon small class="mr-2">mdi-rocket-launch</v-icon>
                    生成 AI 示意卡预览
                    <v-spacer />
                    <v-btn icon small @click="aiStudyCardPreviewDialog = false"><v-icon>mdi-close</v-icon></v-btn>
                </v-card-title>
                <v-card-text style="max-height: 65vh;">
                    <!-- 安全说明 -->
                    <v-alert
                        dense
                        text
                        type="info"
                        class="mt-2"
                    >
                        当前只是预览，不会调用 AI，也不会生成复习卡。
                    </v-alert>

                    <!-- V3: 用户已选词区域（带勾选 + 来源句子 + 位置 + 数量） -->
                    <div class="mt-4">
                        <div class="d-flex align-center mb-2">
                            <v-icon x-small class="mr-1">mdi-account-check</v-icon>
                            <span class="text-subtitle-1 font-weight-medium">你已选的词</span>
                            <v-spacer />
                            <span class="text-caption text--secondary">
                                共 {{ aiPendingItems.length }} 个，已勾选 {{ aiPreviewSelectedItemIds.length }} 个
                            </span>
                        </div>
                        <div v-if="aiPendingItems.length === 0" class="text-caption text--secondary pa-3 rounded" style="border: 1px dashed var(--v-gray2-base);">
                            暂无已选词。请先在阅读页点词并加入「待 AI 解释」。
                        </div>
                        <div v-else>
                            <div class="d-flex align-center mb-2">
                                <v-btn x-small text color="primary" @click="selectAllPreviewItems">全选</v-btn>
                                <v-btn x-small text color="secondary" @click="deselectAllPreviewItems">全不选</v-btn>
                            </div>
                            <v-list dense class="rounded" style="border: 1px solid var(--v-gray2-base);">
                                <v-list-item v-for="item in aiPendingItems" :key="item.id" class="px-2">
                                    <v-list-item-action class="mr-2">
                                        <v-checkbox
                                            :input-value="aiPreviewSelectedItemIds.includes(item.id)"
                                            @change="togglePreviewItemSelection(item.id)"
                                            hide-details
                                            dense
                                        />
                                    </v-list-item-action>
                                    <v-list-item-content>
                                        <v-list-item-title class="d-flex align-center">
                                            <span class="font-weight-medium default-font">{{ item.word }}</span>
                                            <span v-if="item.lemma && item.lemma !== item.word" class="text-caption text--secondary ml-2">({{ item.lemma }})</span>
                                            <v-chip x-small class="ml-2" color="primary" text-color="white">待解释</v-chip>
                                        </v-list-item-title>
                                        <v-list-item-subtitle v-if="item.sentence_text" class="text-caption text--secondary mt-1" style="white-space: normal; line-height: 1.4;">
                                            来源句子：{{ item.sentence_text }}
                                        </v-list-item-subtitle>
                                        <v-list-item-subtitle class="text-caption text--secondary mt-1">
                                            章节 #{{ item.chapter_id }} | 文本块 #{{ item.text_block_index }}<span v-if="item.sentence_index !== null && item.sentence_index !== undefined"> | 句子 #{{ item.sentence_index }}</span>
                                        </v-list-item-subtitle>
                                    </v-list-item-content>
                                </v-list-item>
                            </v-list>
                        </div>
                    </div>

                    <!-- AI 推荐词区域（V4: 粘贴导入 + 解析 + 去重 + 默认不选 + 勾选） -->
                    <div class="mt-5">
                        <div class="d-flex align-center mb-2">
                            <v-icon x-small class="mr-1">mdi-robot</v-icon>
                            <span class="text-subtitle-1 font-weight-medium">AI 推荐词</span>
                            <v-spacer />
                            <span class="text-caption text--secondary">
                                共 {{ aiRecommendations.length }} 条，已勾选 {{ aiSelectedRecommendationIndices.length }} 条
                            </span>
                        </div>

                        <!-- V4: 粘贴 AI 推荐词 JSON -->
                        <div class="pa-3 rounded" style="border: 1px dashed var(--v-gray2-base);">
                            <div class="text-caption font-weight-medium mb-2">粘贴 AI 返回的推荐词 JSON：</div>
                            <v-textarea
                                v-model="aiRecommendationJsonInput"
                                outlined
                                dense
                                rows="4"
                                placeholder='{"schema_version":"ai-study-card-recommendations-v1","recommended_items":[{"word":"agency","lemma":"agency","surface":"agency","reason":"...","sentence_text":"...","confidence":0.86}]}'
                                class="text-caption"
                                hide-details
                            />
                            <div class="d-flex mt-2">
                                <v-btn x-small color="primary" depressed @click="parseAiRecommendations" :loading="false">
                                    <v-icon x-small class="mr-1">mdi-refresh</v-icon>
                                    解析推荐词
                                </v-btn>
                                <v-btn x-small text color="secondary" class="ml-2" @click="clearAiRecommendations">
                                    <v-icon x-small class="mr-1">mdi-eraser</v-icon>
                                    清空推荐词
                                </v-btn>
                            </div>
                            <div class="text-caption text--secondary mt-2">
                                规则：AI 推荐词默认不选；不会与你已选的词重复；需手动勾选才会进入最终候选包。
                            </div>
                        </div>

                        <!-- V4: 解析错误提示 -->
                        <v-alert
                            v-if="aiRecommendationParseError"
                            dense
                            text
                            type="error"
                            class="mt-2 mb-0"
                        >{{ aiRecommendationParseError }}</v-alert>

                        <!-- V4: 解析摘要 -->
                        <div v-if="aiRecommendationSummary" class="mt-2 pa-2 rounded text-caption" style="background: var(--v-gray1-base);">
                            <div class="font-weight-medium mb-1">解析摘要：</div>
                            <div>原始推荐数量：{{ aiRecommendationSummary.original_count }}</div>
                            <div>有效推荐数量：{{ aiRecommendationSummary.valid_count }}</div>
                            <div>缺少 word 被丢弃：{{ aiRecommendationSummary.dropped_missing_word }}</div>
                            <div>与用户已选词重复被丢弃：{{ aiRecommendationSummary.dropped_duplicate_with_user }}</div>
                            <div>AI 推荐词内部重复被丢弃：{{ aiRecommendationSummary.dropped_ai_internal_duplicate }}</div>
                        </div>

                        <!-- V4: AI 推荐词列表（默认不选，每项 checkbox，reason/confidence/sentence_text 可见） -->
                        <div v-if="aiRecommendations.length > 0" class="mt-2">
                            <div class="d-flex align-center mb-2">
                                <v-btn x-small text color="primary" @click="selectAllAiRecommendations">全选推荐词</v-btn>
                                <v-btn x-small text color="secondary" class="ml-2" @click="deselectAllAiRecommendations">全不选推荐词</v-btn>
                            </div>
                            <v-list dense class="rounded" style="border: 1px solid var(--v-gray2-base);">
                                <v-list-item v-for="(rec, idx) in aiRecommendations" :key="'ai-rec-' + idx" class="px-2">
                                    <v-list-item-action class="mr-2">
                                        <v-checkbox
                                            :input-value="aiSelectedRecommendationIndices.includes(idx)"
                                            @change="toggleAiRecommendationSelection(idx)"
                                            hide-details
                                            dense
                                        />
                                    </v-list-item-action>
                                    <v-list-item-content>
                                        <v-list-item-title class="d-flex align-center">
                                            <span class="font-weight-medium default-font">{{ rec.word }}</span>
                                            <span v-if="rec.lemma && rec.lemma !== rec.word" class="text-caption text--secondary ml-2">({{ rec.lemma }})</span>
                                            <v-chip x-small class="ml-2" color="purple" text-color="white">AI 推荐</v-chip>
                                            <span v-if="rec.confidence !== null && rec.confidence !== undefined" class="text-caption text--secondary ml-2">
                                                置信度 {{ Math.round(rec.confidence * 100) }}%
                                            </span>
                                        </v-list-item-title>
                                        <v-list-item-subtitle v-if="rec.reason" class="text-caption text--secondary mt-1" style="white-space: normal; line-height: 1.4;">
                                            原因：{{ rec.reason }}
                                        </v-list-item-subtitle>
                                        <v-list-item-subtitle v-if="rec.sentence_text" class="text-caption text--secondary mt-1" style="white-space: normal; line-height: 1.4;">
                                            来源句子：{{ rec.sentence_text }}
                                        </v-list-item-subtitle>
                                    </v-list-item-content>
                                </v-list-item>
                            </v-list>
                        </div>
                    </div>

                    <!-- 规则说明 -->
                    <div class="mt-5 pa-3 rounded" style="background: var(--v-gray1-base);">
                        <div class="text-caption font-weight-medium mb-1">生成规则说明：</div>
                        <ul class="text-caption text--secondary" style="line-height: 1.6;">
                            <li>你已选的词会自动进入最终候选包。</li>
                            <li>AI 推荐词默认不选，需手动勾选。</li>
                            <li>AI 推荐词不会与你已选的词重复。</li>
                            <li>只有你确认后，才会生成最终候选包。</li>
                            <li>最终候选包不会生成复习卡，也不会调用 AI。</li>
                            <li>下一阶段才会基于最终候选包生成 WordSense / ReviewCard（需用户再次确认）。</li>
                        </ul>
                    </div>

                    <!-- V3: 安全生成包展示区域 -->
                    <div v-if="aiPreviewPackage" class="mt-5">
                        <div class="d-flex align-center mb-2">
                            <v-icon x-small class="mr-1">mdi-package-variant-closed</v-icon>
                            <span class="text-subtitle-1 font-weight-medium">安全生成包</span>
                            <v-spacer />
                            <v-btn
                                x-small
                                rounded
                                depressed
                                color="primary"
                                @click="copyPreviewPackage"
                            >
                                <v-icon x-small class="mr-1">mdi-content-copy</v-icon>
                                复制生成包
                            </v-btn>
                        </div>
                        <v-alert
                            v-if="aiPreviewCopyMessage"
                            dense
                            text
                            :type="aiPreviewCopied ? 'success' : 'error'"
                            class="mb-2"
                        >{{ aiPreviewCopyMessage }}</v-alert>
                        <v-alert
                            dense
                            text
                            type="warning"
                            class="mb-2"
                        >
                            这只是生成包，不是 AI 输出，不会生成复习卡。
                        </v-alert>
                        <pre class="pa-3 rounded text-caption" style="background: var(--v-gray1-base); max-height: 240px; overflow: auto; white-space: pre-wrap; word-break: break-all;">{{ JSON.stringify(aiPreviewPackage, null, 2) }}</pre>
                    </div>

                    <!-- V3: 生成包错误提示 -->
                    <v-alert
                        v-if="aiPreviewPackageError"
                        dense
                        text
                        type="error"
                        class="mt-3"
                    >{{ aiPreviewPackageError }}</v-alert>

                    <!-- V4: 最终候选包展示区域 -->
                    <div v-if="aiFinalCandidatesPackage" class="mt-5">
                        <div class="d-flex align-center mb-2">
                            <v-icon x-small class="mr-1">mdi-check-decagram</v-icon>
                            <span class="text-subtitle-1 font-weight-medium">最终候选包</span>
                            <v-spacer />
                            <v-btn
                                x-small
                                rounded
                                depressed
                                color="primary"
                                @click="copyFinalCandidatesPackage"
                            >
                                <v-icon x-small class="mr-1">mdi-content-copy</v-icon>
                                复制最终候选包
                            </v-btn>
                        </div>
                        <v-alert
                            v-if="aiFinalCopyMessage"
                            dense
                            text
                            :type="aiFinalCopied ? 'success' : 'error'"
                            class="mb-2"
                        >{{ aiFinalCopyMessage }}</v-alert>
                        <v-alert
                            dense
                            text
                            type="warning"
                            class="mb-2"
                        >
                            这只是最终候选包，不是 AI 输出，不会生成复习卡。下一阶段需你再次确认才会生成 WordSense / ReviewCard。
                        </v-alert>
                        <pre class="pa-3 rounded text-caption" style="background: var(--v-gray1-base); max-height: 280px; overflow: auto; white-space: pre-wrap; word-break: break-all;">{{ JSON.stringify(aiFinalCandidatesPackage, null, 2) }}</pre>
                    </div>

                    <!-- V4: 最终候选包错误提示 -->
                    <v-alert
                        v-if="aiFinalCandidatesError"
                        dense
                        text
                        type="error"
                        class="mt-3"
                    >{{ aiFinalCandidatesError }}</v-alert>

                    <!-- V5: 结果展示由共享组件 AiStudyCardGenerateCardsResult 负责 -->
                    <AiStudyCardGenerateCardsResult
                        :result="aiGenerateCardsResult"
                        @go-to-sense-reviews="goToSenseReviews"
                        @dismiss="aiGenerateCardsResult = null"
                    />
                </v-card-text>
                <v-card-actions class="d-flex pa-3">
                    <v-btn text @click="aiStudyCardPreviewDialog = false">关闭</v-btn>
                    <v-spacer />
                    <v-btn
                        color="primary"
                        :disabled="aiPreviewSelectedItemIds.length === 0 || aiPreviewPackageLoading"
                        :loading="aiPreviewPackageLoading"
                        @click="generatePreviewPackage"
                    >
                        <v-icon small class="mr-1">mdi-package-variant</v-icon>
                        准备生成
                    </v-btn>
                    <v-btn
                        color="success"
                        :disabled="(aiPreviewSelectedItemIds.length === 0 && aiSelectedRecommendationIndices.length === 0) || aiFinalCandidatesLoading || !aiPreviewPackage"
                        :loading="aiFinalCandidatesLoading"
                        @click="generateFinalCandidatesPackage"
                        class="ml-2"
                    >
                        <v-icon small class="mr-1">mdi-check-decagram</v-icon>
                        生成最终候选包
                    </v-btn>
                    <!-- V5: 生成学习卡按钮（窄屏 parity） -->
                    <v-btn
                        color="error"
                        :disabled="!aiFinalCandidatesPackage || aiGenerateCardsLoading"
                        :loading="aiGenerateCardsLoading"
                        @click="openGenerateCardsDialog"
                        class="ml-2"
                    >
                        <v-icon small class="mr-1">mdi-cards-outline</v-icon>
                        生成学习卡
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- V5: 确认生成学习卡对话框（共享组件 AiStudyCardGenerateCardsDialog） -->
        <AiStudyCardGenerateCardsDialog
            v-model="aiGenerateCardsDialog"
            :items="aiGenerateCardsItems"
            :loading="aiGenerateCardsLoading"
            :error="aiGenerateCardsError"
            @confirm="confirmGenerateCards"
        />
    </v-card>
</template>

<script>
    import { mapState } from 'vuex';
    import WordSensesList from './WordSensesList.vue';
    import AiStudyCardGenerateCardsDialog from './AiStudyCardGenerateCardsDialog.vue';
    import AiStudyCardGenerateCardsResult from './AiStudyCardGenerateCardsResult.vue';
    import {
        buildAiSuggestionLookupContext,
        buildAiSuggestionLookupKey,
        fetchAiSuggestions,
        buildAiVocabSensePayload,
        buildAiPhraseSensePayload,
    } from './../../services/VocabularyAiSuggestionService';
    import {
        buildGenerateCardItems,
        filterConfirmedGenerateCardItems,
        generateAiStudyCards,
    } from './../../services/AiStudyCardGenerateCardsService';

    export default {
        components: {
            WordSensesList,
            AiStudyCardGenerateCardsDialog,
            AiStudyCardGenerateCardsResult,
        },
        props: {
            autoHighlightWords: Boolean,
            language: String,
            anyApiDictionaryEnabled: Boolean,
            textToSpeechAvailable: Boolean,
        },
        computed: mapState({
            active: state => state.vocabularyBox.active,
            type: state => state.vocabularyBox.type,
            word: state => state.vocabularyBox.word,
            phrase: state => state.vocabularyBox.phrase,
            stage: state => state.vocabularyBox.stage,
            inflections: state => state.vocabularyBox.inflections,
            _reading: state => state.vocabularyBox.reading,
            _baseWord: state => state.vocabularyBox.baseWord,
            _studyBase: state => state.vocabularyBox.studyBase,
            _baseWordReading: state => state.vocabularyBox.baseWordReading,
            _phraseReading: state => state.vocabularyBox.phraseReading,
            _translationText: state => state.vocabularyBox.translationText,
            _searchField: state => state.vocabularyBox.searchField,
            positionLeft: state => state.vocabularyBox.positionLeft,
            positionTop: state => state.vocabularyBox.positionTop,
            width: state => state.vocabularyBox.width,
            height: state => state.vocabularyBox.height,
            _chapterId: state => state.vocabularyBox.chapterId,
            _sentenceIndex: state => state.vocabularyBox.sentenceIndex,
            _sentenceText: state => state.vocabularyBox.sentenceText,
            aiVocabSuggestions: state => state.vocabularyBox.aiVocabSuggestions,
            aiPhraseSuggestions: state => state.vocabularyBox.aiPhraseSuggestions,
            aiLookupLoading: state => state.vocabularyBox.aiLookupLoading,
            aiLookupError: state => state.vocabularyBox.aiLookupError,
        }),
        data: function() {
            return {
                // data for word
                reading: '',
                baseWord: '',
                studyBase: '',
                baseWordReading: '',
                phraseReading: '',

                // data for both
                translationText: '',
                translationList: [],

                // ui data
                tab: 0,
                latestAiLookupKey: '',
                searchField: '',
                searchResults: [],
                aiStudyCardPendingLoading: false,
                aiStudyCardPendingMessage: '',
                aiStudyCardPendingError: false,
                // V2: 待解释列表面板状态
                aiPendingListDialog: false,
                aiPendingListLoading: false,
                aiPendingListMessage: '',
                aiPendingListError: '',
                aiPendingItems: [],
                aiPendingDismissLoadingId: null,
                // V2: 生成预览弹窗状态
                aiStudyCardPreviewDialog: false,
                // V3: 已取消视图 + 恢复按钮
                aiPendingListStatusFilter: 'pending',
                aiPendingDismissedItems: [],
                aiPendingRestoreLoadingId: null,
                // V3: 真预览内容 + 勾选 + 安全生成包
                aiPreviewSelectedItemIds: [],
                aiPreviewPackage: null,
                aiPreviewPackageLoading: false,
                aiPreviewPackageError: '',
                aiPreviewCopyMessage: '',
                aiPreviewCopied: false,
                // V4: AI 推荐词粘贴导入 + 去重 + 默认不选 + 勾选 + 最终候选包
                aiRecommendationJsonInput: '',
                aiRecommendations: [],
                aiSelectedRecommendationIndices: [],
                aiRecommendationParseError: '',
                aiRecommendationSummary: null,
                aiFinalCandidatesPackage: null,
                aiFinalCandidatesLoading: false,
                aiFinalCandidatesError: '',
                aiFinalCopyMessage: '',
                aiFinalCopied: false,
                // V5: 生成学习卡确认对话框 + 结果展示
                aiGenerateCardsDialog: false,
                aiGenerateCardsItems: [],
                aiGenerateCardsLoading: false,
                aiGenerateCardsError: '',
                aiGenerateCardsResult: null,
            };
        },
        watch: {
            word() {
                this.updateDataFromStore();
                this.loadAiSuggestions();
                this.resetAiStudyCardPendingFeedback();
            },
            phrase() {
                this.updateDataFromStore();
            },
            // Re-trigger AI lookup when sentence changes (new word in same sentence or different sentence)
            '_sentenceIndex'() {
                if (this.$store.state.vocabularyBox.active && this.word) {
                    this.loadAiSuggestions();
                }
            },
        },
        mounted: function() {
            this.updateDataFromStore();
            this.loadAiSuggestions();
        },
        methods: {
            updateDataFromStore() {
                this.translationText = this._translationText;
                this.reading = this._reading;
                this.baseWord = this._baseWord;
                this.studyBase = this._studyBase;
                this.baseWordReading = this._baseWordReading;
                this.phraseReading = this._phraseReading;
                this.searchField = this._searchField;
            },
            textToSpeech() {
                this.$emit('textToSpeech');
            },
            searchFieldChanged(event) {
                if (event === '') {
                    return;
                }
                
                this.searchField = event;
            },
            setStage(stage) {
                this.$emit('setStage', stage);
            },
            openKanji(kanji) {
                window.location.href = '/kanji/' + kanji;
            },
            addNewPhrase() {
                this.$emit('addNewPhrase');
            },
            deletePhrase() {
                this.$emit('deletePhrase');
            },
            deleteWord() {
                this.$emit('deleteWord');
            },
            resetAiStudyCardPendingFeedback() {
                this.aiStudyCardPendingLoading = false;
                this.aiStudyCardPendingMessage = '';
                this.aiStudyCardPendingError = false;
            },
            markAiStudyCardPending() {
                if (this.type !== 'word') {
                    return;
                }

                const chapterId = this.$store.state.vocabularyBox.chapterId;
                const sentenceIndex = this.$store.state.vocabularyBox.sentenceIndex;
                if (!chapterId || sentenceIndex === null || sentenceIndex === undefined || !this.word) {
                    this.aiStudyCardPendingError = true;
                    this.aiStudyCardPendingMessage = '缺少章节或句子位置，暂时无法加入待 AI 解释。';
                    return;
                }

                this.aiStudyCardPendingLoading = true;
                this.aiStudyCardPendingError = false;
                this.aiStudyCardPendingMessage = '';

                axios.post('/ai-study-card/pending-items', {
                    chapter_id: chapterId,
                    text_block_index: sentenceIndex,
                    sentence_index: sentenceIndex,
                    sentence_id: String(sentenceIndex),
                    word: this.word,
                    surface: this.word,
                    lemma: this.baseWord || this.word,
                    sentence_text: this.$store.state.vocabularyBox.sentenceText || '',
                    source_payload: {
                        source: 'reader_vocabulary_box',
                    },
                }).then((response) => {
                    this.aiStudyCardPendingMessage = response.data && response.data.message
                        ? response.data.message
                        : '已加入待 AI 解释。';
                }).catch((error) => {
                    this.aiStudyCardPendingError = true;
                    this.aiStudyCardPendingMessage = error.response && error.response.data && error.response.data.message
                        ? error.response.data.message
                        : '加入待 AI 解释失败。';
                }).finally(() => {
                    this.aiStudyCardPendingLoading = false;
                });
            },
            // V3: 打开待解释列表面板，并加载 pending + dismissed 数据
            openAiPendingListDialog() {
                this.aiPendingListDialog = true;
                this.aiPendingListMessage = '';
                this.aiPendingListError = '';
                this.aiPendingListStatusFilter = 'pending';
                this.loadAiPendingItems();
                this.loadAiPendingDismissedItems();
            },
            // V3: 加载当前用户（可按当前章节过滤）的待解释项
            loadAiPendingItems() {
                this.aiPendingListLoading = true;
                this.aiPendingListError = '';
                const chapterId = this.$store.state.vocabularyBox.chapterId;
                const params = { status: 'pending' };
                if (chapterId) {
                    params.chapter_id = chapterId;
                }
                axios.get('/ai-study-card/pending-items', { params })
                    .then((response) => {
                        const items = response.data && response.data.items ? response.data.items : [];
                        this.aiPendingItems = items;
                    })
                    .catch((error) => {
                        this.aiPendingListError = error.response && error.response.data && error.response.data.message
                            ? error.response.data.message
                            : '加载待解释列表失败。';
                        this.aiPendingItems = [];
                    })
                    .finally(() => {
                        this.aiPendingListLoading = false;
                    });
            },
            // V3: 加载已取消的待解释项
            loadAiPendingDismissedItems() {
                const chapterId = this.$store.state.vocabularyBox.chapterId;
                const params = { status: 'dismissed' };
                if (chapterId) {
                    params.chapter_id = chapterId;
                }
                axios.get('/ai-study-card/pending-items', { params })
                    .then((response) => {
                        const items = response.data && response.data.items ? response.data.items : [];
                        this.aiPendingDismissedItems = items;
                    })
                    .catch(() => {
                        this.aiPendingDismissedItems = [];
                    });
            },
            // V3: 取消（dismiss）一个待解释项
            dismissAiPendingItem(itemId) {
                this.aiPendingDismissLoadingId = itemId;
                this.aiPendingListMessage = '';
                this.aiPendingListError = '';
                axios.post(`/ai-study-card/pending-items/${itemId}/dismiss`)
                    .then((response) => {
                        this.aiPendingListMessage = response.data && response.data.message
                            ? response.data.message
                            : '已取消。';
                        const dismissed = this.aiPendingItems.find(i => i.id === itemId);
                        this.aiPendingItems = this.aiPendingItems.filter(i => i.id !== itemId);
                        if (dismissed) {
                            this.aiPendingDismissedItems.unshift({ ...dismissed, status: 'dismissed' });
                        }
                    })
                    .catch((error) => {
                        this.aiPendingListError = error.response && error.response.data && error.response.data.message
                            ? error.response.data.message
                            : '取消失败。';
                    })
                    .finally(() => {
                        this.aiPendingDismissLoadingId = null;
                    });
            },
            // V3: 恢复（restore）一个已取消的待解释项
            restoreAiPendingItem(itemId) {
                this.aiPendingRestoreLoadingId = itemId;
                this.aiPendingListMessage = '';
                this.aiPendingListError = '';
                axios.post(`/ai-study-card/pending-items/${itemId}/restore`)
                    .then((response) => {
                        this.aiPendingListMessage = response.data && response.data.message
                            ? response.data.message
                            : '已恢复。';
                        const restored = this.aiPendingDismissedItems.find(i => i.id === itemId);
                        this.aiPendingDismissedItems = this.aiPendingDismissedItems.filter(i => i.id !== itemId);
                        if (restored) {
                            this.aiPendingItems.unshift({ ...restored, status: 'pending' });
                        }
                    })
                    .catch((error) => {
                        this.aiPendingListError = error.response && error.response.data && error.response.data.message
                            ? error.response.data.message
                            : '恢复失败。';
                    })
                    .finally(() => {
                        this.aiPendingRestoreLoadingId = null;
                    });
            },
            // V3: 打开生成预览弹窗，初始化勾选状态
            openAiStudyCardPreview() {
                this.aiStudyCardPreviewDialog = true;
                // 默认全部勾选
                this.aiPreviewSelectedItemIds = this.aiPendingItems.map(i => i.id);
                this.aiPreviewPackage = null;
                this.aiPreviewPackageError = '';
                this.aiPreviewCopyMessage = '';
                this.aiPreviewCopied = false;
                // V4: 清空 AI 推荐词相关状态
                this.aiRecommendationJsonInput = '';
                this.aiRecommendations = [];
                this.aiSelectedRecommendationIndices = [];
                this.aiRecommendationParseError = '';
                this.aiRecommendationSummary = null;
                this.aiFinalCandidatesPackage = null;
                this.aiFinalCandidatesError = '';
                this.aiFinalCopyMessage = '';
                this.aiFinalCopied = false;
            },
            // V3: 切换某个词的勾选状态
            togglePreviewItemSelection(itemId) {
                const idx = this.aiPreviewSelectedItemIds.indexOf(itemId);
                if (idx >= 0) {
                    this.aiPreviewSelectedItemIds.splice(idx, 1);
                } else {
                    this.aiPreviewSelectedItemIds.push(itemId);
                }
                // 清空已生成的包（因为选择变了）
                this.aiPreviewPackage = null;
                this.aiPreviewCopyMessage = '';
                this.aiPreviewCopied = false;
                // V4: 用户已选词变化时，重新对 AI 推荐词去重并清空最终候选包
                this.rededupeAiRecommendationsAfterUserSelectionChange();
                this.aiFinalCandidatesPackage = null;
                this.aiFinalCopyMessage = '';
                this.aiFinalCopied = false;
            },
            // V3: 全选
            selectAllPreviewItems() {
                this.aiPreviewSelectedItemIds = this.aiPendingItems.map(i => i.id);
                this.aiPreviewPackage = null;
                this.aiPreviewCopyMessage = '';
                this.aiPreviewCopied = false;
                // V4: 重新对 AI 推荐词去重
                this.rededupeAiRecommendationsAfterUserSelectionChange();
                this.aiFinalCandidatesPackage = null;
                this.aiFinalCopyMessage = '';
                this.aiFinalCopied = false;
            },
            // V3: 全不选
            deselectAllPreviewItems() {
                this.aiPreviewSelectedItemIds = [];
                this.aiPreviewPackage = null;
                this.aiPreviewCopyMessage = '';
                this.aiPreviewCopied = false;
                // V4: 重新对 AI 推荐词去重
                this.rededupeAiRecommendationsAfterUserSelectionChange();
                this.aiFinalCandidatesPackage = null;
                this.aiFinalCopyMessage = '';
                this.aiFinalCopied = false;
            },
            // V4: 解析 AI 推荐词 JSON
            parseAiRecommendations() {
                this.aiRecommendationParseError = '';
                this.aiRecommendationSummary = null;
                this.aiRecommendations = [];
                this.aiSelectedRecommendationIndices = [];

                const text = (this.aiRecommendationJsonInput || '').trim();
                if (!text) {
                    this.aiRecommendationParseError = '请粘贴 AI 推荐词 JSON。';
                    return;
                }

                let parsed;
                try {
                    parsed = JSON.parse(text);
                } catch (e) {
                    this.aiRecommendationParseError = 'JSON 格式错误：' + (e.message || '无法解析。');
                    return;
                }

                if (!parsed || typeof parsed !== 'object') {
                    this.aiRecommendationParseError = 'JSON 根对象必须是对象。';
                    return;
                }

                const schemaVersion = parsed.schema_version || 'unknown';
                if (schemaVersion !== 'ai-study-card-recommendations-v1') {
                    // 不强制要求 schema_version，但给出提示
                    // 仅警告，不阻止解析
                }

                const items = parsed.recommended_items;
                if (!Array.isArray(items)) {
                    this.aiRecommendationParseError = 'recommended_items 必须是数组。';
                    return;
                }

                const userSelectedKeys = {};
                const selectedItems = this.aiPendingItems.filter(i => this.aiPreviewSelectedItemIds.includes(i.id));
                selectedItems.forEach(item => {
                    const key = (item.lemma || item.word || '').trim().toLowerCase();
                    if (key) userSelectedKeys[key] = true;
                });

                const validRecommendations = [];
                const seenKeys = {};
                let droppedMissingWord = 0;
                let droppedDuplicateWithUser = 0;
                let droppedAiInternalDuplicate = 0;

                items.forEach((raw) => {
                    if (!raw || typeof raw !== 'object') {
                        droppedMissingWord++;
                        return;
                    }
                    const word = (raw.word || '').toString().trim();
                    if (!word) {
                        droppedMissingWord++;
                        return;
                    }
                    const lemma = (raw.lemma || '').toString().trim() || word;
                    const key = lemma.toLowerCase();
                    if (userSelectedKeys[key]) {
                        droppedDuplicateWithUser++;
                        return;
                    }
                    if (seenKeys[key]) {
                        droppedAiInternalDuplicate++;
                        return;
                    }
                    seenKeys[key] = true;
                    validRecommendations.push({
                        word: word,
                        lemma: lemma,
                        surface: (raw.surface || '').toString().trim() || word,
                        reason: (raw.reason || '').toString().trim() || '无说明',
                        sentence_text: raw.sentence_text ? (raw.sentence_text).toString().trim() : '',
                        confidence: raw.confidence !== undefined && raw.confidence !== null ? raw.confidence : null,
                    });
                });

                this.aiRecommendations = validRecommendations;
                // 默认全部不选
                this.aiSelectedRecommendationIndices = [];
                this.aiRecommendationSummary = {
                    original_count: items.length,
                    valid_count: validRecommendations.length,
                    dropped_missing_word: droppedMissingWord,
                    dropped_duplicate_with_user: droppedDuplicateWithUser,
                    dropped_ai_internal_duplicate: droppedAiInternalDuplicate,
                };

                if (validRecommendations.length === 0) {
                    this.aiRecommendationParseError = '没有有效的 AI 推荐词。';
                }
            },
            // V4: 清空 AI 推荐词
            clearAiRecommendations() {
                this.aiRecommendationJsonInput = '';
                this.aiRecommendations = [];
                this.aiSelectedRecommendationIndices = [];
                this.aiRecommendationParseError = '';
                this.aiRecommendationSummary = null;
                this.aiFinalCandidatesPackage = null;
                this.aiFinalCandidatesError = '';
                this.aiFinalCopyMessage = '';
                this.aiFinalCopied = false;
            },
            // V4: 用户已选词变化后，重新对 AI 推荐词去重
            // 如果之前已解析出推荐词，重新过滤一遍，把和新选词重复的移除
            rededupeAiRecommendationsAfterUserSelectionChange() {
                if (this.aiRecommendations.length === 0) {
                    return;
                }
                const userSelectedKeys = {};
                const selectedItems = this.aiPendingItems.filter(i => this.aiPreviewSelectedItemIds.includes(i.id));
                selectedItems.forEach(item => {
                    const key = (item.lemma || item.word || '').trim().toLowerCase();
                    if (key) userSelectedKeys[key] = true;
                });

                const kept = [];
                const keptIndices = [];
                let dropped = 0;
                this.aiRecommendations.forEach((rec, idx) => {
                    const key = (rec.lemma || rec.word || '').trim().toLowerCase();
                    if (userSelectedKeys[key]) {
                        dropped++;
                        return;
                    }
                    kept.push(rec);
                    if (this.aiSelectedRecommendationIndices.includes(idx)) {
                        keptIndices.push(kept.length - 1);
                    }
                });

                this.aiRecommendations = kept;
                this.aiSelectedRecommendationIndices = keptIndices;
                if (this.aiRecommendationSummary) {
                    this.aiRecommendationSummary.valid_count = kept.length;
                    this.aiRecommendationSummary.dropped_duplicate_with_user += dropped;
                }
            },
            // V4: 切换 AI 推荐词勾选
            toggleAiRecommendationSelection(idx) {
                const i = this.aiSelectedRecommendationIndices.indexOf(idx);
                if (i >= 0) {
                    this.aiSelectedRecommendationIndices.splice(i, 1);
                } else {
                    this.aiSelectedRecommendationIndices.push(idx);
                }
                this.aiFinalCandidatesPackage = null;
                this.aiFinalCopyMessage = '';
                this.aiFinalCopied = false;
            },
            // V4: 全选 AI 推荐词
            selectAllAiRecommendations() {
                this.aiSelectedRecommendationIndices = this.aiRecommendations.map((_, idx) => idx);
                this.aiFinalCandidatesPackage = null;
                this.aiFinalCopyMessage = '';
                this.aiFinalCopied = false;
            },
            // V4: 全不选 AI 推荐词
            deselectAllAiRecommendations() {
                this.aiSelectedRecommendationIndices = [];
                this.aiFinalCandidatesPackage = null;
                this.aiFinalCopyMessage = '';
                this.aiFinalCopied = false;
            },
            // V4: 生成最终候选包
            generateFinalCandidatesPackage() {
                if (this.aiPreviewSelectedItemIds.length === 0 && this.aiSelectedRecommendationIndices.length === 0) {
                    return;
                }
                if (!this.aiPreviewPackage) {
                    this.aiFinalCandidatesError = '请先点击「准备生成」生成安全预览包。';
                    return;
                }
                this.aiFinalCandidatesLoading = true;
                this.aiFinalCandidatesError = '';
                this.aiFinalCandidatesPackage = null;
                this.aiFinalCopyMessage = '';
                this.aiFinalCopied = false;

                const selectedAi = this.aiSelectedRecommendationIndices.map(idx => this.aiRecommendations[idx]).filter(Boolean);
                const unselectedAi = this.aiRecommendations
                    .map((rec, idx) => ({ rec, idx }))
                    .filter(({ idx }) => !this.aiSelectedRecommendationIndices.includes(idx))
                    .map(({ rec }) => rec);

                axios.post('/ai-study-card/pending-items/final-candidates-package', {
                    selected_item_ids: this.aiPreviewSelectedItemIds,
                    selected_ai_recommendations: selectedAi,
                    unselected_ai_recommendations: unselectedAi,
                    dedupe_summary: this.aiRecommendationSummary || {
                        original_ai_count: 0,
                        valid_ai_count: 0,
                        dropped_missing_word: 0,
                        dropped_duplicate_with_user: 0,
                        dropped_ai_internal_duplicate: 0,
                    },
                    source_preview_package: this.aiPreviewPackage,
                }).then((response) => {
                    if (response.data && response.data.success) {
                        this.aiFinalCandidatesPackage = response.data.package;
                    } else {
                        this.aiFinalCandidatesError = response.data && response.data.message
                            ? response.data.message
                            : '生成最终候选包失败。';
                    }
                }).catch((error) => {
                    this.aiFinalCandidatesError = error.response && error.response.data && error.response.data.message
                        ? error.response.data.message
                        : '生成最终候选包失败。';
                }).finally(() => {
                    this.aiFinalCandidatesLoading = false;
                });
            },
            // V5: 打开"生成学习卡"确认对话框
            // 候选项构造逻辑由共享 helper buildGenerateCardItems 提供，与
            // VocabularySideBox.vue 行为一致（窄屏 parity）。
            openGenerateCardsDialog() {
                if (!this.aiFinalCandidatesPackage) {
                    return;
                }

                this.aiGenerateCardsItems = buildGenerateCardItems(this.aiFinalCandidatesPackage);
                this.aiGenerateCardsResult = null;
                this.aiGenerateCardsError = '';
                this.aiGenerateCardsDialog = true;
            },
            // V5: 确认生成学习卡
            // 前端预过滤空释义项，后端也会严格校验。
            // 请求逻辑由共享 helper generateAiStudyCards 提供。
            confirmGenerateCards() {
                if (this.aiGenerateCardsItems.length === 0) {
                    return;
                }

                this.aiGenerateCardsLoading = true;
                this.aiGenerateCardsError = '';

                // 前端预过滤：只发送 sense_zh 非空的项
                const confirmedItems = filterConfirmedGenerateCardItems(this.aiGenerateCardsItems);

                if (confirmedItems.length === 0) {
                    this.aiGenerateCardsError = '请至少为一个候选项填写中文释义。';
                    this.aiGenerateCardsLoading = false;
                    return;
                }

                generateAiStudyCards(axios, this.aiFinalCandidatesPackage, confirmedItems)
                    .then((data) => {
                        this.aiGenerateCardsResult = data;
                        this.aiGenerateCardsDialog = false;
                    })
                    .catch((error) => {
                        this.aiGenerateCardsError = error.message || '生成学习卡失败。';
                    })
                    .finally(() => {
                        this.aiGenerateCardsLoading = false;
                    });
            },
            // V5: 跳转到 /reviews/senses 复习主线
            goToSenseReviews() {
                window.location.href = '/reviews/senses';
            },
            // V4: 复制最终候选包
            copyFinalCandidatesPackage() {
                if (!this.aiFinalCandidatesPackage) {
                    return;
                }
                const text = JSON.stringify(this.aiFinalCandidatesPackage, null, 2);
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(text).then(() => {
                        this.aiFinalCopied = true;
                        this.aiFinalCopyMessage = '已复制到剪贴板。';
                    }).catch(() => {
                        this.aiFinalCopied = false;
                        this.aiFinalCopyMessage = '复制失败，请手动选择文本复制。';
                    });
                } else {
                    try {
                        const textarea = document.createElement('textarea');
                        textarea.value = text;
                        textarea.style.position = 'fixed';
                        textarea.style.opacity = '0';
                        document.body.appendChild(textarea);
                        textarea.select();
                        document.execCommand('copy');
                        document.body.removeChild(textarea);
                        this.aiFinalCopied = true;
                        this.aiFinalCopyMessage = '已复制到剪贴板。';
                    } catch (e) {
                        this.aiFinalCopied = false;
                        this.aiFinalCopyMessage = '复制失败，请手动选择文本复制。';
                    }
                }
            },
            // V3: 生成安全预览包
            generatePreviewPackage() {
                if (this.aiPreviewSelectedItemIds.length === 0) {
                    return;
                }
                this.aiPreviewPackageLoading = true;
                this.aiPreviewPackageError = '';
                this.aiPreviewPackage = null;
                this.aiPreviewCopyMessage = '';
                this.aiPreviewCopied = false;
                axios.post('/ai-study-card/pending-items/preview-package', {
                    item_ids: this.aiPreviewSelectedItemIds,
                }).then((response) => {
                    if (response.data && response.data.success) {
                        this.aiPreviewPackage = response.data.package;
                    } else {
                        this.aiPreviewPackageError = response.data && response.data.message
                            ? response.data.message
                            : '生成安全包失败。';
                    }
                }).catch((error) => {
                    this.aiPreviewPackageError = error.response && error.response.data && error.response.data.message
                        ? error.response.data.message
                        : '生成安全包失败。';
                }).finally(() => {
                    this.aiPreviewPackageLoading = false;
                });
            },
            // V3: 复制安全生成包到剪贴板
            copyPreviewPackage() {
                if (!this.aiPreviewPackage) {
                    return;
                }
                const text = JSON.stringify(this.aiPreviewPackage, null, 2);
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(text).then(() => {
                        this.aiPreviewCopied = true;
                        this.aiPreviewCopyMessage = '已复制到剪贴板。';
                    }).catch(() => {
                        this.aiPreviewCopied = false;
                        this.aiPreviewCopyMessage = '复制失败，请手动选择文本复制。';
                    });
                } else {
                    try {
                        const textarea = document.createElement('textarea');
                        textarea.value = text;
                        textarea.style.position = 'fixed';
                        textarea.style.opacity = '0';
                        document.body.appendChild(textarea);
                        textarea.select();
                        document.execCommand('copy');
                        document.body.removeChild(textarea);
                        this.aiPreviewCopied = true;
                        this.aiPreviewCopyMessage = '已复制到剪贴板。';
                    } catch (e) {
                        this.aiPreviewCopied = false;
                        this.aiPreviewCopyMessage = '复制失败，请手动选择文本复制。';
                    }
                }
            },
            // V2: 日期格式化
            formatDate(value) {
                if (!value) return '';
                const date = new Date(value);
                if (isNaN(date.getTime())) return value;
                const yyyy = date.getFullYear();
                const mm = String(date.getMonth() + 1).padStart(2, '0');
                const dd = String(date.getDate()).padStart(2, '0');
                const hh = String(date.getHours()).padStart(2, '0');
                const mi = String(date.getMinutes()).padStart(2, '0');
                return `${yyyy}-${mm}-${dd} ${hh}:${mi}`;
            },
            updateVocabBoxTranslationList() {
                this.translationList = this._translationText.split(';');
            },
            addDefinitionToInput(definition) {
                if (this.translationText.length && this.translationText[this.translationText.length - 1] !== ';') {
                    this.translationText += ';';
                }

                this.translationText += definition;
                this.inputChanged('translation');
            },
            addDefinitionAsSense(payload) {
                if (this.$refs.wordSensesList && this.$refs.wordSensesList.openAddFormFromDictionary) {
                    this.$refs.wordSensesList.openAddFormFromDictionary(payload);
                }
            },
            loadAiSuggestions() {
                // Uses VocabularyAiSuggestionService so the responsive
                // (half-screen / narrow) vocab box and the wide-screen side box
                // share the same AI candidate lookup rules. The service owns the
                // request and response shaping; this component owns Vuex state.
                const context = buildAiSuggestionLookupContext({
                    chapterId: this.$store.state.vocabularyBox.chapterId,
                    sentenceIndex: this.$store.state.vocabularyBox.sentenceIndex,
                    word: this.word,
                    studyBase: this.studyBase,
                    storeStudyBase: this._studyBase,
                    baseWord: this._baseWord,
                });
                if (!context) {
                    this.latestAiLookupKey = '';
                    this.$store.commit('vocabularyBox/setAiLookupError', '');
                    this.$store.commit('vocabularyBox/setAiLookupLoading', false);
                    this.$store.commit('vocabularyBox/setAiVocabSuggestions', []);
                    this.$store.commit('vocabularyBox/setAiPhraseSuggestions', []);
                    return;
                }
                const lookupKey = buildAiSuggestionLookupKey(context);
                this.latestAiLookupKey = lookupKey;
                this.$store.commit('vocabularyBox/setAiLookupLoading', true);
                this.$store.commit('vocabularyBox/setAiLookupError', '');
                fetchAiSuggestions(axios, context).then((result) => {
                    if (this.latestAiLookupKey !== lookupKey) {
                        return;
                    }
                    this.$store.commit('vocabularyBox/setAiVocabSuggestions', result.vocabularySuggestions);
                    this.$store.commit('vocabularyBox/setAiPhraseSuggestions', result.phraseSuggestions);
                }).catch(() => {
                    if (this.latestAiLookupKey !== lookupKey) {
                        return;
                    }
                    this.$store.commit('vocabularyBox/setAiLookupError', '无法读取 AI 建议。');
                    this.$store.commit('vocabularyBox/setAiVocabSuggestions', []);
                    this.$store.commit('vocabularyBox/setAiPhraseSuggestions', []);
                }).finally(() => {
                    if (this.latestAiLookupKey !== lookupKey) {
                        return;
                    }
                    this.$store.commit('vocabularyBox/setAiLookupLoading', false);
                });
            },
            useAiSuggestion(vi) {
                if (this.$refs.wordSensesList && this.$refs.wordSensesList.openAddFormFromAi) {
                    this.$refs.wordSensesList.openAddFormFromAi(buildAiVocabSensePayload(vi));
                }
            },
            useAiPhraseSuggestion(pi) {
                if (this.$refs.wordSensesList && this.$refs.wordSensesList.openAddFormFromAi) {
                    this.$refs.wordSensesList.openAddFormFromAi(buildAiPhraseSensePayload(pi));
                }
            },
            inputChanged(inputName = '') {
                this.updateVocabBoxTranslationList();

                this.$emit('updateVocabBoxData', {
                    reading: this.reading,
                    baseWord: this.baseWord,
                    baseWordReading: this.baseWordReading,
                    phraseReading: this.phraseReading,
                    translationText: this.translationText
                });

                if (inputName == 'translation' && this.stage >= 0 && this.$props.autoHighlightWords && this.translationText !== '') {
                    this.setStage(-7);
                }
            },
            unselectAllWords() {
                this.$emit('unselectAllWords');
            },
            addSelectedWordToAnki() {
                this.$emit('addSelectedWordToAnki');
            },
            close() {
                this.$emit('unselectAllWords');
            }
        }
    }
</script>
