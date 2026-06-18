<template>
    <div>
        <v-form ref="importFileForm" v-model="isFormValid">
            <v-alert dark border="left" type="info" color="primary" class="mb-8">
                请确认 .epub 文件没有 DRM 保护。LinguaCafe 无法读取受 DRM 保护的文件。
            </v-alert>
            <label class="font-weight-bold">电子书文件</label>
            <v-file-input
                v-model="ebookFile"
                filled
                dense
                rounded
                persistent-hint
                hint="支持格式：.epub"
                ref="ebookFile"
                accept=".epub"
                placeholder="电子书文件"
                prepend-icon="mdi-book"
                :rules="[rules.ebookFileRule]"
                @change="selectImportFile"
            ></v-file-input>
        </v-form>
    </div>
</template>

<script>
    export default {
        data: function() {
            return {
                ebookFile: null,
                isFormValid: false,
                rules: {
                    ebookFileRule: (value) => {
                        if (value === null || value === undefined) {
                            return '请选择文件。';
                        }
                        
                        let extension = value.name.split('.');
                        extension = extension[extension.length - 1];
                        if (extension !== 'epub') {
                            return '请选择 .epub 文件。';
                        }

                        return true;
                    }
                }
            }
        },
        props: {
        },
        mounted() {
        },
        methods: {
            selectImportFile() {
                this.$emit('file-selected', {
                    importFile: this.ebookFile,
                    isImportSourceValid: this.$refs.importFileForm.validate()
                });
            }
        }
    }
</script>
