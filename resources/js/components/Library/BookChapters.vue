<template>
    <v-container class="book-chapters py-0">
        <error-dialog
            v-if="errorDialog.active"
            v-model="errorDialog.active"
            :content="errorDialog.content"
        ></error-dialog>

        <edit-book-chapter-dialog
            v-if="editBookChapterDialog.active"
            v-model="editBookChapterDialog.active"
            :book-id="$props.bookId"
            :chapter-id="editBookChapterDialog.chapterId"
            @chapter-saved="chapterSaved"
        ></edit-book-chapter-dialog>

        <delete-book-chapter-dialog
            v-if="deleteBookChapterDialog.active"
            v-model="deleteBookChapterDialog.active"
            :chapter-id="deleteBookChapterDialog.chapterId"
            :chapter-name="deleteBookChapterDialog.chapterName"
            @confirm="deleteChapter"
        ></delete-book-chapter-dialog>

        <start-review-dialog
            v-model="startReviewDialog.active"
            :book-id="startReviewDialog.bookId"
            :book-name="startReviewDialog.bookName"
            :chapter-id="startReviewDialog.chapterId"
            :chapter-name="startReviewDialog.chapterName"
        ></start-review-dialog>

        <v-data-table
            class="my-4 mb-0 no-hover"
            :headers="[
                { text: '章节', value: 'name'},
                { text: '总词数', value: 'wordCount.total', align: 'center' },
                { text: '唯一词', value: 'wordCount.unique', align: 'center' },
                { text: '已知词', value: 'wordCount.known', align: 'center' },
                { text: '高亮词', value: 'wordCount.highlighted', align: 'center' },
                { text: '新词', value: 'wordCount.new', align: 'center' },
                { text: '操作', value: 'actions', sortable: false },
            ]"
            :items="chapters"
            :loading="chaptersLoading"
            :items-per-page="-1"
            hide-default-footer
        >
            <template v-slot:item.wordCount.total="{ item }">
                <template v-if="isWordCountReady(item)">
                    {{ formatNumber(item.wordCount.total) }}
                </template>
                <template v-else-if="shouldShowWordCountPlaceholder(item)">-</template>
                <v-skeleton-loader
                    v-else
                    class="chapter-word-count-skeleton rounded-pill"
                    type="image"
                ></v-skeleton-loader>
            </template>

            <template v-slot:item.wordCount.unique="{ item }">
                <template v-if="isWordCountReady(item)">
                    {{ formatNumber(item.wordCount.unique) }}
                </template>
                <template v-else-if="shouldShowWordCountPlaceholder(item)">-</template>
                <v-skeleton-loader
                    v-else
                    class="chapter-word-count-skeleton rounded-pill"
                    type="image"
                ></v-skeleton-loader>
            </template>

            <template v-slot:item.wordCount.known="{ item }">
                <template v-if="isWordCountReady(item)">
                    <template v-if="$props.wordCountDisplayType == 0">
                        {{ formatNumber(item.wordCount.known) }}
                    </template>
                    <template v-else-if="item.wordCount.unique">
                        {{ (item.wordCount.known / item.wordCount.unique * 100).toFixed(1) }}%
                    </template>
                    <template v-else>0%</template>
                </template>
                <template v-else-if="shouldShowWordCountPlaceholder(item)">-</template>
                <v-skeleton-loader
                    v-else
                    class="chapter-word-count-skeleton rounded-pill"
                    type="image"
                ></v-skeleton-loader>
            </template>

            <template v-slot:item.wordCount.highlighted="{ item }">
                <template v-if="isWordCountReady(item)">
                    <div class="highlighted-words px-2 rounded-xl mx-auto">
                        <template v-if="$props.wordCountDisplayType < 2">
                            {{ formatNumber(item.wordCount.highlighted) }}
                        </template>
                        <template v-else-if="item.wordCount.unique">
                            {{ (item.wordCount.highlighted / item.wordCount.unique * 100).toFixed(1) }}%
                        </template>
                        <template v-else>0%</template>
                    </div>
                </template>
                <template v-else-if="shouldShowWordCountPlaceholder(item)">-</template>
                <v-skeleton-loader
                    v-else
                    class="chapter-word-count-skeleton rounded-pill"
                    type="image"
                ></v-skeleton-loader>
            </template>

            <template v-slot:item.wordCount.new="{ item }">
                <template v-if="isWordCountReady(item)">
                    <div class="new-words px-2 rounded-xl mx-auto">
                        <template v-if="$props.wordCountDisplayType < 2">
                            {{ formatNumber(item.wordCount.new) }}
                        </template>
                        <template v-else-if="item.wordCount.unique">
                            {{ (item.wordCount.new / item.wordCount.unique * 100).toFixed(1) }}%
                        </template>
                        <template v-else>0%</template>
                    </div>
                </template>
                <template v-else-if="shouldShowWordCountPlaceholder(item)">-</template>
                <v-skeleton-loader
                    v-else
                    class="chapter-word-count-skeleton rounded-pill"
                    type="image"
                ></v-skeleton-loader>
            </template>

            <template v-slot:item.actions="{ item }">
                <div class="d-flex justify-center">
                    <template v-if="item.processing_status == 'processed'">
                        <v-btn icon :to="'/chapters/read/' + item.id" title="阅读">
                            <v-icon>mdi-book-open-variant</v-icon>
                        </v-btn>
                        <v-menu rounded offset-y bottom left nudge-top="-5">
                            <template v-slot:activator="{ on, attrs }">
                                <v-btn icon v-bind="attrs" v-on="on"><v-icon>mdi-dots-horizontal</v-icon></v-btn>
                            </template>
                            <v-btn width="100" class="menu-button" tile color="white" @click="showEditChapterDialog(item.id)">
                                编辑
                            </v-btn>
                            <v-btn width="100" class="menu-button" tile color="white" @click="showStartReviewDialog(book.id, book.name, item.id, item.name)">
                                复习
                            </v-btn>
                            <v-btn width="100" class="menu-button" tile color="white" @click="showDeleteChapterDialog(item)">
                                删除
                            </v-btn>
                        </v-menu>
                    </template>

                    <template v-else-if="item.processing_status === 'unprocessed'">
                        <v-chip small color="warning">处理中</v-chip>
                    </template>

                    <template v-else-if="item.processing_status === 'failed'">
                        <v-chip small color="error">处理失败</v-chip>
                    </template>
                </div>
            </template>
        </v-data-table>
    </v-container>
