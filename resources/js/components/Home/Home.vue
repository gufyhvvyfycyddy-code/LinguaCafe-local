<template>
    <div>
        <v-container id="home" class="pb-12">
            <change-password-dialog
                v-model="passwordChangeDialog"
                @password-changed="passwordChangeFinished"
            ></change-password-dialog>

            <template v-if="!passwordChanged">
                <div class="subheader subheader-margin-top d-flex">
                    <v-icon large color="error" class="mr-2">mdi-alert</v-icon>修改密码
                </div>

                <v-alert
                    id="password-change-alert"
                    class="rounded-lg mt-2"
                    color="foreground"
                    border="left"
                >
                    你的账号由管理员创建。继续使用前，请先修改密码。

                    <div class="d-flex mt-4">
                        <v-spacer />
                        <v-btn
                            rounded
                            depressed
                            color="error"
                            @click="passwordChangeDialog = true;"
                        >
                            <v-icon class="mr-2">mdi-lock-reset</v-icon>
                            修改密码
                        </v-btn>
                    </div>
                </v-alert>
            </template>

            <calendar
                ref="calendar"
                @achievement-quantity-change="updateGoals"
            ></calendar>
            <goals
                ref="goals"
                @goal-quantity-change="updateCalendar"
            ></goals>
            <statistics
                ref="statistics"
            ></statistics>

            <div class="subheader subheader-margin-top d-flex">
                关于
            </div>

            <div id="about" class="d-flex flex-wrap">
                <v-card outlined class="rounded-lg pt-0 mr-4 mb-4" width="290px">
                    <v-card-title>LinguaCafe</v-card-title>
                    <v-card-text>
                        可以通过这些链接了解 LinguaCafe。
                        <div class="footer-link-box mb-1 mt-4">
                            <router-link to="/attributions"><v-icon class="mr-2">mdi-copyright</v-icon>版权与致谢</router-link>
                        </div>
                        <div class="footer-link-box mb-1">
                            <a href="https://simjanos-dev.github.io/LinguaCafeHome/"><v-icon class="mr-2">mdi-file-document</v-icon>项目介绍</a>
                        </div>
                    </v-card-text>
                </v-card>

                <v-card outlined class="rounded-lg pt-0 mr-4 mb-4" width="290px">
                    <v-card-title>联系</v-card-title>
                    <v-card-text>
                        可以通过这些平台联系 LinguaCafe 开发者。
                        <div class="footer-link-box mb-1 mt-4">
                            <a href="https://discord.gg/wZYZYrdaeP"><v-icon class="mr-2">mdi-message-text</v-icon>Discord chat</a>
                        </div>
                        <div class="footer-link-box mb-1">
                            <a href="https://github.com/simjanos-dev/LinguaCafe"><v-icon class="mr-2">mdi-github</v-icon>Github</a>
                        </div>
                        <div class="footer-link-box mb-1">
                            <a href="https://www.reddit.com/r/linguacafe/"><v-icon class="mr-2">mdi-reddit</v-icon>Reddit</a>
                        </div>
                    </v-card-text>
                </v-card>

                <v-card outlined class="rounded-lg pt-0 mr-4 mb-4" width="290px">
                    <v-card-title>版本</v-card-title>
                    <v-card-text>
                        当前 LinguaCafe 版本是 v0.14.1。
                        <div class="footer-link-box mb-1 mt-4">
                            <router-link to="/patch-notes"><v-icon class="mr-2">mdi-update</v-icon>更新说明</router-link>
                        </div>
                    </v-card-text>
                </v-card>
            </div>
        </v-container>
    </div>
</template>


<script>
    import {formatNumber} from './../../helper.js';
    const moment = require('moment');
    import { DefaultLocalStorageManager } from './../../services/LocalStorageManagerService';
    export default {
        data: function() {
            return {
                theme: DefaultLocalStorageManager.loadSetting('theme') || 'light',
                passwordChanged: true,
                passwordChangeDialog: false
            }
        },
        props: {
        },
        mounted() {
            axios.get('/users/is-password-changed').then((response) => {
                this.passwordChanged = Boolean(response.data);
            }).catch(() => {});
        },
        methods: {
            updateCalendar() {
                this.$refs.calendar.loadCalendarData();
                this.$refs.statistics.loadStatistics();
            },
            updateGoals() {
                this.$refs.goals.loadGoals();
            },
            passwordChangeFinished() {
                this.passwordChanged = true;
                this.passwordChangeDialog = false;
            },
            formatNumber: formatNumber,
        }
    }
</script>
