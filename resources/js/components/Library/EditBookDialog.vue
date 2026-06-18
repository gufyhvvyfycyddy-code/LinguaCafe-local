<template>
    <v-dialog v-model="value" persistent width="800px" @keydown.enter.prevent="enterPressed">
        <v-card id="edit-book-dialog" class="rounded-lg">
            <!-- Card title -->
            <v-card-title>
                <!-- Edit book title -->
                <template v-if="$props.bookId !== -1">
                    <v-icon class="mr-2">mdi-folder-edit</v-icon>编辑书籍
                </template>

                <!-- New book title -->
                <template v-if="$props.bookId == -1">
                    <v-icon class="mr-2">mdi-folder-plus</v-icon>添加书籍
                </template>

                <v-spacer />
                <v-btn icon @click="close">
                    <v-icon>mdi-close</v-icon>
                </v-btn>
            </v-card-title>
            
            <!-- Form -->
            <v-card-text>
                <v-form ref="bookForm">
                    <label class="font-weight-bold">书名</label>
                    <v-text-field 
                        v-model="name"
                        class="default-font"
                        ref="bookName"
                        filled
                        dense
                        rounded
                        placeholder="书名"
                        :rules="[rules.name]"
                        maxlength="128"
                        @keyup="validateForm"
                    ></v-text-field>
                    
                    
                    <label class="font-weight-bold mt-2" v-show="editImage">书籍封面</label><br>
                    <v-file-input
                        v-show="editImage"
                        v-model="image"
                        filled
                        dense
                        rounded
                        clearable
                        ref="image"
                        accept=".jpg,.jpeg,.png,.webp"
                        placeholder="封面图片"
                        prepend-icon="mdi-image"
                        @change="imageChanged"
                    ></v-file-input>
                    
                    <template v-if="!editImage">
                        <div id="image-upload-box" class="d-flex">
                            <div id="image-box" class="d-flex align-center">
                                <img v-if="$props.bookCover"
                                    class="cover-image rounded-xl"
                                    :src="'/images/book_images/' + $props.bookCover"
                                    width="100px"
                                    :alt="$props.bookName + 'cover'"
                                ></img>
                                <NoBookCoverIcon v-else />
                            </div>
                            <div
                                id="edit-book-upload-image-button" 
                                class="rounded-xl bg-foreground-base"
                                @click="uploadImageButton"
                            >
                                
                                <h4><v-icon large>mdi-file-upload</v-icon> 更换图片</h4>
                            </div>
                        </div>
                    </template>
                    
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
                        书籍已保存。
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
                    :disabled="!isFormValid || saving || saveResult == 'success'"
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
                saving: false,
                saveResult: '',
                name: this.$props.bookName,
                image: null,
                editImage: false,

                rules: {
                    name: (value) => {
                        if (value === null || !value.length) {
                            return '请输入书名。'
                        }

                        if (value.length > 128) {
                            return '书名不能超过 128 个字符。'
                        }

                        return true;
                    }
                },
            }
        },
        props: {
            value : Boolean,
            bookId: Number,
            bookName: String,
            bookCover: String
        },
        emits: ['input'],
        mounted() {
            this.$refs.bookName.focus();

            if (this.$props.bookName.length) {
                this.validateForm();
            }
        },
        methods: {
            enterPressed() {
                if (this.$refs.bookForm.validate()) {
                    this.save();
                }
            },
            uploadImageButton() {
                this.$nextTick(() => {
                    this.$refs.image.$refs.input.click();
                });
            },
            imageChanged(event) {
                console.log('image changed', this.image, event);
                this.editImage = true;
                if (this.image === null || this.image === undefined) {
                    this.image = null;
                    this.editImage = false;
                }
            },
            validateForm() {
                this.isFormValid = this.$refs.bookForm.validate();
            },
            save() {
                if (!this.$refs.bookForm.validate()) {
                    this.isFormValid = false;
                    return;
                }

                this.saving = true;
                
                var url = '/books/update';
                var form = new FormData();
                form.set('bookName',this.name);
                
                if (this.$props.bookId === -1) {
                    url = '/books/create';
                } else {
                    form.set('bookId', this.$props.bookId);
                }
                
                if (this.editImage) {
                    form.set('bookCover', this.image);
                }

                axios.post(url, form).catch((e) => {
                    this.saveResult = 'error';
                    this.saving = false;
                }).then((response) => {
                    this.saving = false;
                    if (response.status === 200) {
                        this.saveResult = 'success';

                        setTimeout(() => {
                            this.$emit('book-saved');
                            this.close();
                        }, 750);
                    } else {
                        this.saveResult = 'error';
                    }
                });
            },
            close() {
                this.$emit('input', false);
            }
        }
    }
</script>
