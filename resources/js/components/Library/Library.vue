<template>
    <v-container id="books" :class="{'book-opened': openedBook !== -1}">
        <!-- Error dialog -->
        <error-dialog
            v-if="errorDialog.active"
            v-model="errorDialog.active"
            :content="errorDialog.content"
        ></error-dialog>

        <!-- Import dialog -->
        <import-dialog
            v-if="importDialog.active"
            v-model="importDialog.active"
            :language="$props.language"
            @import-finished="importFinished"
        ></import-dialog>

        <!-- Edit or add book dialog -->
        <edit-book-dialog
            v-if="editBookDialog.active"
            v-model="editBookDialog.active"
            :book-id="editBookDialog.bookId"
            :book-name="editBookDialog.bookName"
            :book-cover="editBookDialog.bookCover"
            @book-saved="loadBooks"
        ></edit-book-dialog>

        <!-- Delete book dialog -->
        <delete-book-dialog
            v-if="deleteBookDialog.active"
            v-model="deleteBookDialog.active"
            :book-id="deleteBookDialog.bookId"
            :book-name="deleteBookDialog.bookName"
            @confirm="deleteBook"
        ></delete-book-dialog>

        <!-- Toolbar -->
        <div id="toolbar" class="d-flex mx-auto mt-6 mb-2">
              <v-menu offset-y class="rounded-lg">
                    <template v-slot:activator="{ on, attrs }">
                        <v-btn color="foreground" rounded depressed v-bind="attrs" v-on="on">
                            布局
                            <v-icon v-if="attrs['aria-expanded'] === 'true'">mdi-chevron-up</v-icon>
                            <v-icon v-if="attrs['aria-expanded'] !== 'true'">mdi-chevron-down</v-icon>
                        </v-btn>
                    </template>
                    <v-btn
                        class="menu-button justify-start"
                        tile
                        color="white"
                        @click="setLayout('table')"
                    >
                        <v-icon class="mr-1">mdi-view-list</v-icon>
                        列表
                    </v-btn>
                    <v-btn
                        class="menu-button justify-start"
                        tile
                        color="white"
                        @click="setLayout('cover-only')"
                    >
                        <v-icon class="mr-1">mdi-view-module</v-icon>
                        仅封面
                    </v-btn>
                    <v-btn
                        class="menu-button justify-start"
                        tile
                        color="white"
                        @click="setLayout('detailed')"
                    >
                        <v-icon class="mr-1">mdi-view-agenda</v-icon>
                        详细
                    </v-btn>
                </v-menu>

                <v-spacer></v-spacer>
                <v-menu offset-y class="rounded-lg">
                    <template v-slot:activator="{ on, attrs }">
                        <v-btn class="library-small-screen" :color="theme == 'eink' ? 'white' : ''" rounded depressed v-bind="attrs" v-on="on">
                            阅读材料
                            <v-icon v-if="attrs['aria-expanded'] === 'true'">mdi-chevron-up</v-icon>
                            <v-icon v-if="attrs['aria-expanded'] !== 'true'">mdi-chevron-down</v-icon>
                        </v-btn>
                    </template>
                    <v-btn
                        class="menu-button justify-start"
                        tile
                        color="white"
                        @click="showEditBookDialog(null)"
                    >
                        <v-icon class="mr-1">mdi-book-plus</v-icon>
                        创建书籍
                    </v-btn>
                    <v-btn
                        class="menu-button justify-start"
                        tile
                        color="white"
                        @click="importDialog.active = true;"
                    >
                        <v-icon class="mr-1">mdi-import</v-icon>
                        导入
                    </v-btn>
                </v-menu>

                <v-btn
                    rounded
                    class="library-large-screen mx-0"
                    color="primary"
                    @click="showEditBookDialog(null)"
                >
                    <v-icon class="mr-1">mdi-book-plus</v-icon>创建书籍
                </v-btn>
                <v-btn
                    rounded
                    class="library-large-screen ml-2"
                    color="primary"
                    @click="importDialog.active = true;"
                >
                    <v-icon class="mr-1">mdi-import</v-icon>导入
                </v-btn>
        </div>

        <v-card
            v-if="openedBook === -1 && books.length > 0"
            id="material-library-filters"
            outlined
            class="border rounded-lg mt-4 pa-4"
        >
            <v-text-field
                v-model="materialSearch"
                append-icon="mdi-magnify"
                label="搜索阅读材料"
                filled
                dense
                hide-details
                single-line
                rounded
            ></v-text-field>
            <v-chip-group
                v-model="materialTypeFilter"
                class="mt-3"
                mandatory
                column
                aria-label="材料分类"
            >
                <v-chip
                    v-for="type in availableMaterialTypes"
                    :key="type.value"
                    :value="type.value"
                    filter
                >
                    {{ type.label }} ({{ type.count }})
                </v-chip>
            </v-chip-group>
        </v-card>

        <!-- Book list table -->
        <book-list-table
            v-if="openedBook === -1 && layout === 'table'"
            :books="filteredBooks"
            @show-edit-book-dialog="showEditBookDialog"
            @show-delete-book-dialog="showDeleteBookDialog"
            @open-book="openBook"
        />

        <!-- Book list detailed -->
        <book-list-detailed
            v-if="openedBook === -1 && layout === 'detailed'"
            :books="filteredBooks"
            @show-edit-book-dialog="showEditBookDialog"
            @show-delete-book-dialog="showDeleteBookDialog"
            @open-book="openBook"
        />

        <!-- Book list cover only -->
        <book-list-cover-only
            v-if="openedBook === -1 && layout === 'cover-only'"
            :books="filteredBooks"
            @open-book="openBook"
        />

        <book
            v-if="openedBook !== -1"
            :book="books[openedBook]"
            @show-edit-book-dialog="showEditBookDialog"
            @show-delete-book-dialog="showDeleteBookDialog"
            @close-book="closeBook"
        />
    </v-container>
