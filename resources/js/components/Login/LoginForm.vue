<template>
    <v-container class="d-flex justify-center">
        <theme-selection-dialog v-model="themeSelectionDialog" @input="updateTheme" />

        <v-card outlined class="rounded-lg mt-16" width="600px">
            <v-card-title>
                <v-icon class="mr-2">{{ setupMode ? 'mdi-account-plus' : 'mdi-account' }}</v-icon>
                {{ setupMode ? '创建第一个管理员账号' : '登录' }}
                <v-spacer />
                <v-btn rounded depressed @click="themeSelectionDialog = true;">
                    <v-icon class="mr-2">mdi-weather-sunny</v-icon> / <v-icon class="ml-2">mdi-weather-night</v-icon>
                </v-btn>
            </v-card-title>

            <v-card-text class="pt-4 pb-6">
                <template v-if="setupMode && userCount > 0">
                    <v-alert class="rounded-lg mb-6" color="primary" type="info" border="left" dark>
                        系统已初始化，请使用已有账号登录。
                    </v-alert>
                    <v-btn rounded depressed color="primary" href="/login">
                        返回登录
                    </v-btn>
                </template>

                <template v-else-if="setupMode">
                    <v-alert class="rounded-lg mb-6" color="primary" type="info" border="left" dark>
                        当前还没有任何用户。请创建第一个管理员账号，创建后再返回登录页登录。
                    </v-alert>

                    <v-form v-model="isSetupFormValid" ref="setupForm">
                        <label class="font-weight-bold">邮箱</label>
                        <v-text-field v-model="setup.email" rounded filled dense placeholder="邮箱" :rules="[rules.email]" :disabled="loading" />

                        <label class="font-weight-bold">密码</label>
                        <v-text-field v-model="setup.password" rounded filled dense type="password" placeholder="密码" :rules="[rules.password]" :disabled="loading" />

                        <label class="font-weight-bold">确认密码</label>
                        <v-text-field v-model="setup.passwordConfirmation" rounded filled dense type="password" placeholder="确认密码" :rules="[rules.passwordMatch]" :disabled="loading" @keyup.enter="createFirstUser" />

                        <v-alert v-if="error !== ''" class="rounded-lg mt-4 mb-0" color="error" type="error" border="left" dark>
                            {{ error }}
                        </v-alert>
                        <v-alert v-if="success !== ''" class="rounded-lg mt-4 mb-0" color="success" type="success" border="left" dark>
                            {{ success }}
                        </v-alert>
                    </v-form>
                </template>

                <template v-else>
                    <v-form v-model="isLoginFormValid" ref="loginForm">
                        <v-alert v-if="userCount == 0" class="rounded-lg mb-8" color="primary" type="info" border="left" dark>
                            看起来这是安装后第一次使用 LinguaCafe。请先创建第一个管理员账号。
                            <div class="d-flex mt-4">
                                <v-spacer />
                                <v-btn rounded depressed color="gray" class="text--text" href="/setup">
                                    <v-icon color="text" class="mr-2">mdi-account-plus</v-icon>
                                    创建第一个管理员账号
                                </v-btn>
                            </div>
                        </v-alert>

                        <label class="font-weight-bold">邮箱</label>
                        <v-text-field v-model="email" rounded filled dense name="linguacafe-email" placeholder="邮箱" :rules="[rules.email]" @keyup.enter="login" />

                        <label class="font-weight-bold">密码</label>
                        <v-text-field v-model="password" rounded filled dense type="password" name="linguacafe-password" placeholder="密码" :rules="[rules.requiredPassword]" @keyup.enter="login" />

                        <v-alert v-if="error !== ''" class="rounded-lg mt-4 mb-0" color="error" type="error" border="left" dark>
                            {{ error }}
                        </v-alert>
                    </v-form>
                </template>
            </v-card-text>

            <v-card-actions v-if="setupMode && userCount == 0">
                <v-btn rounded text href="/login" :disabled="loading">返回登录</v-btn>
                <v-spacer />
                <v-btn color="primary" rounded :disabled="loading || !isSetupFormValid" :loading="loading" @click="createFirstUser">
                    创建账号
                </v-btn>
            </v-card-actions>

            <v-card-actions v-if="!setupMode">
                <v-spacer />
                <v-btn color="primary" rounded :disabled="loading || !isLoginFormValid" :loading="loading" @click="login">
                    登录
                </v-btn>
            </v-card-actions>
        </v-card>
    </v-container>
</template>

<script>
export default {
    props: {
        userCount: Number,
        setupMode: Boolean,
    },
    data() {
        return {
            themeSelectionDialog: false,
            isLoginFormValid: false,
            isSetupFormValid: false,
            email: '',
            password: '',
            setup: {
                email: '',
                password: '',
                passwordConfirmation: '',
            },
            error: '',
            success: '',
            loading: false,
            rules: {
                requiredPassword: value => value.length > 0 || '请输入密码。',
                password: value => (value.length >= 8 && value.length <= 32) || '密码长度必须在 8 到 32 个字符之间。',
                passwordMatch: value => value == this.setup.password || '两次输入的密码不一致。',
                email: value => {
                    const pattern = /^(([^<>()[\]\\.,;:\s@"]+(\.[^<>()[\]\\.,;:\s@"]+)*)|(".+"))@((\[[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}])|(([a-zA-Z\-0-9]+\.)+[a-zA-Z]{2,}))$/;
                    return pattern.test(value) || '请输入有效邮箱。';
                }
            }
        };
    },
    methods: {
        createFirstUser() {
            if (!this.$refs.setupForm.validate()) {
                return;
            }

            this.loading = true;
            this.error = '';
            this.success = '';

            axios.post('/users/create', {
                name: this.setup.email,
                email: this.setup.email,
                password: this.setup.password,
                password_confirmation: this.setup.passwordConfirmation,
                isAdmin: true,
            }).then(() => {
                this.success = '账号创建成功，请使用该邮箱和密码登录。';
                window.location.href = '/login';
            }).catch((error) => {
                this.error = this.formatError(error) || '账号创建失败。';
            }).finally(() => {
                this.loading = false;
            });
        },
        login() {
            if (!this.$refs.loginForm.validate()) {
                return;
            }

            this.loading = true;
            this.error = '';
            axios.post('/login', {
                email: this.email,
                password: this.password,
                remember: true
            }).then((response) => {
                if (response.status === 200) {
                    window.location.href = '/';
                } else {
                    this.error = '邮箱或密码不正确。';
                }
            }).catch(() => {
                this.error = '邮箱或密码不正确。';
            }).finally(() => {
                this.loading = false;
            });
        },
        formatError(error) {
            if (!error.response) {
                return '';
            }

            if (error.response.data?.errors) {
                return Object.values(error.response.data.errors).flat().join(' ');
            }

            return error.response.data?.message || error.response.data || '';
        },
        updateTheme() {
            window.location.href = window.location.pathname;
        },
    }
}
</script>
