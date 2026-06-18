<template>
    <v-dialog v-model="value" persistent max-width="600px" height="300px">
        <v-card class="rounded-lg">
            <v-card-title>
                <!-- Add user title-->
                <template v-if="userId == -1">
                    <v-icon class="mr-2" >mdi-account-plus</v-icon>
                    <span class="text-h5">新增用户</span>
                </template>

                <!-- Edit user title-->
                <template v-if="userId !== -1">
                    <v-icon class="mr-2" >mdi-account-edit</v-icon>
                    <span class="text-h5">编辑用户</span>
                </template>

                <!-- Close button -->
                <v-spacer />
                <v-btn icon @click="close">
                    <v-icon>mdi-close</v-icon>
                </v-btn>
            </v-card-title>
            
            <!-- User form -->
            <v-card-text class="pt-4 pb-6">
                <v-form v-model="isFormValid" ref="userForm">
                    <!-- Name -->
                    <label class="font-weight-bold">姓名</label>
                    <v-text-field 
                        v-model="name"
                        filled
                        dense
                        rounded
                        placeholder="姓名"
                        maxlength="64"
                        :rules="[rules.nameLength]"
                        :disabled="saving"
                        @keyup.enter="save"
                    ></v-text-field>
                    
                    <!-- E-mail -->
                    <label class="font-weight-bold">邮箱地址</label>
                    <v-text-field
                        v-model="email"
                        filled
                        dense
                        rounded
                        placeholder="邮箱地址"
                        maxlength="64"
                        :rules="[rules.email]"
                        :disabled="saving"
                        @keyup.enter="save"
                    ></v-text-field>

                    <template v-if="userId == -1">
                        <!-- Password -->
                        <label class="font-weight-bold">密码</label>
                        <v-text-field
                            v-model="password"
                            type="password"
                            filled
                            dense
                            rounded
                            placeholder="密码"
                            maxlength="32"
                            style="overflow: hidden;"
                            :rules="[rules.password]"
                            :disabled="saving"
                            @keyup.enter="save"
                        ></v-text-field>

                        <!-- Password confirmation -->
                        <label class="font-weight-bold">确认密码</label>
                        <v-text-field
                            v-model="passwordConfirmation"
                            type="password"
                            filled
                            dense
                            rounded
                            placeholder="确认密码"
                            maxlength="32"
                            :rules="[rules.passwordMatch]"
                            :disabled="saving"
                            @keyup.enter="save"
                        ></v-text-field>
                    </template>

                    <!-- Admin -->
                    <label class="font-weight-bold">管理员</label>
                    <v-switch
                        v-model="isAdmin"
                        hide-details
                        class="mt-0"
                        color="primary"
                        label="管理员"
                        :disabled="saving || $props.adminLock"
                    ></v-switch>

                    <v-alert
                        v-if="$props._isCurrentUser && !isAdmin"
                        class="rounded-lg mt-4 mb-0"
                        color="error"
                        type="error"
                        border="left"
                        dark
                    >
                        这是你当前登录的用户，此操作会移除你自己的管理员权限。
                    </v-alert>

                    <v-alert
                        v-if="errorMessage !== '' && errorMessage !== 'success'"
                        class="rounded-lg mt-4 mb-0"
                        color="error"
                        type="error"
                        border="left"
                        dark
                    >
                        <div v-html="errorMessage"></div>
                    </v-alert>
                </v-form>
            </v-card-text>
            
            <v-card-actions>
                <v-spacer></v-spacer>
                <v-btn rounded text @click="close">取消</v-btn>

                <!-- Save button -->
                <v-btn 
                    rounded 
                    depressed
                    color="primary" 
                    @click="save"
                    :disabled="!isFormValid || saving"
                    :loading="saving"
                >
                    <template v-if="userId == -1">新增用户</template>
                    <template v-if="userId !== -1">保存</template>
                </v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>
</template>

<script>
    export default {
        props: {
            value : Boolean,
            _userId: {
                type: Number,
                default: -1
            },
            _isCurrentUser: {
                type: Boolean,
                default: false
            },
            _name: {
                type: String,
                default: ''
            },
            _email: {
                type: String,
                default: ''
            },
            _isAdmin: {
                type: Number,
                default: false
            },
            adminLock: {
                type: Boolean,
                default: false
            },
        },
        emits: ['input'],
        data: function() {
            return {
                isFormValid: false,
                errorMessage: '',
                saving: false,
                userId: this.$props._userId,
                name: this.$props._name,
                email: this.$props._email,
                password: '',
                passwordConfirmation: '',
                isAdmin: Boolean(this.$props._isAdmin),

                rules: {
                    nameLength: value => {
                        if (value.length < 2 || value.length > 64) {
                            return '姓名长度必须在 2 到 64 个字符之间。';
                        }

                        return true;
                    },
                    password: value => {
                        if (value.length < 8 || value.length > 32) {
                            return '密码长度必须在 8 到 32 个字符之间。';
                        }
                        
                        return true;
                    },
                    passwordMatch: value => {
                        return value == this.password || '两次输入的密码不一致。';
                    },
                    email: value => {
                        const pattern = /^(([^<>()[\]\\.,;:\s@"]+(\.[^<>()[\]\\.,;:\s@"]+)*)|(".+"))@((\[[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}])|(([a-zA-Z\-0-9]+\.)+[a-zA-Z]{2,}))$/;
                        return pattern.test(value) || '邮箱格式不正确。';
                    },
                },
            };
        },
        mounted: function() {
        },
        methods: {
            save() {
                if (!this.$refs.userForm.validate()) {
                    return;
                }

                this.saving = true;

                let data = {
                    userId: this.userId,
                    name: this.name,
                    email: this.email,
                    isAdmin: this.isAdmin
                };

                var url = '/users/update';
                if (this.userId === -1) {
                    data.password = this.password;
                    data.password_confirmation = this.passwordConfirmation;
                    url = '/users/create';
                }

                axios.post(url, data).then((response) => {
                    if (response.status !== 200) {
                        return;
                    }

                    this.saving = false;
                    this.errorMessage = 'success';
                    this.$emit('user-saved');
                }).catch((error) => {
                    this.saving = false;
                    this.errorMessage = '';

                    // add all error messages to the save result
                    if (error.response.data.errors === undefined) {
                        this.errorMessage = error.response.data.message;
                    } else {
                        var index = 0;
                        for (const [key, value] of Object.entries(error.response.data.errors)) {
                            if (index) {
                                this.errorMessage += '<br>';
                            }

                            this.errorMessage += value.join('<br>');

                            index ++;
                        }
                    }
                });
            },
            close() {
                this.$emit('input', false);
            }
        }
    }
</script>
