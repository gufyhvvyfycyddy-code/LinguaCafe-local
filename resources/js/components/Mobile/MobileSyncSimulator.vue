<template>
    <v-container class="mobile-sync-simulator py-6" data-testid="mobile-sync-simulator">
        <div class="d-flex flex-wrap align-center mb-4">
            <div>
                <h1 class="text-h4 mb-1">移动端离线同步模拟器</h1>
                <div class="text--secondary">
                    使用真实移动端令牌接口构造队列，验证顺序、幂等与部分冲突。密码和令牌只保存在当前页面内存中。
                </div>
            </div>
            <v-spacer></v-spacer>
            <v-chip :color="connected ? 'success' : 'grey'" outlined data-testid="connection-status">
                {{ connected ? '已连接' : '未连接' }}
            </v-chip>
        </div>

        <v-alert v-if="error" type="error" outlined dismissible data-testid="simulator-error" @input="error = ''">
            {{ error }}
        </v-alert>

        <v-card outlined class="rounded-lg pa-4 mb-4">
            <div class="text-h6 mb-3">1. 连接测试设备</div>
            <v-row>
                <v-col cols="12" md="4">
                    <v-text-field
                        v-model.trim="credentials.email"
                        label="账号邮箱"
                        type="email"
                        outlined
                        dense
                        autocomplete="off"
                        data-testid="mobile-email"
                    ></v-text-field>
                </v-col>
                <v-col cols="12" md="4">
                    <v-text-field
                        v-model="credentials.password"
                        label="密码"
                        type="password"
                        outlined
                        dense
                        autocomplete="new-password"
                        data-testid="mobile-password"
                    ></v-text-field>
                </v-col>
                <v-col cols="12" md="4">
                    <v-text-field
                        v-model.trim="deviceUuid"
                        label="设备 UUID"
                        outlined
                        dense
                        data-testid="device-uuid"
                    ></v-text-field>
                </v-col>
            </v-row>
            <div class="d-flex flex-wrap align-center">
                <v-btn
                    color="primary"
                    :loading="connecting"
                    :disabled="connecting || !credentials.email || !credentials.password || !deviceUuid"
                    data-testid="connect-device"
                    @click="connect"
                >
                    {{ connected ? '重新连接' : '连接' }}
                </v-btn>
                <v-btn v-if="connected" text class="ml-2" data-testid="disconnect-device" @click="disconnect">
                    断开
                </v-btn>
                <span v-if="device" class="caption text--secondary ml-3">
                    设备 #{{ device.id }} · {{ device.device_uuid }}
                </span>
            </div>
            <div v-if="connected" class="mt-4">
                <v-btn
                    small
                    outlined
                    color="primary"
                    :loading="loadingPackage"
                    data-testid="load-review-package"
                    @click="loadReviewPackage"
                >
                    加载短期复习包
                </v-btn>
                <v-alert
                    v-if="packageLoaded && packageItems.length === 0"
                    class="mt-3 mb-0"
                    type="info"
                    text
                    dense
                    data-testid="package-empty"
                >
                    当前短期包没有可用卡片。
                </v-alert>
                <v-simple-table v-if="packageItems.length > 0" class="mt-3" data-testid="review-package-items">
                    <thead>
                        <tr>
                            <th>ReviewCard</th>
                            <th>WordSense</th>
                            <th>词条</th>
                            <th>到期时间</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(item, index) in packageItems" :key="item.review_card_id">
                            <td>{{ item.review_card_id }}</td>
                            <td>{{ item.display.word_sense_id }}</td>
                            <td>{{ item.display.lemma }}</td>
                            <td>{{ item.scheduling_snapshot.fsrs_due_at }}</td>
                            <td class="text-right">
                                <v-btn
                                    x-small
                                    text
                                    color="primary"
                                    :data-testid="`use-rating-${index}`"
                                    @click="usePackageItem(item, 'sense_review.rating')"
                                >
                                    用于评分
                                </v-btn>
                                <v-btn
                                    x-small
                                    text
                                    color="primary"
                                    :data-testid="`use-update-${index}`"
                                    @click="usePackageItem(item, 'word_sense.update')"
                                >
                                    用于修改
                                </v-btn>
                            </td>
                        </tr>
                    </tbody>
                </v-simple-table>
            </div>
        </v-card>

        <v-card outlined class="rounded-lg pa-4 mb-4">
            <div class="text-h6 mb-3">2. 构造排队动作</div>
            <v-row>
                <v-col cols="12" md="4">
                    <v-select
                        v-model="draft.type"
                        :items="actionTypes"
                        item-text="label"
                        item-value="value"
                        label="动作类型"
                        outlined
                        dense
                        data-testid="action-type"
                    ></v-select>
                </v-col>
                <v-col cols="12" md="5">
                    <v-text-field
                        v-model.trim="draft.occurred_at"
                        label="发生时间（ISO-8601）"
                        outlined
                        dense
                        data-testid="occurred-at"
                    ></v-text-field>
                </v-col>
                <v-col cols="12" md="3">
                    <v-text-field
                        v-model.number="draft.sequence"
                        label="设备序号"
                        type="number"
                        min="1"
                        outlined
                        dense
                        data-testid="action-sequence"
                    ></v-text-field>
                </v-col>
            </v-row>

            <v-row v-if="draft.type === 'sense_review.rating'">
                <v-col cols="12" md="4">
                    <v-text-field
                        v-model.number="draft.review_card_id"
                        label="ReviewCard ID"
                        type="number"
                        min="1"
                        outlined
                        dense
                        data-testid="review-card-id"
                    ></v-text-field>
                </v-col>
                <v-col cols="12" md="4">
                    <v-select
                        v-model="draft.rating"
                        :items="ratings"
                        label="评分"
                        outlined
                        dense
                        data-testid="rating"
                    ></v-select>
                </v-col>
                <v-col cols="12" md="4">
                    <v-text-field
                        v-model.number="draft.review_duration_ms"
                        label="作答耗时（毫秒）"
                        type="number"
                        min="0"
                        max="600000"
                        outlined
                        dense
                        data-testid="review-duration"
                    ></v-text-field>
                </v-col>
            </v-row>

            <template v-else>
                <v-row>
                    <v-col cols="12" md="4">
                        <v-text-field
                            v-model.number="draft.word_sense_id"
                            label="WordSense ID"
                            type="number"
                            min="1"
                            outlined
                            dense
                            data-testid="word-sense-id"
                        ></v-text-field>
                    </v-col>
                    <v-col cols="12" md="8">
                        <v-text-field
                            v-model.trim="draft.expected_word_sense_version"
                            label="期望 WordSense 版本（sha256:…）"
                            outlined
                            dense
                            data-testid="word-sense-version"
                        ></v-text-field>
                    </v-col>
                </v-row>
                <v-textarea
                    v-if="draft.type === 'word_sense.update'"
                    v-model="draft.changes_json"
                    label='修改内容（JSON，例如 {"sense_zh":"新释义"}）'
                    outlined
                    rows="3"
                    data-testid="sense-changes"
                ></v-textarea>
            </template>

            <v-btn color="primary" outlined data-testid="add-action" @click="addAction">加入队列</v-btn>
        </v-card>

        <v-card outlined class="rounded-lg pa-4 mb-4">
            <div class="d-flex flex-wrap align-center mb-3">
                <div class="text-h6">3. 待同步队列</div>
                <v-chip small outlined class="ml-2" data-testid="queue-count">{{ actions.length }}</v-chip>
                <v-spacer></v-spacer>
                <v-btn text small :disabled="actions.length === 0 || submitting" data-testid="clear-queue" @click="clearQueue">
                    清空
                </v-btn>
            </div>

            <v-alert v-if="actions.length === 0" type="info" text data-testid="queue-empty">
                队列为空。可以先加入成功动作和故意过期的版本，观察部分成功结果。
            </v-alert>
            <v-simple-table v-else data-testid="queued-actions">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>发生时间</th>
                        <th>序号</th>
                        <th>类型</th>
                        <th>目标</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(action, index) in actions" :key="action.client_action_id">
                        <td>{{ index + 1 }}</td>
                        <td>{{ action.occurred_at }}</td>
                        <td>{{ action.sequence }}</td>
                        <td>{{ action.type }}</td>
                        <td>{{ targetLabel(action) }}</td>
                        <td class="text-right">
                            <v-btn icon small :disabled="submitting" :data-testid="`remove-action-${index}`" @click="removeAction(index)">
                                <v-icon small>mdi-delete-outline</v-icon>
                            </v-btn>
                        </td>
                    </tr>
                </tbody>
            </v-simple-table>

            <v-btn
                class="mt-4"
                color="primary"
                :loading="submitting"
                :disabled="!connected || actions.length === 0 || submitting"
                data-testid="submit-batch"
                @click="submitBatch"
            >
                提交整批动作
            </v-btn>
        </v-card>

        <v-card v-if="result" outlined class="rounded-lg pa-4" data-testid="sync-result">
            <div class="d-flex flex-wrap align-center mb-3">
                <div class="text-h6">同步结果</div>
                <v-chip
                    small
                    class="ml-2"
                    :color="statusColor(result.status)"
                    data-testid="batch-status"
                >
                    {{ statusLabel(result.status) }}
                </v-chip>
                <v-spacer></v-spacer>
                <span class="caption text--secondary">批次 {{ result.batch_id }}</span>
            </div>
            <div class="result-counts mb-3" data-testid="result-counts">
                <v-chip small outlined>总数 {{ result.counts.total }}</v-chip>
                <v-chip small outlined color="success">成功 {{ result.counts.succeeded }}</v-chip>
                <v-chip small outlined color="error">失败 {{ result.counts.failed }}</v-chip>
                <v-chip small outlined color="info">重放 {{ result.counts.replayed }}</v-chip>
            </div>
            <v-alert v-if="result.status === 'partial'" type="warning" outlined dense data-testid="partial-result">
                批次已部分完成。成功动作不会因其他动作冲突而回滚；仅重试标为可重试的失败项。
            </v-alert>
            <v-simple-table data-testid="action-results">
                <thead>
                    <tr>
                        <th>原序</th>
                        <th>处理序</th>
                        <th>类型</th>
                        <th>结果</th>
                        <th>HTTP</th>
                        <th>错误</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="item in result.results" :key="item.client_action_id || `invalid-${item.original_index}`">
                        <td>{{ item.original_index }}</td>
                        <td>{{ item.processed_order }}</td>
                        <td>{{ item.type || '无效动作' }}</td>
                        <td>{{ item.outcome }}</td>
                        <td>{{ item.http_status }}</td>
                        <td>{{ item.error ? `${item.error.code}: ${item.error.message}` : '—' }}</td>
                    </tr>
                </tbody>
            </v-simple-table>
        </v-card>
    </v-container>
