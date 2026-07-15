<template>
    <v-card outlined class="rounded-lg mt-4" :loading="loading || busy">
        <v-card-title class="d-flex align-center flex-wrap">
            <span>复习设置 Preset</span>
            <v-spacer />
            <v-chip v-if="currentPreset" small color="primary" text-color="white">
                当前 Preset：{{ currentPreset.name }}
            </v-chip>
        </v-card-title>
        <v-card-subtitle>
            当前语言：{{ currentLanguage }}。切换只改变当前语言的绑定，不会自动重排已有卡片。
        </v-card-subtitle>
        <v-card-text>
            <v-alert v-if="error" dense outlined type="error" class="mb-3">{{ error }}</v-alert>
            <v-alert v-if="success" dense outlined type="success" class="mb-3">{{ success }}</v-alert>

            <v-row dense align="center">
                <v-col cols="12" md="6">
                    <v-select
                        v-model="selectedPresetId"
                        :items="presetOptions"
                        item-text="text"
                        item-value="value"
                        label="当前语言使用的 Preset"
                        outlined
                        dense
                        hide-details
                        :disabled="loading || busy || presets.length === 0"
                        @change="switchPreset"
                    />
                </v-col>
                <v-col cols="12" md="6" class="d-flex flex-wrap justify-md-end mt-3 mt-md-0">
                    <v-btn small outlined color="primary" class="mr-2 mb-2" :disabled="busy" @click="openNameDialog('create')">
                        新建 Preset
                    </v-btn>
                    <v-btn small outlined class="mr-2 mb-2" :disabled="!selectedPreset || busy" @click="openNameDialog('clone')">
                        复制
                    </v-btn>
                    <v-btn small outlined class="mr-2 mb-2" :disabled="!selectedPreset || selectedPreset.is_default || busy" @click="openNameDialog('rename')">
                        重命名
                    </v-btn>
                    <v-btn small outlined color="error" class="mb-2" :disabled="!selectedPreset || selectedPreset.is_default || busy" @click="deleteDialog = true">
                        删除
                    </v-btn>
                </v-col>
            </v-row>

            <div v-if="selectedPreset" class="mt-4">
                <div class="caption grey--text text--darken-1 mb-2">适用语言</div>
                <div class="d-flex flex-wrap">
                    <v-chip
                        v-for="boundLanguage in selectedPreset.bound_languages"
                        :key="boundLanguage"
                        small
                        outlined
                        class="mr-2 mb-2"
                    >
                        {{ boundLanguage }}
                    </v-chip>
                    <span v-if="selectedPreset.bound_language_count === 0" class="body-2 grey--text">尚未绑定语言</span>
                </div>
                <v-alert
                    v-if="selectedPreset.bound_language_count > 1"
                    dense
                    outlined
                    type="info"
                    class="mt-2 mb-0"
                >
                    这个 Preset 由 {{ selectedPreset.bound_language_count }} 种语言共享。修改下面的设置会同时影响：{{ selectedPreset.bound_languages.join('、') }}。
                </v-alert>
                <div v-else class="caption grey--text mt-1">
                    修改会同时影响所有绑定此 Preset 的语言。
                </div>
            </div>
        </v-card-text>

        <v-dialog v-model="nameDialog" max-width="480" persistent>
            <v-card>
                <v-card-title>{{ nameDialogTitle }}</v-card-title>
                <v-card-text>
                    <v-text-field
                        v-model="nameInput"
                        label="Preset 名称"
                        outlined
                        dense
                        maxlength="120"
                        counter="120"
                        :error-messages="nameError ? [nameError] : []"
                        @keyup.enter="submitNameAction"
                    />
                    <div class="caption grey--text">Default 是系统保留名称。名称在当前用户内必须唯一。</div>
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn text :disabled="busy" @click="closeNameDialog">取消</v-btn>
                    <v-btn color="primary" depressed :loading="busy" @click="submitNameAction">确认</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <v-dialog v-model="deleteDialog" max-width="520" persistent>
            <v-card>
                <v-card-title>删除 Preset</v-card-title>
                <v-card-text>
                    <p>确定删除“{{ selectedPreset?.name }}”吗？</p>
                    <v-alert dense outlined type="warning" class="mb-0">
                        绑定到它的语言会安全地重新绑定到 Default。此操作不会删除学习卡，也不会重排已有卡片。
                    </v-alert>
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn text :disabled="busy" @click="deleteDialog = false">取消</v-btn>
                    <v-btn color="error" depressed :loading="busy" @click="deleteSelected">删除并重新绑定</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    </v-card>
</template>

