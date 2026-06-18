<template>
    <v-dialog content-class="language-selection-dialog" v-model="value" scrollable persistent>
        <v-card class="rounded-lg" :loading="loading">
            <v-card-title>
                <span class="text-h5">学习语言</span>
                <v-spacer></v-spacer>
                <v-btn icon @click="close">
                    <v-icon>mdi-close</v-icon>
                </v-btn>
            </v-card-title>
            <v-card-text>
                <v-alert
                    v-if="error"
                    dense
                    outlined
                    type="error"
                    class="rounded-lg mt-2"
                >
                    {{ error }}
                </v-alert>

                <v-alert
                    v-if="notInstalledLanguages"
                    dense
                    class="rounded-lg mt-2"
                    color="primary"
                    border="left"
                    dark
                >
                    <v-row align="center">
                        <v-col class="grow">
                            <template v-if="notInstalledLanguages === 1">
                                还有 1 种学习语言可以安装。
                            </template>

                            <template v-else>
                                还有 {{ notInstalledLanguages }} 种学习语言可以安装。
                            </template>

                            <template v-if="!$store.getters['shared/userAdmin']">
                                只有管理员可以安装学习语言。
                            </template>
                        </v-col>
                        <v-col class="shrink" v-if="$store.getters['shared/userAdmin']">
                            <v-btn outlined depressed rounded color="foreground" @click="manageLanguages">
                                <v-icon class="mr-1">mdi-cog</v-icon>
                                管理语言
                            </v-btn>
                        </v-col>
                    </v-row>
                </v-alert>

                <!-- List of supported and installed languages -->
                <div id="language-buttons" class="d-flex flex-wrap mt-2" v-if="!loading">
                    <v-btn 
                        v-for="(language, index) in supportedLanguages"
                        rounded
                        depressed
                        :key="index"
                        class="language-button my-1 mx-1" 
                        @click="selectLanguage(language)" 
                    >
                        <v-img 
                            eager
                            class="border" 
                            :src="'/images/flags/' + language.toLowerCase() + '.png'" 
                            max-width="43" 
                            height="28"
                        ></v-img> 
                        <span>{{ languageName(language) }}</span>
                    </v-btn>
                </div>
            </v-card-text>
            <v-card-actions>
                <v-spacer></v-spacer>
                <v-btn rounded text @click="close">取消</v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>
</template>

<script>
    import { languageName, requestErrorMessage } from './../../services/UiTextService';

    export default {
        props: {
            value : Boolean,
        },
        emits: ['input'],
        data: function() {
            return {
                loading: false,
                supportedLanguages: [],
                notInstalledLanguages: 0,
                error: '',
            };
        },
        watch: { 
            value: function(newVal, oldVal) {
                if (newVal) {
                    this.loadLanguages();
                }
            }
        },
        mounted: function() {
        },
        methods: {
            manageLanguages() {
                if (this.$router.currentRoute.fullPath !== '/admin/languages') {
                    this.$router.push('/admin/languages');
                }
                
                this.close();
            },
            loadLanguages() {
                this.loading = true;
                this.notInstalledLanguages = 0;
                this.error = '';

                // get selected and supported languages
                axios.get('/languages/get-language-selection-dialog-data').then((response) => {
                    this.supportedLanguages = response.data.languages;
                    this.notInstalledLanguages = response.data.notInstalledLanguages;
                }).catch((error) => {
                    this.error = requestErrorMessage(error, '学习语言加载失败，请确认已登录且后端服务正在运行。');
                    this.supportedLanguages = ['English'];
                    this.notInstalledLanguages = 0;
                }).finally(() => {
                    this.loading = false;
                });
            },
            selectLanguage(newLanguage) {
                this.loading = true;
                this.error = '';
                var language = newLanguage.toLowerCase();
                axios.get('/languages/select/' + language).then(function (response) {
                    document.location.href = '/';
                }.bind(this)).catch((error) => {
                    this.error = requestErrorMessage(error, '学习语言切换失败。');
                }).finally(() => {
                    this.loading = false;
                });
            },
            languageName,
            close() {
                this.$emit('input', false);
            }
        }
    }
</script>