</template>

<script>
    import {formatNumber} from './../../helper.js';
    import { requestErrorMessage } from './../../services/UiTextService';

    export default {
        data: function() {
            return {
                book: null,
                bookWordCount: null,
                chapters: [],
                chaptersLoading: false,
                randomChapter: 0,
                errorDialog: {
                    active: false,
                    content: '',
                },
                editBookChapterDialog: {
                    active: false,
                    chapterId: -1,
                },
                deleteBookChapterDialog: {
                    active: false,
                    chapterId: -1,
                },
                startReviewDialog: {
                    active: false,
                    bookId: -1,
                    bookName: '',
                    chapterId: -1,
                    chapterName: '',
                }
            }
        },
        props: {
            bookId: Number,
            wordCountDisplayType: Number,
        },
        mounted() {
            this.loadChapters();

            this.$store.getters['shared/echo']
                .private('chapter-status-update.' + this.$store.getters['shared/userUuid'])
                .listen('ChapterStateUpdatedEvent', (message) => {
                    this.chapterStatusUpdate(JSON.parse(message.chapters));
                });
        },
        beforeDestroy() {
            this.$store.getters['shared/echo']
                .private('chapter-status-update.' + this.$store.getters['shared/userUuid'])
                .stopListening('ChapterStateUpdatedEvent');
        },
        methods: {
            chapterStatusUpdate(chapters) {
                this.chapters.forEach((currentChapter) => {
                    if (!chapters[currentChapter.id]) {
                        return;
                    }

                    if ('wordCount' in chapters[currentChapter.id] && chapters[currentChapter.id].wordCount !== null) {
                        currentChapter.wordCount = chapters[currentChapter.id].wordCount;
                        currentChapter.wordCountsLoaded = true;
                        currentChapter.wordCountLoadFailed = false;
                    }

                    if ('processing_status' in chapters[currentChapter.id]) {
                        currentChapter.processing_status = chapters[currentChapter.id].processing_status;
                    }
                });
            },
            chapterSaved() {
                this.$emit('word-count-changed');
            },
            showEditChapterDialog(chapterId) {
                this.editBookChapterDialog.active = true;
                this.editBookChapterDialog.chapterId = chapterId;
            },
            showDeleteChapterDialog(chapter) {
                this.deleteBookChapterDialog.active = true;
                this.deleteBookChapterDialog.chapterId = chapter.id;
                this.deleteBookChapterDialog.chapterName = chapter.name;
            },
            deleteChapter() {
                axios.post('/chapters/delete', {
                    'chapterId': this.deleteBookChapterDialog.chapterId,
                }).then((response) => {
                    if (response.status === 200) {
                        this.$emit('word-count-changed');
                    } else {
                        this.errorDialog.content = '删除章节失败。';
                        this.errorDialog.active = true;
                    }
                }).catch((error) => {
                    this.errorDialog.content = requestErrorMessage(error, '删除章节失败。');
                    this.errorDialog.active = true;
                });
            },
            loadChapters() {
                this.chaptersLoading = true;
                this.chapters = [];

                axios.post('/chapters', {
                    'bookId': this.$props.bookId,
                }).then((response) => {
                    for (let chapterIndex = 0; chapterIndex < response.data.chapters.length; chapterIndex++) {
                        response.data.chapters[chapterIndex].wordCountsLoaded = false;
                        response.data.chapters[chapterIndex].wordCountLoadFailed = false;
                    }

                    this.book = response.data.book;
                    this.chapters = response.data.chapters;

                    if (this.chapters.length) {
                        this.randomChapter = this.chapters[Math.floor(Math.random() * this.chapters.length)].id;
                    } else {
                        this.randomChapter = 0;
                    }

                    this.chaptersLoading = false;
                    this.$nextTick(() => {
                        axios.get('/chapters/word-counts/' + this.$props.bookId).then((response) => {
                            this.chapterStatusUpdate(response.data);
                        }).catch((error) => {
                            this.chapters.forEach((chapter) => {
                                chapter.wordCountLoadFailed = true;
                            });
                            this.errorDialog.content = requestErrorMessage(error, '章节词数加载失败。实时状态服务未启动时，请手动刷新页面。');
                            this.errorDialog.active = true;
                        });
                    });
                }).catch((error) => {
                    this.chapters = [];
                    this.errorDialog.content = requestErrorMessage(error, '章节列表加载失败。');
                    this.errorDialog.active = true;
                    this.chaptersLoading = false;
                });
            },
            showStartReviewDialog(bookId, bookName, chapterId, chapterName) {
                this.startReviewDialog.bookName = bookName;
                this.startReviewDialog.bookId = bookId;
                this.startReviewDialog.chapterName = chapterName;
                this.startReviewDialog.chapterId = chapterId;
                this.startReviewDialog.active = true;
            },
            isWordCountReady(chapter) {
                return chapter.processing_status === 'processed' && chapter.wordCountsLoaded && chapter.wordCount;
            },
            shouldShowWordCountPlaceholder(chapter) {
                return chapter.wordCountLoadFailed || chapter.processing_status !== 'processed';
            },
            formatNumber: formatNumber
        }
    }
</script>
