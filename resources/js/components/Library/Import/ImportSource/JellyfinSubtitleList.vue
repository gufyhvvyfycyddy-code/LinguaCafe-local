<template>
    <div class="d-flex flex-column justify-center flex-nowrap">
        <!-- Jellyfin info -->
        <v-alert
            v-if="!$props.subtitleLoading"
            class="media-player-subtitle-info mt-12 mb-6 rounded-lg"
            color="primary"
            type="info"
            border="left"
            dark
        >
            从 Jellyfin 当前正在播放的媒体中选择字幕，导入后可在 LinguaCafe 中阅读。
        </v-alert>

        <!-- Subtitle list -->
        <v-card 
            outlined 
            id="jellyfin-subtitle-list" 
            class="rounded-lg px-4 pb-4" 
            :loading="subtitleListLoading || $props.subtitleLoading"
        >
            <!-- Subtitle list title-->
            <div id="subtitles-card-title" class="mt-4" v-if="!$props.subtitleLoading">
                字幕
                <v-btn 
                    id="refresh-button" 
                    rounded
                    color="primary" 
                    @click="loadSubtitleList" 
                    :disabled="subtitleListLoading" 
                    v-if="!$props.subtitleLoading"
                >
                    <v-icon class="mr-1">mdi-refresh</v-icon> 刷新
                </v-btn>
            </div>

            
            <!-- Subtitle list header -->
            <div class="regular-list-height subtitle header rounded-pill my-2" v-if="!$props.subtitleLoading">
                <div class="subtitle-language">语言</div>
                <div class="subtitle-user">用户</div>
                <div class="subtitle-client">客户端</div>
                <div class="subtitle-media">媒体</div>
            </div>
            
            <!-- Subtitle list skeleton loader -->
            <template v-if="subtitleListLoading">
                <v-skeleton-loader
                    v-for="index in 3"
                    :key="index"
                    class="regular-list-height d-block skeleton rounded-pill my-2"
                    type="image"
                ></v-skeleton-loader>
            </template>
            
            <!-- Subtitle error message-->
            <template v-if="subtitleListError">
                <v-alert
                    color="error"
                    type="error"
                    border="left"
                    dark
                >
                    无法连接 Jellyfin。
                </v-alert>
            </template>

            <div class="regular-list-height subtitle rounded-pill my-2" v-if="!subtitleListLoading && !$props.subtitleLoading && !sessions.length && !subtitleListError">
                <div id="no-subtitle-found-label">没有找到字幕</div>
            </div>

            <!-- Subtitle list body -->
            <template v-for="(session, sessionIndex) in sessions" v-if="!subtitleListLoading && !$props.subtitleLoading && sessions.length">
                <div 
                    class="regular-list-height subtitle rounded-pill my-2" 
                    @click="selectSubtitle(sessionIndex, subtitleIndex)"
                    v-for="(subtitle, subtitleIndex) in session.subtitles"
                    :key="sessionIndex + '-' + subtitleIndex"
                >
                    <div class="subtitle-language">
                        <v-img 
                            class="border mx-auto" 
                            :src="'/images/flags/' + subtitle.language.toLowerCase() + '.png'" 
                            max-width="43" 
                            height="28"
                        ></v-img> 
                    </div>
                    <div class="subtitle-user">{{ session.userName }}</div>
                    <div class="subtitle-client">{{ session.client }}</div>
                    <div class="subtitle-media" v-if="session.type == 'Episode'">
                        {{ session.seriesName }} S{{ ('0' + session.seriesSeason).slice(-2) }}E{{ ('0' + session.seriesEpisode).slice(-2) }} - {{ session.title }}
                    </div>
                    <div class="subtitle-media" v-if="session.type == 'Movie'">
                        {{ session.movieName }}
                    </div>
                </div>
            </template>

            <!-- Subtitle processing title -->
            <div id="subtitles-card-title" class="mt-4 processing" v-if="$props.subtitleLoading">
                正在处理字幕
            </div>

            <!-- Subtitle processing info -->
            <div class="flex justify-space-around" v-if="$props.subtitleLoading">
                <v-alert
                    class="media-player-subtitle-info my-6 rounded-lg"
                    color="primary"
                    border="left"
                    dark
                    icon="mdi-progress-clock"
                >
                    正在处理你选择的字幕，通常需要 10 到 30 秒。处理完成后会缓存，之后加载更快。
                </v-alert>
            </div>
        </v-card>
    </div>
</template>


<script>
export default {
    data: function () {
        return {
            subtitleListLoading: false,
            subtitleListError: false,
            sessions: []
        }
    },
    props: {
        subtitleLoading: Boolean,
        language: String,
    },
    mounted() {
        this.loadSubtitleList();
    },
    methods: {
        loadSubtitleList: function () {
            this.subtitleListLoading = true;
            this.subtitleListError = false;
            this.sessions = [];
            axios.get('/jellyfin/subtitles').then((result) => {
                var sessions = result.data;

                // remove unsupported and not-selected langauge subtitles
                for (let sessionIndex = 0; sessionIndex < sessions.length; sessionIndex++) {
                    for (let subtitleIndex = sessions[sessionIndex].subtitles.length - 1; subtitleIndex >= 0; subtitleIndex--) {

                        // remove unsupported language subtitle
                        if (!sessions[sessionIndex].subtitles[subtitleIndex].supportedLanguage) {
                            console.log('unsupported language code:', sessions[sessionIndex].subtitles[subtitleIndex].language);
                        }

                        // remove note-selected language subtitle
                        if (sessions[sessionIndex].subtitles[subtitleIndex].language !== this.$props.language) {
                            // sessions[sessionIndex].subtitles.splice(subtitleIndex, 1);
                        }
                    }
                }

                this.sessions = sessions;
            }).catch((error) => {
                this.subtitleListError = true;
            }).finally(() => {
                this.subtitleListLoading = false;
            });
        },
        selectSubtitle: function(selectedSession, selectedSubtitle) {
            var subtitleData = {
                subtitle: this.sessions[selectedSession].subtitles[selectedSubtitle].text,
                language: this.sessions[selectedSession].subtitles[selectedSubtitle].language,
                client: this.sessions[selectedSession].client,
                userName: this.sessions[selectedSession].userName,
                userId: this.sessions[selectedSession].userId,
                title: this.sessions[selectedSession].title,
                type: this.sessions[selectedSession].type,
                
                nowPlayingItemId: this.sessions[selectedSession].nowPlayingItemId,
                runTimeTicks: this.sessions[selectedSession].runTimeTicks,
                mediaSourceId: this.sessions[selectedSession].mediaSourceId,
                sessionId: this.sessions[selectedSession].sessionId
            };

            if (subtitleData.type == 'Movie') {
                subtitleData.movieName = this.sessions[selectedSession].movieName;
            } else {
                subtitleData.seriesName = this.sessions[selectedSession].seriesName;
                subtitleData.seriesEpisode = this.sessions[selectedSession].seriesEpisode;
                subtitleData.seriesSeason = this.sessions[selectedSession].seriesSeason;
            }

            this.$emit('subtitle-change', subtitleData);
        }
    }
}
</script>
