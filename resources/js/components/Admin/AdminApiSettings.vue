<template>
    <div id="admin-api-settings" v-if="settings">
        <v-form v-model="isFormValid">
            <!-- DeepL settings -->
            <div class="subheader mt-4">DeepL</div>
            <v-card outlined class="rounded-lg pa-4 pt-0" :loading="characterLimitLoading">
                <v-card-text id="deepl-card-text">
                    <!-- DeepL cache -->
                    <label class="font-weight-bold"">
                        DeepL Cache
                    </label>
                    
                    <div v-if="characterLimitLoading">
                        <v-skeleton-loader
                            id="skeleton-cached-translations"
                            class="regular-list-height d-block skeleton rounded-pill mt-1"
                            type="image"
                        ></v-skeleton-loader>
                    </div>
                    
                    <div v-if="!characterLimitLoading">
                        {{ formatNumber(cachedDeeplTranslations).replace('&nbsp;', '') }} cached translations.
                    </div>

                    <!-- DeepL host input -->
                    <label class="font-weight-bold mt-4">DeepL host</label>
                    <v-text-field 
                        v-model="settings.deeplHost"
                        class="mb-4"
                        filled
                        dense
                        rounded
                        placeholder="DeepL host"
                        :disabled="saving || characterLimitLoading"
                        :rules="[rules.notEmpty]"
                    ></v-text-field>

                    <!-- DeepL API key input -->
                    <label class="font-weight-bold mt-4">DeepL API key</label>
                    <v-text-field 
                        v-model="settings.deeplApiKey"
                        class="mb-4"
                        filled
                        dense
                        rounded
                        placeholder="DeepL API key"
                        :disabled="saving || characterLimitLoading"
                        :rules="[rules.notEmpty]"
                    ></v-text-field>

                    <!-- DeepL API usage -->
                    <label 
                        v-if="!['error', 'default'].includes(characterLimitStatus)"
                        class="font-weight-bold mt-4" 
                    >
                        DeepL character usage
                    </label>

                    <v-card id="deepl-api-card" class="rounded-lg pa-6" elevation="0" v-if="!['error', 'default'].includes(characterLimitStatus)">
                        <!-- DeepL API usage skeleton -->
                        <v-card-text class="pa-0" v-if="characterLimitLoading">
                            <v-skeleton-loader
                                id="skeleton-used-characters"
                                class="regular-list-height d-block skeleton rounded-pill mt-2 mb-3"
                                type="image"
                            ></v-skeleton-loader>

                            <v-skeleton-loader
                                id="skeleton-max-characters"
                                class="regular-list-height d-block skeleton rounded-pill"
                                type="image"
                            ></v-skeleton-loader>

                            <v-skeleton-loader
                                id="skeleton-progress-bar"
                                class="regular-list-height d-block skeleton rounded-pill mt-6 mb-2"
                                type="image"
                            ></v-skeleton-loader>

                            <v-skeleton-loader
                                id="skeleton-characters-left"
                                class="regular-list-height d-block skeleton rounded-pill mt-1"
                                type="image"
                            ></v-skeleton-loader>
                        </v-card-text>

                        <!-- DeepL API usage loaded -->
                        <v-card-text class="pa-0" v-if="!characterLimitLoading">
                            <div class="font-weight-bold mt-2 mb-3" style="font-size: 36px;">
                                {{ formatNumber(characterUsed).replace('&nbsp;', '') }}
                            </div>
                            
                            <div>Out of max.{{ formatNumber(characterLimit) }} characters</div>
                            
                            <v-progress-linear
                                color="primary"
                                height="36"
                                :value="this.characterUsed / this.characterLimit * 100"
                                class="rounded-pill mt-6 mb-2"
                            >
                                <strong></strong>
                            </v-progress-linear>
                            {{ formatNumber(characterLimit - characterUsed).replace('&nbsp;', '') }} characters left
                        </v-card-text>
                    </v-card>

                    <!-- DeepL API key error message -->
                    <v-alert
                        v-if="!saving && characterLimitStatus == 'error'"
                        class="rounded-lg mt-2"
                        color="error"
                        type="error"
                        border="left"
                        dark
                    >
                        DeepL API call failed. Please make sure that your API key is valid and DeepL services are online.
                    </v-alert>
                </v-card-text>
            </v-card>

            <!-- LibreTranslate settings -->
            <div class="subheader subheader-margin-top">LibreTranslate</div>
            <v-card outlined class="rounded-lg pa-4 pt-0">
                <v-card-text id="jellyfin-card-text">
                    <label class="font-weight-bold">LibreTranslate host</label>
                    <v-text-field 
                        v-model="settings.libreTranslateHost"
                        filled
                        dense
                        rounded
                        hide-details
                        placeholder="LibreTranslate host"
                        :disabled="saving || characterLimitLoading"
                        :rules="[rules.notEmpty]"
                    ></v-text-field>
                </v-card-text>
            </v-card>

            <!-- Anki connect settings -->
            <div class="subheader subheader-margin-top">Anki</div>
            <v-card outlined class="rounded-lg pa-4 pt-0">
                <v-card-text id="jellyfin-card-text">
                    <label class="font-weight-bold">Anki-connect host</label>
                    <v-text-field 
                        v-model="settings.ankiConnectHost"
                        filled
                        dense
                        rounded
                        placeholder="Anki-connect host"
                        :disabled="saving || characterLimitLoading"
                        :rules="[rules.notEmpty]"
                    ></v-text-field>

                    <!-- Auto add cards label -->
                    <label class="font-weight-bold mt-4 mb-0">
                        阅读时自动添加卡片
                        
                        <!-- Auto add cards info box -->
                        <v-menu offset-y nudge-top="-12px">
                            <template v-slot:activator="{ on, attrs }">
                                <v-icon class="ml-1" v-bind="attrs" v-on="on">mdi-help-circle-outline</v-icon>
                            </template>
                            <v-card outlined class="rounded-lg pa-4" width="320px">
                                当你高亮单词或短语（将等级设为 1-7）时，它们会自动发送到 Anki。
                            </v-card>
                        </v-menu>
                    </label>

                    <!-- Auto add cards input -->
                    <v-switch
                        v-model="settings.ankiAutoAddCards"
                        class="mt-0"
                        color="primary"
                        hide-hints
                        dense
                        label="自动添加卡片"
                    ></v-switch>

                    <!-- Update existing cards label -->
                    <label class="font-weight-bold mt-4 mb-0">
                        更新已有卡片
                        
                        <!-- Update existing cards info box -->
                        <v-menu offset-y nudge-top="-12px">
                            <template v-slot:activator="{ on, attrs }">
                                <v-icon class="ml-1" v-bind="attrs" v-on="on">mdi-help-circle-outline</v-icon>
                            </template>
                            <v-card outlined class="rounded-lg pa-4" width="320px">
                                如果发送到 Anki 的单词已存在，会更新翻译、读音和例句字段。
                            </v-card>
                        </v-menu>
                    </label>
                    
                    <!-- Update existing cards input -->
                    <v-switch
                        v-model="settings.ankiUpdateCards"
                        class="mt-0"
                        color="primary"
                        hide-hints
                        dense
                        label="更新已有卡片"
                    ></v-switch>
                    
                    <!-- Show notifications label -->
                    <label class="font-weight-bold mt-4 mb-0">
                        显示通知
                        
                        <!-- Show notifications info box -->
                        <v-menu offset-y nudge-top="-12px">
                            <template v-slot:activator="{ on, attrs }">
                                <v-icon class="ml-1" v-bind="attrs" v-on="on">mdi-help-circle-outline</v-icon>
                            </template>
                            <v-card outlined class="rounded-lg pa-4" width="320px">
                                单词或短语发送到 Anki 后，屏幕上会显示成功或错误通知。
                            </v-card>
                        </v-menu>
                    </label>
                    
                    <!-- Show notifications input -->
                    <v-switch
                        v-model="settings.ankiShowNotifications"
                        class="mt-0"
                        color="primary"
                        hide-hints
                        dense
                        label="显示通知"
                    ></v-switch>
                </v-card-text>
            </v-card>

            <!-- Jellyfin settings -->
            <div class="subheader subheader-margin-top">Jellyfin</div>
            <v-card outlined class="rounded-lg pa-4 pt-0">
                <v-card-text id="jellyfin-card-text">
                    <label class="font-weight-bold mt-4 mb-0">
                        启用 Jellyfin

                        <v-menu offset-y nudge-top="-12px">
                            <template v-slot:activator="{ on, attrs }">
                                <v-icon class="ml-1" v-bind="attrs" v-on="on">mdi-help-circle-outline</v-icon>
                            </template>
                            <v-card outlined class="rounded-lg pa-4" width="320px">
                            如果 LinguaCafe 面向多用户托管，可能需要关闭 Jellyfin。
                            </v-card>
                        </v-menu>
                    </label>

                    <v-switch
                        v-model="settings.jellyfinEnabled"
                        class="mt-0"
                        color="primary"
                        hide-hints
                        dense
                        label="启用 Jellyfin"
                        :disabled="saving || characterLimitLoading"
                    ></v-switch>

                    <label class="font-weight-bold">Jellyfin 主机地址</label>
                    <v-text-field
                        v-model="settings.jellyfinHost"
                        filled
                        dense
                        rounded
                        placeholder="Jellyfin 主机地址"
                        :disabled="saving || characterLimitLoading"
                        :rules="[rules.notEmpty]"
                    ></v-text-field>

                    <label class="font-weight-bold mt-4">Jellyfin API key</label>
                    <v-text-field 
                        v-model="settings.jellyfinApiKey"
                        filled
                        dense
                        rounded
                        placeholder="Jellyfin API key"
                        :disabled="saving || characterLimitLoading"
                        :rules="[rules.notEmpty]"
                    ></v-text-field>
                </v-card-text>
            </v-card>

            <!-- Save result alerts -->
            <v-alert
                v-if="!saving && saveStatus !== '' && saveStatus !== 'success'"
                class="rounded-lg my-3"
                color="error"
                type="error"
                border="left"
                dark
            >
                    保存 API 设置时发生错误。
            </v-alert>

            <!-- Save button -->
            <div class="d-flex">
                <v-spacer />
                <v-btn 
                    rounded 
                    :class="{'my-2': saving || saveStatus == '' || saveStatus == 'success'}"
                    color="primary"
                    @click="saveSettings"
                    :disabled="saving || characterLimitLoading || !isFormValid"
                    :loading="saving || characterLimitLoading"
                >
                    保存设置
                </v-btn>
            </div>
        </v-form>
    </div>
