<template>
    <v-dialog v-model="value" persistent max-width="500px" height="300px">
        <v-card class="rounded-lg" :loading="installing || selecting">
            <v-card-title>
                <v-icon class="mr-2">mdi-download</v-icon>
                <span class="text-h5">安装语言</span>
                
                <v-spacer></v-spacer>
                <v-btn icon @click="close" :disabled="installing || selecting">
                    <v-icon>mdi-close</v-icon>
                </v-btn>
            </v-card-title>
            
            <v-card-text class="pt-4 pb-6">
                <!-- Install confirmation message -->
                <template v-if="!installing && installResult !== 'success'">
                    确定要安装 {{ $props.language }} 语言吗？这需要联网，并且可能需要几分钟。
                </template>

                <!-- Success message -->
                <v-alert
                    v-if="!installing && installResult === 'success'"
                    dense
                    class="rounded-lg"
                    color="success"
                    type="success"
                    border="left"
                >
                    {{ $props.language }} 已成功安装。
                    <div class="w-full d-flex">
                        <v-spacer />
                        <v-btn 
                            class="d-block mt-6"
                            outlined 
                            depressed 
                            rounded 
                            color="foreground" 
                            :loading="selecting"
                            :disabled="selecting || installing"
                            @click="selectNewLanguage" 
                        >
                            切换到 {{ $props.language }}
                        </v-btn>
                    </div>
                </v-alert>

                <v-alert
                    v-if="selectionError"
                    dense
                    class="rounded-lg mt-4"
                    color="error"
                    type="error"
                    border="left"
                >
                    {{ selectionError }}
                </v-alert>

                <!-- Error message -->
                <v-alert
                    v-if="!installing && installResult === 'error'"
                    dense
                    class="rounded-lg mt-4"
                    color="error"
                    type="error"
                    border="left"
                >
                    安装语言时发生错误。
                </v-alert>

                <!-- Installation in progress message -->
                <template v-if="installing">
                    正在安装 {{ $props.language }}，可能需要几分钟...
                </template>
            </v-card-text>

            <v-card-actions>
                <v-spacer></v-spacer>

                <!-- Cancel button -->
                <v-btn rounded text @click="close" :disabled="installing || selecting" v-if="installResult !== 'success'">
                    取消
                </v-btn>
                
                <!-- Close button -->
                <v-btn rounded text @click="close" :disabled="installing || selecting" v-if="installResult === 'success'">
                    关闭
                </v-btn>
                
                <!-- Install button -->
                <v-btn rounded text @click="install" :disabled="installing || selecting" v-if="installResult !== 'success'">
                    <v-icon class="mr-1">mdi-download</v-icon>
                    安装
                </v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>
</template>

<script>
    import { requestErrorMessage } from './../../services/UiTextService';

    export default {
        props: {
            value : Boolean,
            language: String,
        },
        emits: ['input'],
        data: function() {
            return {
                installResult: '',
                installing: false,
                selecting: false,
                selectionError: '',
            };
        },
        mounted: function() {
        },
        methods: {
            install() {
                this.installing = true;
                axios.post('/languages/install', {
                    language: this.$props.language,
                }).then((response) => {
                    this.installing = false;
                    if (response.status === 200) {
                        this.installResult = 'success';
                        this.$emit('language-installed');
                    } else {
                        this.installResult = 'error';
                    }
                }).catch((error) => {
                    this.installing = false;
                    this.installResult = 'error';
                });
            },
            selectNewLanguage() {
                if (this.selecting) {
                    return;
                }

                var language = this.$props.language.toLowerCase();
                this.selecting = true;
                this.selectionError = '';

                axios.put('/languages/select/' + language).then(function (response) {
                    document.location.href = '/admin/languages';
                }.bind(this)).catch((error) => {
                    if (error?.response?.status === 419) {
                        this.selectionError = '页面验证已过期，请刷新页面后重试。';
                    } else if (error?.response?.status === 503) {
                        this.selectionError = '数据库正在恢复，暂时无法切换学习语言。';
                    } else {
                        this.selectionError = requestErrorMessage(error, '学习语言切换失败，请稍后重试。');
                    }
                }).finally(() => {
                    this.selecting = false;
                });
            },
            close() {
                this.installResult = '';
                this.installing = false;
                this.selecting = false;
                this.selectionError = '';
                this.$emit('input', false);
            }
        }
    }
</script>
