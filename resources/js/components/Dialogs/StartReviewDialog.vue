<template>
    <v-dialog v-model="value" persistent max-width="500px">
        <v-card class="rounded-lg">
            <v-card-title>
                <span class="text-h5">单词复习</span>
                <v-spacer></v-spacer>
                <v-btn icon @click="close">
                    <v-icon>mdi-close</v-icon>
                </v-btn>
            </v-card-title>
            <v-card-text>
                <!-- Book name -->
                <span v-if="bookName !== ''">书籍：{{ bookName }}</span><br>
                
                <!-- Chapter name -->
                <span v-if="chapterName !== ''">章节：{{ chapterName }}</span>

                <!-- Reviewing all words info -->
                <span v-if="bookName === '' && chapterName === ''">
                    复习所有阅读材料中的到期卡片。
                </span>
            </v-card-text>
            <v-card-actions class="mt-8">
                
                <!-- Practice mode -->
                <v-checkbox class="mt-0 pt-0" hide-details v-model="practiceMode" label="练习模式"></v-checkbox>
                <v-menu offset-y left nudge-top="-12px" nudge-left="-88px">
                    <template v-slot:activator="{ on, attrs }">
                        <v-icon class="ml-2" v-bind="attrs" v-on="on">mdi-help-circle-outline</v-icon>
                    </template>
                    <v-card outlined class="rounded-lg pa-4" width="252px">
                        <span class="mb-1">练习模式下：</span>
                        <ul class="mb-0">
                            <li>
                                单词和短语的复习到期时间不会改变。
                            </li>
                            <li>
                                单词和短语的等级不会改变。
                            </li>
                            <li>
                                本次复习不计入每日复习目标。
                            </li>
                            <li>
                                也会出现尚未到期的卡片。
                            </li>
                        </ul>
                    </v-card>
                </v-menu>

                <v-spacer></v-spacer>
                <v-btn rounded text @click="close">取消</v-btn>
                <v-btn rounded text @click="startReview">开始复习</v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>
</template>

<script>
    export default {
        props: {
            value : Boolean,
            bookId: {
                type: Number,
                default: -1
            },
            bookName: {
                type: String,
                default: ''
            },
            chapterId: {
                type: Number,
                default: -1
            },
            chapterName: {
                type: String,
                default: ''
            }
        },
        watch: { 
            // reset practice mode when dialog opens
            value: function(newValue) {
                if (newValue) {
                    this.practiceMode = false;
                }
            }
        },
        emits: ['input'],
        data: function() {
            return {
                practiceMode: false,
            };
        },
        mounted: function() {
        },
        methods: {
            startReview() {
                window.location.href = '/review/' + this.practiceMode + '/' + this.$props.bookId + '/' + this.$props.chapterId;
                this.$emit('input', false);
            },
            close() {
                this.$emit('input', false);
            }
        }
    }
</script>
