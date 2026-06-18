<template>
    <v-dialog v-model="value" persistent width="800px" @keydown.enter.prevent="enterPressed">
        <v-card class="rounded-lg">
            <!-- Card title -->
            <v-card-title>
                <!-- Edit chapter title -->
                <template v-if="$props.chapterId !== -1">
                    <v-icon class="mr-2">mdi-text-box-edit</v-icon>编辑章节
                </template>

                <!-- New chapter title -->
                <template v-if="$props.chapterId == -1">
                    <v-icon class="mr-2">mdi-text-box-plus</v-icon>添加章节
                </template>

                <v-spacer />
                <v-btn icon @click="close">
                    <v-icon>mdi-close</v-icon>
                </v-btn>
            </v-card-title>

            <!-- Form -->
            <v-card-text>
                <v-form ref="editChapterForm" v-if="!loading">
                    <label class="font-weight-bold mt-2">章节名</label>
                    <v-text-field 
                        ref="chapterName"
                        class="default-font"
                        filled
                        dense
                        rounded
                        v-model="name"
                        :rules="[rules.chapterName]"
                        :disabled="type !== 'text' || loading"
                    ></v-text-field>
                    
                    <label class="font-weight-bold mt-2">文本</label>
                    <v-textarea
                        class="default-font"
                        v-model="text"
                        filled
                        dense
                        rounded
                        no-resize
                        height="300px"
                        maxlength="15000"
                        counter="15000"
                        :disabled="type !== 'text' || loading"
                    ></v-textarea>
                    
                    <!-- Save result alerts -->
                    <v-alert
                        class="my-3" 
                        border="left"
                        type="error"
                        v-if="saveResult == 'error'"
                    >
                        保存时发生错误。
                    </v-alert>

                    <v-alert
                        class="my-3" 
                        border="left"
                        type="success"
                        v-if="saveResult == 'success'"
                    >
                        章节已保存。
                    </v-alert>

                    <!-- Subtitle editing is not enabled alert -->
                    <v-alert
                        v-if="type !== 'text'"
                        class="my-3" 
                        border="left"
                        type="error"
                    >
                        目前还不能编辑字幕内容，后续版本会支持。
                    </v-alert>
                </v-form>
            </v-card-text>

            <!-- Action buttons -->
            <v-card-actions>
                <v-spacer></v-spacer>
                <v-btn rounded text @click="close">取消</v-btn>

                <v-btn 
                    rounded 
                    depressed
                    color="primary" 
                    @click="save"
                    :disabled="!isFormValid || saving || saveResult == 'success' || type !== 'text'"
                    :loading="saving"
                >
                    保存
                </v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>
</template>

<script>
    export default {
        data: function() {
            return {
                isFormValid: false,
                loading: true,
                saving: false,
                saveResult: '',
                rules: {
                    chapterName: (value) => {
                        if (!value.length) {
                            this.isFormValid = false;
                            return '请输入章节名。';
                        }

                        if (value.length > 128) {
                            this.isFormValid = false;
                            return '章节名不能超过 128 个字符。';
                        }

                        this.isFormValid = true;
                        return true;
                    }
                },
                name: '',
                text: '',
                type: 'text',
            }
        },
        props: {
            value : Boolean,
            bookId: Number,
            chapterId: Number
        },
        emits: ['input'],
        mounted() {
            this.loadChapter();
        },
        methods: {
            enterPressed() {
                if (this.$refs.editChapterForm.validate()) {
                    this.save();
                }
            },
            save() {
                this.saveResult = '';
                if (!this.$refs.editChapterForm.validate()) {
                    return;
                }

                this.saving = true;
                var url = '/chapters/update';
                var data = {
                    'chapterName': this.name,
                    'chapterText': this.text,
                };

                if (this.$props.chapterId !== -1) {
                    data.chapterId = this.$props.chapterId;
                } else {
                    data.bookId = this.$props.bookId;
                    url = '/chapters/create';
                }
                
                axios.post(url, data).then((response) => {
                    this.saving = false;
                    if (response.status === 200) {
                        this.saveResult = 'success';
                        this.$emit('chapter-saved');

                        setTimeout(() => {
                            this.close();
                        }, 750);
                    } else {
                        this.saveResult = 'error';
                    }
                }).catch((error) => {
                    this.saving = false;
                    this.saveResult = 'error';
                });
            },
            loadChapter() {
                if (this.$props.chapterId !== -1) {
                    axios.post('/chapters/get/editor', {
                        chapterId: this.$props.chapterId,
                    }).then((response) => {
                        this.name = response.data.name;
                        this.text = response.data.raw_text;
                        this.type = response.data.type;
                        this.loading = false;
                        this.$nextTick(() => {
                            this.$refs.editChapterForm.validate();

                            this.$refs.chapterName.focus();
                        });
                    });
                } else {
                    this.loading = false;
                    this.$nextTick(() => {
                        this.$refs.editChapterForm.validate();
                        this.$refs.chapterName.focus();
                    });
                }
            },
            close() {
                this.$emit('input', false);
            }
        }
    }
</script>
