<template>
    <v-dialog v-model="value" persistent width="400px" height="500px">
        <v-card class="rounded-lg">
            <!-- Title -->
            <v-card-title>
                <v-icon class="mr-1">mdi-keyboard-outline</v-icon>复习快捷键
                <v-spacer></v-spacer>
                <v-btn icon @click="close">
                    <v-icon>mdi-close</v-icon>
                </v-btn>
            </v-card-title>
            
            <!-- hotkey info -->
            <v-card-text class="px-8">
                <div class="d-flex">
                    <span class="primary--text mr-6" style="width: 80px;">Enter</span><span>显示答案</span>
                </div>
                
                <div class="d-flex">
                    <span class="primary--text mr-6" style="width: 80px;">1</span><span>忘了</span>
                </div>

                <div class="d-flex">
                    <span class="primary--text mr-6" style="width: 80px;">2</span><span>勉强记得</span>
                </div>

                <div class="d-flex">
                    <span class="primary--text mr-6" style="width: 80px;">3</span><span>记得</span>
                </div>

                <div class="d-flex">
                    <span class="primary--text mr-6" style="width: 80px;">4</span><span>很熟</span>
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
