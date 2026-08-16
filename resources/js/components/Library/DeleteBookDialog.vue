<template>
    <v-dialog v-model="value" persistent max-width="500px" height="300px">
        <v-card id="delete-book-dialog" class="rounded-lg">
            <v-progress-linear
                class="delete-dialog-delay"
                :value="deletionEnabledDelay"
                color="error"
                height="8"
            ></v-progress-linear>
            <v-card-title>
                <v-icon large class="mr-2" color="error">mdi-alert-circle</v-icon>
                <span class="text-h5">删除书籍</span>
                <v-spacer></v-spacer>
                <v-btn icon @click="close">
                    <v-icon>mdi-close</v-icon>
                </v-btn>
            </v-card-title>
            <v-card-text class="pt-4 pb-6">
                <v-progress-circular v-if="loading" indeterminate color="primary"></v-progress-circular>
                <v-alert v-else-if="loadError" type="error" text>{{ loadError }}</v-alert>
                <div v-else>
                    <p>删除《{{ impact.book_name }}》将删除 {{ impact.chapter_count }} 个章节。</p>
                    <p>
                        {{ impact.source_occurrence_count }} 条来源记录、{{ impact.word_sense_count }} 个词义、
                        {{ impact.review_card_count }} 张复习卡、{{ impact.review_log_count }} 条复习记录和
                        {{ impact.reading_session_count }} 个阅读会话会保留，但原章节将无法再打开。
                    </p>
                    <v-checkbox
                        v-model="acknowledged"
                        label="我已了解来源导航将不可用，学习历史会保留"
                        hide-details
                    ></v-checkbox>
                </div>
            </v-card-text>
            <v-card-actions>
                <v-spacer></v-spacer>
                <v-btn rounded text @click="close">取消</v-btn>
                <v-btn rounded :dark="canDelete" color="error" @click="confirm" :disabled="!canDelete">
                    <v-icon class="mr-2" color="white">mdi-delete</v-icon>
                    删除
                </v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>
</template>

<script>
    import { requestErrorMessage } from './../../services/UiTextService';

    export default {
        props: {
            value : Boolean,
            bookId: Number,
            bookName: String,
        },
        emits: ['input'],
        data: function() {
            return {
                deletionEnabledDelay: 0,
                loading: true,
                loadError: '',
                impact: null,
                acknowledged: false,
            };
        },
        computed: {
            canDelete() {
                return this.impact !== null && this.acknowledged && this.deletionEnabledDelay > 100;
            },
        },
        mounted: function() {
            this.loadImpact();
        },
        methods: {
            loadImpact() {
                axios.post('/books/delete', {
                    bookId: this.$props.bookId,
                    mode: 'preview',
                }).then((response) => {
                    this.impact = response.data;
                    this.progressDelay();
                }).catch((error) => {
                    this.loadError = requestErrorMessage(error, '删除影响加载失败。');
                }).finally(() => {
                    this.loading = false;
                });
            },
            progressDelay() {
                this.deletionEnabledDelay ++;

                if (this.deletionEnabledDelay < 101) {
                    setTimeout(this.progressDelay, 30);
                }
            },
            confirm() {
                if (!this.canDelete) {
                    return;
                }

                this.$emit('confirm', this.$props.bookId);
                this.$emit('input', false);
            },
            close() {
                this.$emit('input', false);
            }
        }
    }
</script>
