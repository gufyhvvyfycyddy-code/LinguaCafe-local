<template>
    <div class="d-flex flex-column align-stretch">
        <!-- Text input -->
        <label class="font-weight-bold">文本</label>
        <v-textarea
            id="plain-text-import-input"
            v-model="text"
            filled
            dense
            rounded
            persistent-hint
            hint="可以在这里粘贴或输入任意文本"
            placeholder="文本"
            maxlength="250000"
            counter="250000"
            no-resize
            @input="textChanged"
            @keyup="textChanged"
        ></v-textarea>
    </div>
</template>

<script>
    export default {
        data: function() {
            return {
                text: ''
            }
        },
        props: {
            language: String,
        },
        mounted() {
        },
        methods: {
            textChanged() {
                if (this.text.length == 0) {
                    this.$emit('text-selected', {
                        text: '',
                        isImportSourceValid: false
                    });

                    return;
                }

                this.$emit('text-selected', {
                    text: this.text,
                    isImportSourceValid: true
                });
            }
        }
    }
</script>
