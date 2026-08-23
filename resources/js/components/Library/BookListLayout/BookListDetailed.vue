<template>
    <!-- Book list detailed -->
    <div id="book-list" class="detailed">
        <v-card
            outlined
            :id="'book-' + book.id"
            class="book detailed rounded-lg mx-auto my-6"
            v-for="(book, index) in books"
            :key="index"
        >
            <div class="book-box">
                <!-- Cover image -->
                <div class="cover-image-box rounded-lg">
                    <img
                        v-if="book.cover_image"
                        class="cover-image rounded-lg"
                        :src="'/images/book_images/' + book.cover_image"
                    ></img>
                    <NoBookCoverIcon v-else/>
                </div>

                <!-- Title bar -->
                <v-card-text class="book-information pa-0 pl-3">
                    <v-card-title class="book-title pa-3">
                        <div class="book-title-text default-font">{{ book.name }}</div>
                        <v-spacer></v-spacer>
                        <v-menu content-class="book-menu" rounded offset-y bottom left nudge-top="-5">
                            <template v-slot:activator="{ on, attrs }">
                                <v-btn icon v-bind="attrs" v-on="on"><v-icon>mdi-dots-horizontal</v-icon></v-btn>
                            </template>
                            <v-btn class="menu-button" tile color="white" @click="loadBookWordCounts(index)">加载词数</v-btn>
                            <v-btn class="menu-button" tile color="white" @click="showEditBookDialog(book)">编辑</v-btn>
                            <v-btn class="menu-button" tile color="white" @click="showDeleteBookDialog(book)">删除</v-btn>
                        </v-menu>
                    </v-card-title>

                    <div class="px-3 pb-3">
                        <template v-if="book.readingProgress && book.readingProgress.available">
                            <div class="d-flex align-center text-caption font-weight-medium mb-1">
                                <span>阅读进度</span>
                                <v-spacer></v-spacer>
                                <span>{{ Number(book.readingProgress.percentage).toFixed(1) }}%</span>
                            </div>
                            <v-progress-linear
                                :value="book.readingProgress.percentage"
                                color="primary"
                                height="7"
                                rounded
                                :aria-label="book.name + '阅读进度'"
                            ></v-progress-linear>
                        </template>
                        <div v-else class="text-caption text--secondary">暂无可用阅读进度</div>
                    </div>

                    <!-- Word counts loading animation -->
                    <div class="book-info-not-loaded-box mb-1" v-if="book.wordCount === null">
                        <template v-if="book.wordCountLoading">
                            <v-progress-circular indeterminate color="primary" />
                        </template>
                    </div>

                    <!-- Word counts -->
                    <v-simple-table dense class="book-info-table no-hover pb-4  mx-auto" v-if="book.wordCount !== null">
                        <tbody>
                            <tr>
                                <td width="200px">总词数</td>
                                <td class="text-center"><div class="info-table-value">{{ formatNumber(book.wordCount.total) }}</div></td>
                            </tr>
                            <tr>
                                <td width="200px">唯一词数</td>
                                <td class="text-center"><div class="info-table-value">{{ formatNumber(book.wordCount.unique) }}</div></td>
                            </tr>
                            <tr>
                                <td width="200px">已知词</td>
                                <td class="text-center"><div class="info-table-value">{{ formatNumber(book.wordCount.known) }}</div></td>
                            </tr>
                            <tr>
                                <td width="200px">高亮词</td>
                                <td class="text-center"><div class="info-table-value highlighted-words px-2 rounded-xl">{{ formatNumber(book.wordCount.highlighted) }}</div></td>
                            </tr>
                            <tr>
                                <td width="200px">新词</td>
                                <td class="text-center"><div class="info-table-value new-words px-2 rounded-xl">{{ formatNumber(book.wordCount.new) }}</div></td>
                            </tr>
                        </tbody>
                    </v-simple-table>
                <v-card-actions>
                    <v-spacer />
                    <v-btn rounded class="mx-0" color="primary" @click="openBook(book.id)" v-if="!book.chaptersVisible">打开</v-btn>
                    <v-btn rounded class="mx-0" color="primary"  v-if="book.chaptersVisible" @click="addChapter(book.id)"><v-icon> mdi-plus</v-icon>添加章节</v-btn>
                </v-card-actions>
                </v-card-text>
            </div>
        </v-card>
    </div>
</template>

<script>
    import {formatNumber} from './../../../helper.js';
    export default {
        data: function() {
            return {
            }
        },
        props: {
            books: Array
        },
        mounted() {
        },
        methods: {
            loadBookWordCounts(index) {
                this.books[index].wordCountLoading = true;
                this.books[index].wordCount = null;

                axios.get('/books/get-word-counts/' + this.books[index].id).then((response) => {
                    if (response.data !== 'error') {
                        this.books[index].wordCountLoading = false;
                        this.books[index].wordCount = response.data;
                    }
                });
            },
            showEditBookDialog(book) {
                this.$emit('show-edit-book-dialog', book);
            },
            showDeleteBookDialog(book) {
                this.$emit('show-delete-book-dialog', book);
            },
            openBook(bookId) {
                this.$emit('open-book', bookId);
            },
            formatNumber: formatNumber
        }
    }
</script>
