<template>
    <div class="sense-media-controls d-inline-flex align-center" data-testid="sense-media-controls">
        <v-btn
            small
            text
            :loading="playing === 'word_pronunciation'"
            data-testid="play-word-audio"
            :aria-label="wordMedia ? '播放词发音附件' : '使用浏览器语音朗读单词'"
            @click="play('word_pronunciation')"
        >
            <v-icon small left>mdi-volume-high</v-icon>词发音
        </v-btn>
        <v-btn
            small
            text
            :loading="playing === 'example_audio'"
            data-testid="play-example-audio"
            :aria-label="exampleMedia ? '播放例句音频附件' : '使用浏览器语音朗读例句'"
            @click="play('example_audio')"
        >
            <v-icon small left>mdi-message-text-outline</v-icon>例句
        </v-btn>
        <v-menu offset-y left>
            <template v-slot:activator="{ on, attrs }">
                <v-btn icon small v-bind="attrs" v-on="on" data-testid="manage-sense-media" aria-label="管理音频">
                    <v-icon small>mdi-paperclip</v-icon>
                </v-btn>
            </template>
            <v-list dense>
                <v-list-item @click="openUpload('word_pronunciation')">
                    <v-list-item-title>{{ wordMedia ? '替换词发音' : '上传词发音' }}</v-list-item-title>
                </v-list-item>
                <v-list-item @click="openUpload('example_audio')">
                    <v-list-item-title>{{ exampleMedia ? '替换当前例句音频' : '上传当前例句音频' }}</v-list-item-title>
                </v-list-item>
                <v-list-item v-if="wordMedia" @click="remove(wordMedia)">
                    <v-list-item-title class="error--text">移除词发音</v-list-item-title>
                </v-list-item>
                <v-list-item v-if="exampleMedia" @click="remove(exampleMedia)">
                    <v-list-item-title class="error--text">移除当前例句音频</v-list-item-title>
                </v-list-item>
            </v-list>
        </v-menu>

        <v-dialog v-model="dialog" max-width="520" persistent>
            <v-card>
                <v-card-title>{{ uploadRole === 'word_pronunciation' ? '词发音附件' : '当前例句音频' }}</v-card-title>
                <v-card-text>
                    <v-alert type="info" dense text>
                        仅 MP3/M4A，最大 10 MiB。文件将按内容哈希保存；移除后保留 30 天。
                    </v-alert>
                    <v-file-input
                        v-model="file"
                        accept="audio/mpeg,audio/mp4,.mp3,.m4a"
                        label="选择音频"
                        prepend-icon="mdi-music-note"
                        :error-messages="uploadError"
                        @change="uploadError = ''"
                    />
                    <v-select
                        v-model="copyrightStatus"
                        :items="copyrightOptions"
                        item-text="text"
                        item-value="value"
                        label="版权状态"
                    />
                    <v-text-field v-model="copyrightSource" maxlength="512" label="来源说明（可选）" />
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn text :disabled="uploading" @click="dialog = false">取消</v-btn>
                    <v-btn color="primary" :loading="uploading" :disabled="!file" @click="upload">保存音频</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
        <span class="sr-only" aria-live="polite">{{ liveMessage }}</span>
    </div>
</template>

<script>
import axios from 'axios';
import TextToSpeechService from '../../services/TextToSpeechService';
import { selectMedia } from '../Review/MediaSlot';

export default {
    name: 'SenseMediaControls',
    props: {
        card: { type: Object, required: true },
    },
    data() {
        return {
            playing: '',
            audio: null,
            dialog: false,
            uploadRole: 'word_pronunciation',
            file: null,
            copyrightStatus: 'owned',
            copyrightSource: '',
            uploading: false,
            uploadError: '',
            liveMessage: '',
            copyrightOptions: [
                { text: '本人拥有或录制', value: 'owned' },
                { text: '已获许可', value: 'licensed' },
                { text: '公有领域', value: 'public_domain' },
                { text: '不确定', value: 'unknown' },
            ],
        };
    },
    computed: {
        wordMedia() {
            return selectMedia(this.card.media, 'word_pronunciation');
        },
        exampleMedia() {
            return selectMedia(this.card.media, 'example_audio', null, this.card.example_sentence_en || '');
        },
    },
    beforeDestroy() {
        this.stopAudio();
    },
    methods: {
        async play(role) {
            const item = role === 'word_pronunciation' ? this.wordMedia : this.exampleMedia;
            const text = role === 'word_pronunciation' ? this.card.lemma : this.card.example_sentence_en;
            this.stopAudio();
            if (item) {
                this.playing = role;
                this.audio = new Audio(item.download_path);
                this.audio.addEventListener('ended', () => { this.playing = ''; }, { once: true });
                this.audio.addEventListener('error', () => {
                    this.playing = '';
                    this.notify('音频附件暂时无法播放。', 'error');
                }, { once: true });
                try {
                    await this.audio.play();
                    this.liveMessage = role === 'word_pronunciation' ? '正在播放词发音' : '正在播放例句音频';
                } catch (_) {
                    this.playing = '';
                    this.notify('浏览器阻止了音频播放，请再次点击。', 'error');
                }
                return;
            }
            const spoken = text && new TextToSpeechService(this.card.language || 'english').speak(text);
            if (!spoken) {
                this.notify('当前浏览器没有可用的英语语音，请上传 MP3/M4A。', 'warning');
            } else {
                this.liveMessage = '正在使用浏览器语音朗读';
            }
        },
        stopAudio() {
            if (this.audio) {
                this.audio.pause();
                this.audio.src = '';
            }
            this.audio = null;
            this.playing = '';
        },
        openUpload(role) {
            this.uploadRole = role;
            this.file = null;
            this.uploadError = '';
            this.dialog = true;
        },
        upload() {
            if (!this.file) return;
            const data = new FormData();
            data.append('file', this.file);
            data.append('role', this.uploadRole);
            data.append('copyright_status', this.copyrightStatus);
            if (this.copyrightSource) data.append('copyright_source', this.copyrightSource);
            if (this.uploadRole === 'example_audio') {
                data.append('sentence', this.card.example_sentence_en || '');
            }
            this.uploading = true;
            this.uploadError = '';
            axios.post(`/word-senses/${this.card.word_sense_id}/media`, data, {
                headers: { 'Content-Type': 'multipart/form-data' },
            }).then((response) => {
                this.$emit('updated', response.data.media || []);
                this.dialog = false;
                this.notify('音频附件已保存。', 'success');
            }).catch((error) => {
                this.uploadError = Object.values(error.response?.data?.errors || {}).flat()[0]
                    || error.response?.data?.message || '音频上传失败。';
            }).finally(() => { this.uploading = false; });
        },
        remove(item) {
            axios.delete(`/media/references/${item.reference_id}`).then(() => {
                this.$emit('updated', (this.card.media || []).filter((entry) => entry.reference_id !== item.reference_id));
                this.notify('附件已移除，二进制将在保留期内可恢复。', 'info');
            }).catch(() => this.notify('附件移除失败。', 'error'));
        },
        notify(message, color) {
            this.liveMessage = message;
            this.$emit('notify', message, color);
        },
    },
};
</script>

<style scoped>
.sense-media-controls { gap: 2px; }
.sr-only {
    position: absolute;
    width: 1px;
    height: 1px;
    overflow: hidden;
    clip: rect(0, 0, 0, 0);
}
@media (max-width: 600px) {
    .sense-media-controls { width: 100%; justify-content: flex-start; margin-top: 4px; }
    .sense-media-controls .v-btn { min-height: 44px; }
}
</style>
