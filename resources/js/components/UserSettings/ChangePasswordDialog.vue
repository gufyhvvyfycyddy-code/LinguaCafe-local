<template>
    <v-dialog v-model="value" persistent max-width="600px" height="300px">
        <v-card class="rounded-lg">
            <v-card-title>
                <v-icon class="mr-2">mdi-lock-reset</v-icon>修改密码
                <v-spacer></v-spacer>
                <v-btn icon @click="close"> 
                    <v-icon>mdi-close</v-icon>
                </v-btn>
            </v-card-title>
            
            <!-- Change password form -->
            <v-card-text class="pt-4 pb-6">
                <v-form v-model="isFormValid" ref="userForm">
                    <template>
                        <!-- Password -->
                        <label class="font-weight-bold">新密码</label>
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
                        <label class="font-weight-bold">确认新密码</label>
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
                </v-form>
                
                <v-alert
                    v-if="saveResult !== '' && saveResult !== 'success'"
                    class="rounded-lg mt-4 mb-0"
                    color="error"
                    type="error"
                    border="left"
                    dark
                >
                    <div style="white-space: pre-line;">{{ saveResult }}</div>
                </v-alert>

                <v-alert
                    v-if="saveResult == 'success'"
                    class="rounded-lg mt-4 mb-0"
                    color="success"
                    type="success"
                    border="left"
                    dark
                >
                    密码已修改成功！
                </v-alert>
                
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
                    :disabled="!isFormValid || saving || saveResult == 'success'"
                    :loading="saving"
                >
                    修改密码
                </v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>
</template>

<script>
    import { requestValidationErrorMessage } from '../../services/UiTextService';

    export default {
        props: {
            value : Boolean
        },
        emits: ['input'],
        data: function() {
            return {
                isFormValid: false,
                saving: false,
                password: '',
                passwordConfirmation: '',
                saveResult: '',

                rules: {
                    password: value => {
                        if (value.length < 8 || value.length > 32) {
                            return '密码长度必须在 8 到 32 个字符之间。';
                        }
                        
                        return true;
                    },
                    passwordMatch: value => {
                        return value == this.password || '两次输入的密码不一致。';
                    }
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
                axios.post('/users/update-password', {
                    password: this.password,
                    password_confirmation: this.passwordConfirmation
                }).then((response) => {
                    if (response.status !== 200) {
                        return;
                    }

                    this.saving = false;
                    this.saveResult = 'success';
                    
                    setTimeout(() => {
                        this.$emit('password-changed');
                    }, 1000);
                }).catch((error) => {
                    this.saving = false;
                    this.saveResult = requestValidationErrorMessage(error, '密码修改失败，请稍后重试。');
                });
            },
            close() {
                this.$emit('input', false);
            }
        }
    }
</script>