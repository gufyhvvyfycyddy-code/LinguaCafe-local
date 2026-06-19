import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

Pusher.logToConsole = false;

function emptyEcho() {
    return {
        private() {
            return {
                listen() {
                    return this;
                },
                stopListening() {
                    return this;
                },
            };
        },
        leave() {},
    };
}

function createEcho() {
    try {
        const echo = new Echo({
            broadcaster: 'pusher',
            key: 'wjp2pou6ebgibtwccqsj',
            cluster: 'mt1',
            forceTLS: false,
            wsHost: window.location.hostname,
            wsPort: 6001,
            enabledTransports: ['ws', 'wss'],
        });

        echo.connector.pusher.connection.bind('error', (error) => {
            console.warn('实时状态服务未启动，章节状态可能需要手动刷新。', error);
        });

        return echo;
    } catch (error) {
        console.warn('实时状态服务初始化失败，章节状态可能需要手动刷新。', error);
        return emptyEcho();
    }
}

export default {
    namespaced: true,
    state: () => ({
        userUuid: '',
        userName: false,
        userEmail: false,
        userAdmin: false,
        vuetifyThemeSettings: null,
        textStylingSettings: null,
        echo: createEcho()
    }),
    mutations: {
        setUuid (state, userUuid) {
            state.userUuid = userUuid;
        },
        setUserName (state, userName) {
            state.userName = userName;
        },
        setUserEmail (state, userEmail) {
            state.userEmail = userEmail;
        },
        setUserAdmin (state, userAdmin) {
            state.userAdmin = userAdmin;
        },
        setVuetifyThemeSettings (state, vuetifyThemeSettings) {
            state.vuetifyThemeSettings = vuetifyThemeSettings;
        },
        setTextStylingSettings (state, textStylingSettings) {
            state.textStylingSettings = textStylingSettings;
        }
    },
    getters: {
        echo (state) {
            return state.echo;
        },
        userUuid(state) {
            return state.userUuid;
        },
        userAdmin(state) {
            return state.userAdmin;
        }
    }
}
