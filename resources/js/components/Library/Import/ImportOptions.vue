<template>
    <div>
        <v-form ref="importOptionsForm" v-model="isFormValid">
            <!-- E-book chapter order -->
            <label class="font-weight-bold mt-2" v-if="$props.type === 'e-book'">
                电子书章节顺序
                <v-menu offset-y nudge-top="-12px">
                    <template v-slot:activator="{ on, attrs }">
                        <v-icon class="ml-1" v-bind="attrs" v-on="on">mdi-help-circle-outline</v-icon>
                    </template>
                    <v-card outlined class="rounded-lg pa-4" width="320px">
                        少数电子书导入后章节顺序可能不正确。如果遇到这种情况，请删除导入的书籍，并用 Spine 选项重新导入。
                    </v-card>
                </v-menu>
            </label>

            <v-radio-group
                v-if="$props.type === 'e-book'"
                v-model="eBookChapterSortMethod"
                @change="importOptionsChanged"
                class="mt-0"
            >
                <v-radio
                    value="default"
                >
                    <template v-slot:label>
                        <div>默认</div>
                    </template>
                </v-radio>
                <v-radio
                    value="spine"
                >
                    <template v-slot:label>
                        <div>Spine 顺序</div>
                    </template>
                </v-radio>
            </v-radio-group>

            <!-- Text processing method label -->
            <label class="font-weight-bold mt-2">每章最大字符数</label>
            <v-text-field 
                v-model="maximumCharactersPerChapter"
                ref="maximumCharactersPerChapterInput"
                filled
                dense
                rounded
                min="200"
                max="20000"
                type="Number"
                @keyup="importOptionsChanged"
                @click="importOptionsChanged"
                :rules="[rules.maximumCharactersPerChapter]"
            ></v-text-field>

            <v-alert dark border="left" color="warning" type="error" v-if="maximumCharactersPerChapter > defaultMaximumCharactersPerChapter">
                章节过大可能导致页面性能问题，建议使用默认值。
            </v-alert>
        </v-form>
    </div>
</template>

<script>
    export default {
        data: function() {
            return {
                eBookChapterSortMethod: 'default',
                isFormValid: false,
                maximumCharactersPerChapter: (this.$props.language == 'chinese' || this.$props.language == 'japanese') ? 1500 : 3000,
                defaultMaximumCharactersPerChapter: (this.$props.language == 'chinese' || this.$props.language == 'japanese') ? 1500 : 3000,

                rules: {
                    maximumCharactersPerChapter: value => {
                        if (value < 300) {
                            return '至少需要 300 个字符。';
                        }

                        if (value > 15000) {
                            return '不能超过 15000 个字符。';
                        }

                        return true;
                    },
                }
            }
        },
        props: {
            language: String,
            type: String,
        },
        mounted() {
            this.importOptionsChanged();
            this.$refs.maximumCharactersPerChapterInput.focus();
        },
        methods: {
            importOptionsChanged() {
                var valid = this.$refs.importOptionsForm.validate();
                this.$emit('import-options-changed', {
                    maximumCharactersPerChapter: this.maximumCharactersPerChapter,
                    eBookChapterSortMethod: this.eBookChapterSortMethod,
                    isValid: valid
                });
            }
        }
    }
</script>
