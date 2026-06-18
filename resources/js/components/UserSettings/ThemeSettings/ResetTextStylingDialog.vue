<template>
    <v-dialog v-model="value" persistent max-width="500px">
        <v-card class="rounded-lg">
            <v-card-title>
                <span class="text-h5">重置文本样式</span>
                <v-spacer></v-spacer>
                <v-btn icon @click="close">
                    <v-icon>mdi-close</v-icon>
                </v-btn>
            </v-card-title>
            <v-card-text>
                你可以把当前词汇状态或整个主题的文本样式重置为默认值。
                <div class="mt-2">重置后的设置不会自动保存。</div>
                <v-radio-group
                    v-model="resetType"
                    class="mt-4"
                >
                    <v-radio
                        label="当前词汇等级"
                        value="level"
                    ></v-radio>
                    <v-radio
                        label="当前主题"
                        value="theme"
                    ></v-radio>
                </v-radio-group>
            </v-card-text>
            <v-card-actions>
                <v-spacer></v-spacer>
                <v-btn rounded text @click="close">取消</v-btn>
                <v-btn rounded depressed color="primary" @click="reset">重置</v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>
</template>

<script>
    export default {
        props: {
            value : Boolean
        },
        emits: ['input', 'reset'],
        data: function() {
            return {
                resetType: 'level'
            };
        },
        mounted: function() {
        },
        methods: {
            reset() {
                this.$emit('reset', this.resetType)
                this.close()
            },
            close() {
                this.$emit('input', false);
            }
        }
    }
</script>
