<template>
    <div>
        <v-form ref="libraryLocationForm" v-model="isFormValid">
            <v-alert dark border="left" type="info" color="primary" class="mt-4">
                如果导入内容会拆成多个章节，章节名会自动加上序号。
                例如章节名为“英语短文”，导入后会变成：<br><br>
                <ul class="mb-0">
                    <li>英语短文 1</li>
                    <li>英语短文 2</li>
                    <li>...</li>
                </ul>
            </v-alert>
            <v-alert v-if="error" dense outlined type="error">{{ error }}</v-alert>

            <!-- Book selector and chapter name -->
            <label class="font-weight-bold">阅读材料位置</label>
            
            <!-- Skeleton loaders for radio buttons -->
            <div class="library-location-skeleton-loader mb-2" v-if="loading">
                <v-skeleton-loader
                    class="rounded-pill mr-1"
                    type="card"
                ></v-skeleton-loader>
                <v-skeleton-loader
                    class="rounded-pill"
                    type="card"
                ></v-skeleton-loader>
            </div>
            <div class="library-location-skeleton-loader longer" v-if="loading">
                <v-skeleton-loader
                    class="rounded-pill mr-1"
                    type="card"
                ></v-skeleton-loader>
                <v-skeleton-loader
                    class="rounded-pill"
                    type="card"
                ></v-skeleton-loader>
            </div>

            <!-- Create or import into existing book radio buttons -->
            <v-radio-group
                v-if="!loading"
                v-model="newOrExistingBook"
                class="mt-0"
                :rules="[rules.newOrExistingBook]"
                @change="formChanged"
            >
                <v-radio
                    label="创建新书"
                    value="new"
                    :disabled="loading"
                ></v-radio>
                <v-radio
                    v-if="books.length > 0"
                    label="使用已有书籍"
                    value="existing"
                ></v-radio>
            </v-radio-group>
            
            <!-- Book selector for existing book -->
            <template v-if="newOrExistingBook == 'existing'">
                <label class="font-weight-bold">书籍</label>
                <v-select
                    v-model="bookId"
                    :items="books"
                    placeholder="选择书籍"
                    item-value="id"
                    dense
                    filled
                    rounded
                    :rules="[rules.bookId]"
                    @change="formChanged"
                >
                    <template v-slot:selection="{ item, index }">
                        {{ item.name }}
                    </template>
                    <template v-slot:item="{ item }">
                        {{ item.name }}
                    </template>
                </v-select>
            </template>

            <!-- Book name text field for new book -->
            <template v-if="newOrExistingBook == 'new'">
                <label class="font-weight-bold">书名</label>
                <v-text-field 
                    v-model="bookName"
                    filled
                    dense
                    rounded
                    placeholder="书名"
                    maxlength="128"
                    :rules="[rules.bookName]"
                    @input="formChanged"
                    @keyup="formChanged"
                ></v-text-field>
            </template>
            
            <!-- Chapter name -->
            <template v-if="newOrExistingBook !== ''">
                <label class="font-weight-bold">章节名</label>
                <v-text-field 
                    v-model="chapterName"
                    filled
                    dense
                    rounded
                    placeholder="章节名"
                    maxlength="120"
                    :rules="[rules.chapterName]"
                    @input="formChanged"
                    @keyup="formChanged"
                ></v-text-field>
            </template>
        </v-form>
    </div>
</template>

<script>
    import { requestErrorMessage } from './../../../services/UiTextService';

    export default {
        data: function() {
            return {
                loading: true,
                error: '',
                books: [{id: -1, name: '加载中'}],
                bookId: -1,
                isFormValid: false,
                newOrExistingBook: '',
                bookName: '',
                chapterName: '',
                rules: {
                    newOrExistingBook: (value) => {
                        if (value === '') {
                            return '请选择一个选项。';
                        }

                        return true;
                    },
                    bookId: (value) => {
                        return true;
                    },
                    bookName: (value) => {
                        if (!value.length) {
                            return '请输入书名。';
                        }

                        if (value.length > 128) {
                            return '书名不能超过 128 个字符。';
                        }

                        return true;
                    },
                    chapterName: (value) => {
                        if (!value.length) {
                            return '请输入章节名。';
                        }

                        if (value.length > 120) {
                            return '章节名不能超过 120 个字符。';
                        }

                        return true;
                    }
                }
            }
        },
        props: {
        },
        mounted() {
            this.loading = true;
            this.error = '';
            axios.post('/books').then((response) => {
                this.books = response.data;
                
                if (this.books.length) {
                    this.bookId = this.books[0].id;
                }
            }).catch((error) => {
                this.books = [];
                this.error = requestErrorMessage(error, '书籍列表加载失败，请稍后重试。');
            }).finally(() => {
                this.loading = false;
            });
        },
        methods: {
            formChanged() {
                this.$nextTick(() => {
                    var valid = true;
                    if (!this.$refs.libraryLocationForm.validate()) {
                        valid = false;
                    }

                    if (this.newOrExistingBook === 'new' && this.bookName === '') {
                        valid = false;
                    }

                    if (this.newOrExistingBook === 'existing' && this.bookId === -1) {
                        valid = false;
                    }

                    this.$emit('input-changed', {
                        isFormValid: valid,
                        newOrExistingBook: this.newOrExistingBook,
                        bookId: this.bookId,
                        bookName: this.bookName,
                        chapterName: this.chapterName
                    });
                });
            }
        }
    }
</script>
