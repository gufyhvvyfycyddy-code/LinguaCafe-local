    <template>
    <v-dialog v-model="value" persistent max-width="500px" height="300px">
        <v-card class="rounded-lg" :loading="uninstalling">
            <v-card-title>
                <v-icon class="mr-2" color="error">mdi-delete</v-icon>
                <span class="text-h5">卸载语言</span>
                
                <v-spacer></v-spacer>
                <v-btn icon @click="close" :disabled="uninstalling">
                    <v-icon>mdi-close</v-icon>
                </v-btn>
            </v-card-title>
            
            <v-card-text class="pt-4 pb-6">
                <!-- Install confirmation message -->
                <template v-if="!uninstalling && uninstallResult !== 'success'">
                    确定要卸载所有可安装语言吗？
                </template>

                <!-- Success message -->
                <v-alert
                    v-if="!uninstalling && uninstallResult === 'success'"
                    dense
                    class="rounded-lg"
                    color="success"
                    type="success"
                    border="left"
                >
                    语言已成功卸载。
                </v-alert>

                <!-- Error message -->
                <v-alert
                    v-if="!uninstalling && uninstallResult === 'error'"
                    dense
                    class="rounded-lg mt-4"
                    color="error"
                    type="error"
                    border="left"
                >
                    卸载语言时发生错误。请等待几秒后重试。
                </v-alert>

                <!-- Installation in progress message -->
                <template v-if="uninstalling">
                    正在卸载语言，可能需要几分钟...
                </template>
            </v-card-text>

            <v-card-actions>
                <v-spacer></v-spacer>
                
                <!-- Cancel button -->
                <v-btn rounded text @click="close" :disabled="uninstalling" v-if="uninstallResult !== 'success'">
                    取消
                </v-btn>
                
                <!-- Close button -->
                <v-btn rounded text @click="close" :disabled="uninstalling" v-if="uninstallResult === 'success'">
                    关闭
                </v-btn>
                
                <!-- Install button -->
                <v-btn rounded depressed color="error" @click="uninstall" :disabled="uninstalling" v-if="uninstallResult !== 'success'">
                    <v-icon class="mr-1">mdi-delete</v-icon>
                    卸载
                </v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>
</template>

<script>
    export default {
        props: {
            value : Boolean
        },
        emits: ['input'],
        data: function() {
            return {
                uninstallResult: '',
                uninstalling: false,
            };
        },
        watch: {
            value: function() {
                this.uninstallResult = '';
                this.uninstalling = false;
            }
        },
        mounted: function() {
        },
        methods: {
            uninstall() {
                this.uninstalling = true;
                axios.delete('/languages/installed/delete').then((response) => {
                    this.uninstalling = false;
                    if (response.status === 200 || response.status === 202) {
                        this.uninstallResult = 'success';
                        window.location.href = "/admin/languages";
                    } else {
                        this.uninstallResult = 'error';
                    }
                }).catch((error) => {
                    this.uninstalling = false;
                    this.uninstallResult = 'error';
                });
            },
            close() {
                this.$emit('input', false);
            }
        }
    }
</script>