</template>

<script>
    import {formatNumber} from './../../helper.js';
    export default {
        data: function() {
            return {
                isFormValid: false,
                settings: null,
                saving: false,
                saveStatus: '',
                characterLimitLoading: true,
                characterLimitStatus: '',
                characterUsed: 0,
                characterLimit: 0,
                cachedDeeplTranslations: 0,
                defaultDeeplApiKey: '00000000-aaaa-aaaa-aaaa-000aaaa000aa:00',
                rules: {
                    notEmpty: value => {
                        if (!value.length) {
                            return 'Field cannot be empty.';
                        }

                        return true;
                    },
                }
            }
        },
        props: {
            language: String
        },
        mounted() {
            this.loadSettings();
        },
        methods: {
            loadDeeplCharacterLimits() {
                axios.get('/dictionaries/deepl/get-usage').then((response) => {
                    this.characterLimitLoading = false;
                    if (response.status === 200) {
                        this.characterLimitStatus = 'success';
                        this.cachedDeeplTranslations = response.data.cachedDeeplTranslations;
                        this.characterUsed = response.data.limits.character_count;
                        this.characterLimit = response.data.limits.character_limit;
                    } else {
                        if (this.settings.deeplApiKey === this.defaultDeeplApiKey) {
                            this.characterLimitStatus = 'default';
                        } else {
                            this.characterLimitStatus = 'error';
                        }
                    }
                }).catch((error) => {
                    this.characterLimitLoading = false;
                    if (this.settings.deeplApiKey === this.defaultDeeplApiKey) {
                        this.characterLimitStatus = 'default';
                    } else {
                        this.characterLimitStatus = 'error';
                    }
                });
            },
            loadSettings() {
                axios.post('/settings/global/get', {
                    'settingNames': [
                        'deeplApiKey',
                        'deeplHost',
                        'jellyfinEnabled',
                        'jellyfinHost',
                        'jellyfinApiKey',
                        'ankiConnectHost',
                        'ankiAutoAddCards',
                        'ankiUpdateCards',
                        'ankiShowNotifications',
                        'libreTranslateHost'
                    ]
                }).then((result) => {
                    this.settings = result.data;
                    this.loadDeeplCharacterLimits();
                });
            },
            saveSettings() {
                this.saving = true;

                this.characterLimitLoading = true;
                this.characterUsed = 0;
                this.characterLimit = 0;
                this.characterLimitStatus = '';

                axios.post('/settings/global/update', {
                    'settings': {
                        'deeplApiKey': this.settings.deeplApiKey,
                        'deeplHost': this.settings.deeplHost,
                        'jellyfinEnabled': this.settings.jellyfinEnabled,
                        'jellyfinHost': this.settings.jellyfinHost,
                        'jellyfinApiKey': this.settings.jellyfinApiKey,
                        'ankiConnectHost': this.settings.ankiConnectHost,
                        'ankiAutoAddCards': this.settings.ankiAutoAddCards,
                        'ankiUpdateCards': this.settings.ankiUpdateCards,
                        'ankiShowNotifications': this.settings.ankiShowNotifications,
                        'libreTranslateHost': this.settings.libreTranslateHost
                    }
                }).catch((error) => {
                    this.saving = false;
                    this.saveStatus = 'error';
                    this.loadDeeplCharacterLimits();
                }).then((response) => {
                    if (response.status !== 200) {
                        return;
                    }

                    this.saving = false;
                    this.saveStatus = 'success';
                    this.loadDeeplCharacterLimits();
                });
            },
            formatNumber: formatNumber,
        }
    }
</script>