</template>

<script>
    export default {
        data() {
            return {
                credentials: { email: '', password: '' },
                deviceUuid: this.uuid(),
                token: '',
                device: null,
                connecting: false,
                loadingPackage: false,
                packageLoaded: false,
                packageItems: [],
                submitting: false,
                error: '',
                actions: [],
                result: null,
                nextSequence: 1,
                actionTypes: [
                    { label: 'Sense 正式评分', value: 'sense_review.rating' },
                    { label: '修改 WordSense', value: 'word_sense.update' },
                    { label: '删除 WordSense', value: 'word_sense.delete' },
                ],
                ratings: ['again', 'hard', 'good', 'easy'],
                draft: {},
            };
        },
        computed: {
            connected() {
                return Boolean(this.token && this.device);
            },
        },
        created() {
            this.resetDraft();
        },
        beforeDestroy() {
            this.disconnect();
            this.credentials.email = '';
            this.credentials.password = '';
            this.actions = [];
            this.result = null;
        },
        methods: {
            uuid() {
                if (window.crypto && typeof window.crypto.randomUUID === 'function') {
                    return window.crypto.randomUUID();
                }
                return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, character => {
                    const random = Math.random() * 16 | 0;
                    const value = character === 'x' ? random : (random & 0x3 | 0x8);
                    return value.toString(16);
                });
            },
            nowIso() {
                return new Date().toISOString();
            },
            resetDraft() {
                this.draft = {
                    type: this.draft.type || 'sense_review.rating',
                    occurred_at: this.nowIso(),
                    sequence: this.nextSequence,
                    review_card_id: null,
                    rating: 'good',
                    review_duration_ms: 1000,
                    word_sense_id: null,
                    expected_word_sense_version: '',
                    changes_json: '{"sense_zh":"模拟器更新"}',
                };
            },
            async connect() {
                this.connecting = true;
                this.error = '';
                this.token = '';
                this.device = null;
                try {
                    const response = await axios.post('/api/v1/mobile/auth/tokens', {
                        email: this.credentials.email,
                        password: this.credentials.password,
                        device_uuid: this.deviceUuid,
                        platform: 'web',
                        device_name: 'Offline Sync Simulator',
                        app_version: '1.0.0',
                    });
                    this.token = response.data.data.token;
                    this.device = response.data.data.device;
                } catch (error) {
                    this.applyError(error, '移动端设备连接失败。');
                } finally {
                    this.connecting = false;
                }
            },
            disconnect() {
                this.token = '';
                this.device = null;
                this.packageLoaded = false;
                this.packageItems = [];
            },
            async loadReviewPackage() {
                this.loadingPackage = true;
                this.error = '';
                try {
                    const response = await axios.get(
                        '/api/v1/mobile/review-packages/short-term',
                        {
                            params: { horizon_days: 30, limit: 100 },
                            headers: { Authorization: `Bearer ${this.token}` },
                        },
                    );
                    this.packageItems = response.data.data.items || [];
                    this.packageLoaded = true;
                } catch (error) {
                    this.applyError(error, '短期复习包加载失败。');
                } finally {
                    this.loadingPackage = false;
                }
            },
            usePackageItem(item, type) {
                this.draft.type = type;
                this.draft.review_card_id = item.review_card_id;
                this.draft.word_sense_id = item.display.word_sense_id;
                this.draft.expected_word_sense_version = item.display.word_sense_version;
                this.draft.occurred_at = this.nowIso();
            },
            addAction() {
                this.error = '';
                try {
                    const sequence = Number(this.draft.sequence);
                    if (!Number.isInteger(sequence) || sequence < 1) throw new Error('设备序号必须是正整数。');
                    if (!this.draft.occurred_at || Number.isNaN(Date.parse(this.draft.occurred_at))) {
                        throw new Error('发生时间必须是有效的 ISO-8601 时间。');
                    }
                    const action = {
                        client_action_id: this.uuid(),
                        type: this.draft.type,
                        occurred_at: new Date(this.draft.occurred_at).toISOString(),
                        sequence,
                        payload: this.buildPayload(),
                    };
                    this.actions.push(action);
                    this.nextSequence = Math.max(this.nextSequence, sequence + 1);
                    this.resetDraft();
                } catch (error) {
                    this.error = error.message || '动作内容无效。';
                }
            },
            buildPayload() {
                if (this.draft.type === 'sense_review.rating') {
                    const cardId = Number(this.draft.review_card_id);
                    if (!Number.isInteger(cardId) || cardId < 1) throw new Error('ReviewCard ID 必须是正整数。');
                    return {
                        review_card_id: cardId,
                        rating: this.draft.rating,
                        review_duration_ms: Number(this.draft.review_duration_ms) || 0,
                    };
                }
                const senseId = Number(this.draft.word_sense_id);
                if (!Number.isInteger(senseId) || senseId < 1) throw new Error('WordSense ID 必须是正整数。');
                if (!/^sha256:[a-f0-9]{64}$/.test(this.draft.expected_word_sense_version)) {
                    throw new Error('WordSense 版本必须是 sha256: 加 64 位小写十六进制。');
                }
                const payload = {
                    word_sense_id: senseId,
                    expected_word_sense_version: this.draft.expected_word_sense_version,
                };
                if (this.draft.type === 'word_sense.update') {
                    let changes;
                    try {
                        changes = JSON.parse(this.draft.changes_json);
                    } catch (ignored) {
                        throw new Error('修改内容必须是有效 JSON。');
                    }
                    if (!changes || Array.isArray(changes) || typeof changes !== 'object' || Object.keys(changes).length === 0) {
                        throw new Error('修改内容必须是非空 JSON 对象。');
                    }
                    payload.changes = changes;
                }
                return payload;
            },
            removeAction(index) {
                this.actions.splice(index, 1);
            },
            clearQueue() {
                this.actions = [];
                this.result = null;
            },
            async submitBatch() {
                this.submitting = true;
                this.error = '';
                this.result = null;
                try {
                    const response = await axios.post(
                        '/api/v1/mobile/sync/actions',
                        { batch_id: this.uuid(), actions: this.actions },
                        { headers: { Authorization: `Bearer ${this.token}` } },
                    );
                    this.result = response.data.data;
                } catch (error) {
                    this.applyError(error, '队列同步失败。');
                } finally {
                    this.submitting = false;
                }
            },
            targetLabel(action) {
                return action.type === 'sense_review.rating'
                    ? `ReviewCard #${action.payload.review_card_id}`
                    : `WordSense #${action.payload.word_sense_id}`;
            },
            statusLabel(status) {
                return { completed: '全部完成', partial: '部分完成', failed: '全部失败' }[status] || status;
            },
            statusColor(status) {
                return { completed: 'success', partial: 'warning', failed: 'error' }[status] || 'grey';
            },
            applyError(error, fallback) {
                const payload = error.response && error.response.data;
                this.error = payload && payload.error && payload.error.message
                    ? `${payload.error.code}: ${payload.error.message}`
                    : fallback;
            },
        },
    };
</script>

<style scoped>
    .mobile-sync-simulator {
        max-width: 1180px;
    }
    .result-counts {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
    }
</style>
