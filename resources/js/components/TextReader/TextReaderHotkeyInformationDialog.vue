<template>
    <v-dialog v-model="value" persistent scrollable width="460px" >
        <v-card id="text-reader-hotkeys" class="rounded-lg" height="500px">
            <!-- Title -->
            <v-card-title>
                <v-icon class="mr-1">mdi-keyboard-outline</v-icon>阅读快捷键
                <v-spacer></v-spacer>
                <v-btn icon @click="close">
                    <v-icon>mdi-close</v-icon>
                </v-btn>
            </v-card-title>
            
            <!-- hotkey info -->
            <v-card-text class="px-8">
                <label class="font-weight-bold mt-2 mb-0">滚动</label>
                <div class="hotkey d-flex mb-2">
                    滚动时按住 Ctrl 或 Shift 可以增大滚动幅度。
                </div>
                <div class="hotkey d-flex">
                    <span class="primary--text" style="width: 170px;">Up arrow</span><span>向上滚动</span>
                </div>
                <div class="hotkey d-flex">
                    <span class="primary--text" style="width: 170px;">Down arrow</span><span>向下滚动</span>
                </div>
                <div class="hotkey d-flex">
                    <span class="primary--text" style="width: 170px;">w</span><span>向上滚动</span>
                </div>
                <div class="hotkey d-flex">
                    <span class="primary--text" style="width: 170px;">s</span><span>向下滚动</span>
                </div>

                <label class="font-weight-bold mt-4 mb-0">文本</label>
                <div class="hotkey d-flex">
                    <span class="primary--text" style="width: 170px;">i</span><span>减小字号</span>
                </div>
                <div class="hotkey d-flex">
                    <span class="primary--text" style="width: 170px;">o</span><span>增大字号</span>
                </div>
                <div class="hotkey d-flex">
                    <span class="primary--text" style="width: 170px;">p</span><span>切换纯文本模式</span>
                </div>

                <label class="font-weight-bold mt-4 mb-0">设置等级</label>
                <div class="hotkey d-flex">
                    <span class="primary--text" style="width: 170px;">x</span><span>设为已忽略</span>
                </div>
                <div class="hotkey d-flex">
                    <span class="primary--text" style="width: 170px;">c</span><span>设为新词</span>
                </div>
                <div class="hotkey d-flex">
                    <span class="primary--text" style="width: 170px;">0</span><span>设为已知</span>
                </div>
                <div class="hotkey d-flex">
                    <span class="primary--text" style="width: 170px;">1</span><span>设为等级 1</span>
                </div>
                <div class="hotkey d-flex">
                    <span class="primary--text" style="width: 170px;">2</span><span>设为等级 2</span>
                </div>
                <div class="hotkey d-flex">
                    <span class="primary--text" style="width: 170px;">3</span><span>设为等级 3</span>
                </div>
                <div class="hotkey d-flex">
                    <span class="primary--text" style="width: 170px;">4</span><span>设为等级 4</span>
                </div>
                <div class="hotkey d-flex">
                    <span class="primary--text" style="width: 170px;">5</span><span>设为等级 5</span>
                </div>
                <div class="hotkey d-flex">
                    <span class="primary--text" style="width: 170px;">6</span><span>设为等级 6</span>
                </div>
                <div class="hotkey d-flex">
                    <span class="primary--text" style="width: 170px;">7</span><span>设为等级 7</span>
                </div>

                <label class="font-weight-bold mt-4 mb-0">选词</label>
                <div class="hotkey d-flex">
                    <span class="primary--text" style="width: 170px;">Esc</span><span>取消选词</span>
                </div>
                <div class="hotkey d-flex">
                    <span class="primary--text" style="width: 170px;">Left arrow</span><span>选择上一个词</span>
                </div>
                <div class="hotkey d-flex">
                    <span class="primary--text" style="width: 170px;">Right arrow</span><span>选择下一个词</span>
                </div>
                <div class="hotkey d-flex">
                    <span class="primary--text" style="width: 170px;">Shift + Left arrow</span><span>选择上一个高亮词</span>
                </div>
                <div class="hotkey d-flex">
                    <span class="primary--text" style="width: 170px;">Shift + Right arrow</span><span>选择下一个高亮词</span>
                </div>
                <div class="hotkey d-flex">
                    <span class="primary--text" style="width: 170px;">Ctrl + Left arrow</span><span>选择上一个新词</span>
                </div>
                <div class="hotkey d-flex">
                    <span class="primary--text" style="width: 170px;">Ctrl + Right arrow</span><span>选择下一个新词</span>
                </div>
                <div class="hotkey d-flex">
                    <span class="primary--text" style="width: 170px;">a</span><span>选择上一个词</span>
                </div>
                <div class="hotkey d-flex">
                    <span class="primary--text" style="width: 170px;">d</span><span>选择下一个词</span>
                </div>
                <div class="hotkey d-flex">
                    <span class="primary--text" style="width: 170px;">Shift + a</span><span>选择上一个高亮词</span>
                </div>
                <div class="hotkey d-flex">
                    <span class="primary--text" style="width: 170px;">Shift + d</span><span>选择下一个高亮词</span>
                </div>
                <div class="hotkey d-flex">
                    <span class="primary--text" style="width: 170px;">Ctrl + a</span><span>选择上一个新词</span>
                </div>
                <div class="hotkey d-flex">
                    <span class="primary--text" style="width: 170px;">Ctrl + d</span><span>选择下一个新词</span>
                </div>

                <label class="font-weight-bold mt-4 mb-0">其他</label>
                <div class="hotkey d-flex">
                    <span class="primary--text" style="width: 170px;">f</span><span>发送到 Anki</span>
                </div>
                <div class="hotkey d-flex">
                    <span class="primary--text" style="width: 170px;">v</span><span>朗读</span>
                </div>

            </v-card-text>
            
            <!-- Close button -->
            <v-card-actions>
                <v-spacer></v-spacer>
                <v-btn rounded text @click="close">关闭</v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>
</template>

<script>
    export default {
        props: {
            value : Boolean,
        },
        emits: ['input'],
        data: function() {
            return {
            };
        },
        mounted: function() {
        },
        methods: {
            close() {
                this.$emit('input', false);
            }
        }
    }
</script>