<script>
import * as AdminReviewSettingsApi from '../../../services/AdminReviewSettingsApi';

export default {
    props: {
        language: {
            type: String,
            default: '',
        },
    },
    data() {
        return {
            loading: true,
            busy: false,
            state: null,
            selectedPresetId: null,
            nameDialog: false,
            nameMode: 'create',
            nameInput: '',
            nameError: '',
            deleteDialog: false,
            error: '',
            success: '',
        };
    },
    computed: {
        presets() {
            return this.state?.presets || [];
        },
        currentPreset() {
            return this.presets.find(preset => preset.id === this.state?.current_preset_id) || null;
        },
        selectedPreset() {
            return this.presets.find(preset => preset.id === this.selectedPresetId) || null;
        },
        currentLanguage() {
            return this.state?.current_language || this.language || '—';
        },
        presetOptions() {
            return this.presets.map(preset => ({
                value: preset.id,
                text: `${preset.name}${preset.is_default ? '（默认）' : ''}`,
            }));
        },
        nameDialogTitle() {
            return {
                create: '新建 Preset',
                clone: `复制“${this.selectedPreset?.name || ''}”`,
                rename: `重命名“${this.selectedPreset?.name || ''}”`,
            }[this.nameMode];
        },
    },
    mounted() {
        this.load();
    },
    methods: {
        applyState(data, selectCurrent = true) {
            this.state = data;
            if (selectCurrent || !this.presets.some(preset => preset.id === this.selectedPresetId)) {
                this.selectedPresetId = data.current_preset_id;
            }
        },
        load() {
            this.loading = true;
            this.error = '';
            AdminReviewSettingsApi.listReviewSettingsPresets()
                .then(response => this.applyState(response.data))
                .catch(error => { this.error = this.messageFrom(error, '加载 Preset 失败，请稍后重试。'); })
                .finally(() => { this.loading = false; });
        },
        switchPreset(presetId) {
            if (!presetId || presetId === this.state?.current_preset_id) return;
            const previousId = this.state?.current_preset_id;
            this.run(
                () => AdminReviewSettingsApi.switchReviewSettingsPreset(presetId),
                '当前语言已切换到新的 Preset。',
                true,
                () => { this.selectedPresetId = previousId; },
            );
        },
        openNameDialog(mode) {
            this.nameMode = mode;
            this.nameInput = mode === 'rename' ? this.selectedPreset?.name || '' : '';
            this.nameError = '';
            this.nameDialog = true;
        },
        closeNameDialog() {
            this.nameDialog = false;
            this.nameInput = '';
            this.nameError = '';
        },
        submitNameAction() {
            const name = this.nameInput.trim();
            if (!name) {
                this.nameError = '请填写 Preset 名称。';
                return;
            }
            const action = {
                create: () => AdminReviewSettingsApi.createReviewSettingsPreset(name),
                clone: () => AdminReviewSettingsApi.cloneReviewSettingsPreset(this.selectedPreset.id, name),
                rename: () => AdminReviewSettingsApi.renameReviewSettingsPreset(this.selectedPreset.id, name),
            }[this.nameMode];
            const success = {
                create: 'Preset 已创建。',
                clone: 'Preset 已复制。',
                rename: 'Preset 已重命名。',
            }[this.nameMode];
            this.run(action, success, this.nameMode === 'rename', null, true);
        },
        deleteSelected() {
            if (!this.selectedPreset || this.selectedPreset.is_default) return;
            this.run(
                () => AdminReviewSettingsApi.deleteReviewSettingsPreset(this.selectedPreset.id),
                'Preset 已删除；原绑定语言已重新绑定到 Default。',
                true,
                null,
                false,
                true,
            );
        },
        run(action, successMessage, refreshPanels, onFailure = null, closeName = false, closeDelete = false) {
            this.busy = true;
            this.error = '';
            this.success = '';
            this.nameError = '';
            action()
                .then(response => {
                    this.applyState(response.data);
                    this.success = successMessage;
                    if (closeName) this.closeNameDialog();
                    if (closeDelete) this.deleteDialog = false;
                    if (refreshPanels) this.$emit('preset-changed', response.data.current_preset_id);
                })
                .catch(error => {
                    const message = this.messageFrom(error, 'Preset 操作失败，请稍后重试。');
                    if (this.nameDialog && error.response?.data?.errors?.name) {
                        this.nameError = error.response.data.errors.name[0];
                    } else {
                        this.error = message;
                    }
                    if (onFailure) onFailure();
                })
                .finally(() => { this.busy = false; });
        },
        messageFrom(error, fallback) {
            return error.response?.data?.message || fallback;
        },
    },
};
</script>
