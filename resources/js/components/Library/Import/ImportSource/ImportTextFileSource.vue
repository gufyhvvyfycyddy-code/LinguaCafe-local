<template>
    <div class="d-flex flex-column align-stretch">
        <!-- Text file -->
        <label class="font-weight-bold">文本文件</label>
        <v-file-input
            v-model="textFile"
            filled
            dense
            rounded
            persistent-hint
            hint="支持格式：.txt"
            ref="textFile"
            accept=".txt"
            placeholder="文本文件"
            prepend-icon="mdi-book"
            :rules="[rules.textFileRule]"
            @change="textFileSelected"
        ></v-file-input>

    </div>
</template>

<script>
    export default {
        data: function() {
            return {
                text: '',
                textFile: null,
                isFormValid: false,
                rules: {
                    textFileRule: (value) => {
                        if (value === null || value === undefined) {
                            return '请选择文件。';
                        }
                        
                        let extension = value.name.split('.');
                        extension = extension[extension.length - 1];
                        if (extension !== 'txt') {
                            return '请选择 .txt 文件。';
                        }

                        return true;
                    }
                }
            }
        },
        props: {
            language: String,
        },
        mounted() {
        },
        methods: {
            textFileSelected() {
                // validate
                this.text = '';
                if (!this.$refs.textFile.validate()) {
                    // disable continue button in import dialog
                    this.$emit('text-selected', {
                        text: '',
                        isImportSourceValid: false
                    });

                    return;
                }

                // read file
                var reader = new FileReader();
                reader.readAsText(this.textFile);
                reader.onload = () => {
                    this.text = reader.result;

                    this.$emit('text-selected', {
                        text: this.text,
                        isImportSourceValid: true
                    });
                };
                reader.onerror = () => {
                    this.text = '';
                    this.$emit('text-selected', {
                        text: '',
                        isImportSourceValid: false
                    });
                };
            }
        }
    }
</script>
