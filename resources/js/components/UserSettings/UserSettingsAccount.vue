<template>
    <div id="user-settings-account">
        <!-- Password change dialog -->
        <change-password-dialog
            v-model="passwordChangeDialog"
            @password-changed="passwordChanged"
        ></change-password-dialog>

        <!-- Delete language data -->
        <div class="subheader mt-4 mb-2 d-flex">
            账号
        </div>
        <v-card outlined class="rounded-lg pb-0 mb-32">
            <v-card-text>
                <v-row>
                    <v-col>
                        <b>用户名：</b> <br>
                        {{ this.$store.state.shared.userName }}
                    </v-col>
                    <v-col>
                        <b>邮箱：</b> <br>
                        {{ this.$store.state.shared.userEmail }}
                    </v-col>
                </v-row>
                <div class="d-flex mt-4">
                    <v-spacer />
                    
                    <!-- Change password button -->
                    <v-btn
                        v-if="!passwordChangeSuccess"
                        rounded
                        depressed
                        color="primary"
                        @click="passwordChangeDialog = true;"
                    >
                        <v-icon class="mr-2">mdi-lock-reset</v-icon>
                        修改密码
                    </v-btn>

                    <!-- Password changed success message -->
                    <v-alert class="mb-0" border="left" color="success" dense v-else>
                        密码已修改成功。
                    </v-alert>
                </div>
            </v-card-text>
        </v-card>

        <!-- Delete language data -->
        <div class="subheader mt-6 mb-2 d-flex">
            <v-icon large color="red" class="mr-2">
                mdi-alert
            </v-icon>
                
            删除学习语言数据
            <v-spacer />
            <v-img 
                eager
                class="border my-2 rounded" 
                :src="'/images/flags/' + $props.language.toLowerCase() + '.png'" 
                max-width="43" 
                height="28"
            ></v-img> 
        </div>
        <v-card outlined class="rounded-lg pb-0 mb-32" :loading="deleting">
            <v-card-text>
                此操作会删除你在 {{ formattedLanguageText }} 中的<b>全部</b>学习数据，其他学习语言的数据不会受影响。

                <div class="mt-4">
                    将删除的数据：
                    <ul>
                        <li>书籍</li>
                        <li>章节</li>
                        <li>词汇</li>
                        <li>短语</li>
                        <li>例句</li>
                        <li>已完成目标统计</li>
                    </ul>
                </div>

                <div id="delete-confirm-text" class="mt-6">
                    <label class="font-weight-bold">请输入“delete all my {{ $props.language }} data”确认删除</label>
                    <v-text-field 
                        v-model="confirmText"
                        filled
                        dense
                        rounded
                        hide-details
                        placeholder="确认删除"
                        width="200"
                    ></v-text-field>
                </div>

                <!-- Error message -->
                <v-alert
                    v-if="!deleting && deletionError"
                    class="rounded-lg mt-4 mb-0"
                    color="error"
                    type="error"
                    border="left"
                    dark
                >
                    删除 {{ formattedLanguageText }} 数据时发生错误。
                </v-alert>

                <!-- Success message -->
                <v-alert
                    v-if="!deleting && deletionSuccess"
                    class="rounded-lg mt-4 mb-0"
                    color="success"
                    type="success"
                    border="left"
                    dark
                >
                    {{ formattedLanguageText }} 数据已删除。
                </v-alert>
                
            </v-card-text>
            <v-card-actions>
                <v-spacer />
                <v-btn 
                    rounded 
                    depressed 
                    color="error" 
                    :disabled="deleting || confirmText !== `delete all my ${$props.language} data`"
                    @click="deleteLanguageData"
                >
                    <v-icon class="mr-2">mdi-delete</v-icon>
                    删除
                </v-btn>
            </v-card-actions>
        </v-card>
    </div>
</template>

<script>
    export default {
        data: function() {
            return {
                passwordChangeDialog: false,
                passwordChangeSuccess: false,
                confirmText: '',
                formattedLanguageText: this.$props.language.charAt(0).toUpperCase() + this.$props.language.slice(1),
                deleting: false,
                deletionError: false,
                deletionSuccess: false,
            }
        },
        props: {
            language: String,
        },
        mounted() {
           
        },
        methods: {
            passwordChanged() {
                this.passwordChangeDialog = false
                this.passwordChangeSuccess = true
            },
            deleteLanguageData() {
                this.deleting = true;
                this.deletionSuccess = false;
                this.deletionError = false;

                axios.delete(`/users/delete-language-data/${this.$props.language}`).then((response) => {
                    this.deletionSuccess = true;
                    this.deleting = false;
                }).catch((error) => {
                    this.deletionError = true;
                    this.deleting = false;
                });
            }
        }
    }
</script>