</template>

<script>
    import {formatNumber} from './../../helper.js';
    import { DefaultLocalStorageManager } from './../../services/LocalStorageManagerService';
    import { requestErrorMessage } from './../../services/UiTextService';
    export default {
        data: function() {
            return {
                layout: DefaultLocalStorageManager.loadSetting('library-layout') || 'table',
                theme: DefaultLocalStorageManager.loadSetting('theme') || 'light',
                books: [],
                materialSearch: '',
                materialTypeFilter: 'all',
                materialTypes: [
                    {value: 'cet4', label: '四级真题'},
                    {value: 'cet6', label: '六级真题'},
                    {value: 'postgraduate_exam', label: '考研真题'},
                    {value: 'personal', label: '我的材料'},
                ],
                openedBook: -1,
                errorDialog: {
                    active: false,
                    content: '',
                },
                importDialog: {
                    active: false,
                },
                editBookDialog: {
                    active: false,
                    bookId: -1
                },
                deleteBookDialog: {
                    active: false,
                    bookId: -1,
                    bookName: '',
                },
            }
        },
        props: {
            language: String
        },
        computed: {
            availableMaterialTypes() {
                const types = this.materialTypes.filter((type) => {
                    return this.books.some((book) => book.material_type === type.value);
                }).map((type) => ({
                    ...type,
                    count: this.books.filter((book) => book.material_type === type.value).length,
                }));

                return [{value: 'all', label: '全部', count: this.books.length}, ...types];
            },
            filteredBooks() {
                const search = this.materialSearch.trim().toLocaleLowerCase();

                return this.books.filter((book) => {
                    if (this.materialTypeFilter !== 'all' && book.material_type !== this.materialTypeFilter) {
                        return false;
                    }

                    const type = this.materialTypes.find((candidate) => candidate.value === book.material_type);
                    const searchableText = [
                        book.name,
                        type ? type.label : '',
                        book.exam_year,
                        book.exam_set,
                    ].filter((value) => value !== null && value !== undefined).join(' ').toLocaleLowerCase();

                    return searchableText.includes(search);
                });
            },
        },
        mounted() {
            this.loadBooks();
        },
        methods: {
            loadBookWordCounts(index) {
                this.books[index].wordCountLoading = true;
                this.books[index].wordCount = null;

                axios.get('/books/get-word-counts/' + this.books[index].id).then((response) => {
                    if (response.data !== 'error') {
                        this.books[index].wordCount = response.data;
                    }
                }).catch((error) => {
                    this.errorDialog.content = requestErrorMessage(error, '词数加载失败。');
                    this.errorDialog.active = true;
                }).finally(() => {
                    this.books[index].wordCountLoading = false;
                });
            },
            showEditBookDialog(book = null) {
                this.editBookDialog.active = true;
                if (book === null) {
                    this.editBookDialog.bookId = -1;
                    this.editBookDialog.bookCover = null;
                    this.editBookDialog.bookName = '';
                } else {
                    this.editBookDialog.bookId = book.id;
                    this.editBookDialog.bookCover = book.cover_image;
                    this.editBookDialog.bookName = book.name;
                }
            },
            showDeleteBookDialog(book) {
                this.deleteBookDialog.active = true;
                this.deleteBookDialog.bookId = book.id;
                this.deleteBookDialog.bookName = book.name;
            },
            deleteBook() {
                axios.post('/books/delete', {
                    'bookId': this.deleteBookDialog.bookId,
                }).then((response) => {
                    if (response.status === 200) {
                        this.loadBooks();
                    } else {
                        this.errorDialog.content = '删除书籍失败。';
                        this.errorDialog.active = true;
                    }
                }).catch((e) => {
                    this.errorDialog.content = requestErrorMessage(e, '删除书籍失败。');
                    this.errorDialog.active = true;
                });
            },
            openBook(bookId) {
                var bookIndex = -1;
                for (let i = 0; i < this.books.length; i++) {
                    if (this.books[i].id === bookId) {
                        bookIndex = i;
                        break;
                    }
                }

                this.openedBook = bookIndex;

                // update url
                if (this.$router.currentRoute.fullPath !== ('/books/' + this.books[bookIndex].id)) {
                    this.$router.push('/books/' + this.books[bookIndex].id);
                }
            },
            closeBook() {
                this.openedBook = -1;

                // update url
                if (this.$router.currentRoute.fullPath !== ('/books')) {
                    this.$router.push('/books');
                }
            },
            importFinished() {
                this.loadBooks();
            },
            loadBooks() {
                axios.post('/books').then((response) => {
                    this.openedBook = -1;
                    for (let bookIndex = 0; bookIndex < response.data.length; bookIndex ++) {
                        response.data[bookIndex].chaptersVisible = false;
                        response.data[bookIndex].wordCountLoading = false;
                    }

                    this.books = response.data;

                    if (!this.availableMaterialTypes.some((type) => type.value === this.materialTypeFilter)) {
                        this.materialTypeFilter = 'all';
                    }

                    // open book from url param
                    if (this.$route.params.bookId !== undefined) {
                        this.$nextTick(() => {
                            this.openBook(parseInt(this.$route.params.bookId));
                        });
                    }
                }).catch((error) => {
                    this.books = [];
                    this.errorDialog.content = requestErrorMessage(error, '阅读材料加载失败。');
                    this.errorDialog.active = true;
                });
            },
            setLayout(newLayout) {
                this.layout = newLayout;
                DefaultLocalStorageManager.saveSetting('library-layout', newLayout);
            },
            formatNumber: formatNumber
        }
    }
</script>
