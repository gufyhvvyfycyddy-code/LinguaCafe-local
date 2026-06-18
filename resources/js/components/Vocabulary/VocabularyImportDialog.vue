<template>
    <v-dialog v-model="value" persistent scrollable width="800px">
        <v-card 
            id="vocabulary-export-dialog" 
            class="rounded-lg"
            :loading="loading"
            min-height="400px"
        >

            <!-- Title bar -->
            <v-card-title>
                <span class="text-h5" v-if="importResult === null">词汇导入</span>
                <span class="text-h5" v-if="importResult !== null">导入结果</span>
                
                <v-spacer></v-spacer>
                <v-btn icon @click="close">
                    <v-icon>mdi-close</v-icon>
                </v-btn>
            </v-card-title>

            <!-- Import card content-->
            <v-card-text>
                <template v-if="importResult === null || importResult.error">
                    <!-- Import information -->
                    <v-alert dark border="left" type="info" color="primary" v-if="!loading">
                        导入前请先阅读 <a href="/user-manual/vocabulary-import"><v-icon small class="mr-0.5">mdi-file</v-icon>用户手册</a>。
                    </v-alert>

                    <!-- Csv file -->
                    <label class="font-weight-bold">CSV 文件</label>
                    <v-file-input
                        v-model="importFile"
                        filled
                        dense
                        rounded
                        persistent-hint
                        hint="支持格式：.csv"
                        ref="importFile"
                        accept=".csv"
                        placeholder="选择导入文件"
                        prepend-icon="mdi-file-delimited"
                        class="mb-4"
                        :disabled="loading"
                        :rules="[rules.importFileRule]"
                    ></v-file-input>

                    <!-- Delimiter -->
                    <label class="font-weight-bold">分隔符</label>
                    <v-text-field 
                        v-model="delimiter"
                        filled
                        dense
                        rounded
                        hide-details
                        max-length="1"
                    ></v-text-field>

                    <!-- Skip first row -->
                    <v-switch
                        v-model="skipHeader"
                        hide-details
                        class="my-1 mt-6"
                        color="primary"
                        label="跳过第一行"
                        :disabled="loading"
                    ></v-switch>

                    <!-- Only update -->
                    <v-switch
                        v-model="onlyUpdate"
                        hide-details
                        class="my-2"
                        color="primary"
                        label="只更新已有词汇"
                        :disabled="loading"
                    ></v-switch>

                    <!-- Import information -->
                    <v-alert dark class="mt-4" border="left" type="error" color="error" v-if="!loading && importResult !== null && importResult.error">
                        导入失败，请确认文件格式正确。
                    </v-alert>
                </template>

                <!-- Importing message -->
                <v-alert class="mt-4" dark border="left" type="info" color="primary" v-if="loading">
                    正在导入所选文件，可能需要一点时间...
                </v-alert>

                <!-- Import success -->
                <template v-if="importResult !== null && !importResult.error">              
                    <v-simple-table class="no-hover border rounded-lg mt-4">
                        <tbody>
                            <tr v-if="importResult.createdWords">
                                <th>新增词汇：</th>
                                <th>{{ importResult.createdWords }}</th>
                            </tr>
                            <tr v-if="importResult.updatedWords">
                                <th>更新词汇：</th>
                                <th>{{ importResult.updatedWords }}</th>
                            </tr>
                            <tr v-if="importResult.rejectedWords">
                                <th>跳过词汇：</th>
                                <th>{{ importResult.rejectedWords }}</th>
                            </tr>
                        </tbody>
                    </v-simple-table>
                </template>
            </v-card-text>

            <!-- Action buttons -->
            <v-card-actions>
                <v-spacer></v-spacer>

                <!-- Import and cancel buttons -->
                <template v-if="importResult === null || importResult.error">
                    <v-btn rounded text :disabled="loading" @click="close">取消</v-btn>
                    <v-btn 
                        rounded 
                        depressed
                        color="primary"
                        :disabled="!importFileValid || loading"
                        @click="importFromCsv"
                    >导入</v-btn>
                </template>

                <!-- Close button -->
                <template v-if="importResult !== null && !importResult.error">
                    <v-btn rounded text @click="close">关闭</v-btn>
                </template>
            </v-card-actions>
        </v-card>
    </v-dialog>
</template>

<script>
    export default {
        props: {
            value : Boolean,
        },
        emits: ['input'],
        data: function() {
            return {
                importFile: null,
                importFileValid: false,
                importResult: null,
                loading: false,
                skipHeader: false,
                onlyUpdate: true,
                delimiter: '|',
                rules: {
                    importFileRule: (value) => {
                        if (value === null || value === undefined) {
                            this.importFileValid = false;
                            return '请选择文件。';
                        }
                        
                        let extension = value.name.split('.');
                        extension = extension[extension.length - 1];
                        
                        if (extension !== 'csv') {
                            this.importFileValid = false;
                            return '请选择 .csv 文件。';
                        }

                        this.importFileValid = true;
                        return true;
                    }
                }
            };
        },
        mounted: function() {
        },
        methods: {
            importFromCsv() {
                // validate                
                if (!this.$refs.importFile.validate()) {
                    return;
                }

                // create form data
                var formData = new FormData();
                formData.set('importFile', this.importFile);
                formData.append("skipHeader", this.skipHeader);
                formData.append("onlyUpdate", this.onlyUpdate);
                formData.append("delimiter", this.delimiter);

                this.loading = true;
                this.importResult = null;
                axios.post('/vocabulary/import-from-csv', formData, {
                    headers: {
                        'Content-Type': 'multipart/form-data'
                    }
                }).then((response) => {
                    this.loading = false;
                    this.importFile = null;
                    this.importResult = {
                        createdWords: response.data.createdWords,
                        updatedWords: response.data.updatedWords,
                        rejectedWords: response.data.rejectedWords,
                        error: false
                    };
                    
                }).catch((error) => {
                    this.loading = false;
                    this.importFile = null;
                    this.importResult = {
                        createdWords: 0,
                        updatedWords: 0,
                        rejectedWords: 0,
                        error: true
                    };
                });
            },
            close() {
                this.$emit('input', false);
            }
        }
    }
</script>
