<template>
    <!-- Book list detailed -->
    <div id="book-list" class="cover-only d-flex flex-row flex-wrap justify-start">
        <v-card
            outlined
            :id="'book-' + book.id"
            class="book cover-only rounded-lg mr-3 mb-3"
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
                        @click="openBook(book.id)"
                    />
                    <div
                        v-else
                        class="cover-image no-cover p-2 h-100 text-center align-middle"
                        @click="openBook(book.id)">
                        {{book.name}}
                    </div>
                </div>
                <div class="px-2 py-2">
                    <template v-if="book.readingProgress && book.readingProgress.available">
                        <div class="d-flex align-center text-caption font-weight-medium mb-1">
                            <span>阅读</span>
                            <v-spacer></v-spacer>
                            <span>{{ Number(book.readingProgress.percentage).toFixed(1) }}%</span>
                        </div>
                        <v-progress-linear
                            :value="book.readingProgress.percentage"
                            color="primary"
                            height="6"
                            rounded
                            :aria-label="book.name + '阅读进度'"
                        ></v-progress-linear>
                    </template>
                    <div v-else class="text-caption text--secondary">暂无进度</div>
                </div>
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
        openBook(bookId) {
            this.$emit('open-book', bookId);
        },
        formatNumber: formatNumber
    }
}
</script>
