<template>
    <v-card 
        id="custom-dictionary-import-dialog" 
        class="rounded-lg"
        :loading="configFileLoading || fileTestLoading || importing"
    >
        <!-- Title bar -->
        <v-card-title>
            <span class="text-h5">导入词典</span>
            <v-spacer></v-spacer>
            <v-btn icon @click="close"> 
                <v-icon>mdi-close</v-icon>
            </v-btn>
        </v-card-title>

        <!-- Card text -->
        <v-card-text>
            <v-stepper id="import-stepper" v-model="stepperPage" elevation="0" class="pb-0" min-height="620px">
                <!-- Stepper header -->
                <v-stepper-header>
                    <v-stepper-step :complete="stepperPage > 1" step="1">
                        词典信息
                        <small>名称、数据库表、语言</small>
                    </v-stepper-step>
                    <v-divider/>
                    
                    <v-stepper-step :complete="stepperPage > 2" step="2">
                        词典文件
                        <small>选择词典文件</small>
                    </v-stepper-step>
                    <v-divider/>

                    <v-stepper-step :complete="stepperPage > 3" step="3">
                        概览
                        <small>确认所有数据正确</small>
                    </v-stepper-step>

                    <v-stepper-step :complete="stepperPage > 4" step="4">
                        完成
                    </v-stepper-step>
                </v-stepper-header>

                <v-stepper-items>
                    <!-- Step 1: dictionary data -->
                    <v-stepper-content step="1">
                        <div v-if="!configFileLoading">
                            <!-- Source language -->
                            <label class="font-weight-bold">源语言</label>
                            <v-select
                                v-model="dictionary.sourceLanguage"
                                :items="sourceLanguages"
                                item-value="name"
                                placeholder="源语言"
                                dense
                                filled
                                rounded
                                @change="updateDatabaseName"
                            >
                                <template v-slot:selection="{ item, index }">
                                    <img class="mr-2 border" :src="'/images/flags/' + item.name + '.png'" width="40" height="26">
                                    <span class="text-capitalize">{{ item.name }}</span>
                                </template>
                                <template v-slot:item="{ item }">
                                    <img class="mr-2 border" :src="'/images/flags/' + item.name + '.png'" width="40" height="26">
                                    <span class="text-capitalize">{{ item.name }}</span>
                                </template>
                            </v-select>

                            <!-- Target language -->
                            <label class="font-weight-bold">目标语言</label>
                            <v-select
                                v-model="dictionary.targetLanguage"
                                :items="targetLanguages"
                                item-value="name"
                                placeholder="目标语言"
                                dense
                                filled
                                rounded
                                @change="updateDatabaseName"
                            >
                                <template v-slot:selection="{ item, index }">
                                    <img class="mr-2 border" :src="'/images/flags/' + item.name + '.png'" width="40" height="26">
                                    <span class="text-capitalize">{{ item.name }}</span>
                                </template>
                                <template v-slot:item="{ item }">
                                    <img class="mr-2 border" :src="'/images/flags/' + item.name + '.png'" width="40" height="26">
                                    <span class="text-capitalize">{{ item.name }}</span>
                                </template>
                            </v-select>

                            <!-- Dictionary name -->
                            <label class="font-weight-bold">词典名称</label>
                            <v-text-field 
                                v-model="dictionary.name"
                                filled
                                dense
                                rounded
                                placeholder="词典名称"
                                :rules="rules.dictionaryName"
                                @keyup="updateDatabaseName"
                                @change="updateDatabaseName"
                                maxlength="16"
                            ></v-text-field>
                            
                            <!-- Database table name -->
                            <label class="font-weight-bold">数据库表名</label>
                            <v-text-field 
                                v-model="dictionary.databaseName"
                                class="mb-3"
                                color="black"
                                filled
                                dense
                                rounded
                                persistent-hint
                                hint="只能包含小写字母、数字和下划线。"
                                placeholder="database_name"
                                :prefix="dictionary.databasePrefix"
                                :rules="rules.databaseName"
                                maxlength="28"
                            ></v-text-field>

                            <!-- Display color -->
                            <label class="font-weight-bold">显示颜色</label>
                            <v-menu
                                v-model="colorPicker"
                                width="290px"
                                offset-y
                                nudge-top="-10px"
                                right
                                :close-on-content-click="false"
                            >
                                <template v-slot:activator="{ on, attrs }">
                                    <v-card
                                        class="border"
                                        outlined
                                        :color="dictionary.color"
                                        width="64px"
                                        height="32px"
                                        @click="colorPicker = !colorPicker;"
                                    ></v-card>
                                </template>
                                <v-color-picker hide-inputs v-model="dictionary.color" />
                            </v-menu>
                        </div>
                    </v-stepper-content>

                    <!-- Step 2: dictionary file -->
                    <v-stepper-content step="2">
                        <v-alert
                            class="rounded-lg"
                            color="primary"
                            type="info"
                            border="left"
                            dark
                        >
                            你可以从 .csv 文件导入自定义词典。文件必须有两列：第一列是单词，第二列是翻译。
                            单个单词可以用英文分号 ";" 分隔多个翻译。例如：<br><br>

                            Word|Translation<br>
                            å gjøre|to do;to make<br>
                            å bygge|to build;to construct<br>
                            å elske|to love
                        </v-alert>
                        
                        <label class="font-weight-bold">表头</label>
                        <v-switch
                            v-model="dictionary.csvSkipHeader"
                            class="mt-0"
                            color="primary"
                            label="跳过第一行"
                            @change="fileInputChange"
                        ></v-switch>

                        <label class="font-weight-bold">分隔符</label>
                        <v-text-field 
                            v-model="dictionary.csvDelimiter"
                            filled
                            dense
                            rounded
                            placeholder="分隔符"
                            @change="fileInputChange"
                            maxlength="1"
                            :rules="rules.csvDelimiter"
                        ></v-text-field>

                        <label class="font-weight-bold">词典文件</label>
                        <v-file-input
                            v-model="dictionary.file"
                            filled
                            dense
                            rounded
                            placeholder="选择文件"
                            accept=".csv"
                            prepend-icon="mdi-file-delimited"
                            @change="fileInputChange"
                        ></v-file-input>
                        <v-alert
                            v-if="!fileTestLoading && fileTestError"
                            class="rounded-lg"
                            color="error"
                            type="error"
                            border="left"
                            dark
                        >
                            读取文件时发生错误。请确认文件格式正确后重试。
                        </v-alert>
                        <v-alert
                            v-if="dictionary.file && !fileTestLoading && !fileTestError"
                            class="rounded-lg"
                            color="success"
                            type="success"
                            border="left"
                            dark
                        >
                            文件检测通过，共包含 {{ fileRecordCount }} 条记录。
                        </v-alert>
                    </v-stepper-content>
                    
                    <!-- Step 3: overview -->
                    <v-stepper-content step="3">
                        <label class="font-weight-bold">概览</label>
                        <v-simple-table class="border no-hover rounded-lg" v-if="dictionary.file">
                            <tbody>
                                <tr>
                                    <td class="font-weight-bold">源语言：</td>
                                    <td>
                                        <img 
                                            :src="'/images/flags/' + dictionary.sourceLanguage.toLowerCase() + '.png'" 
                                            class="mr-2 border" 
                                            width="40" 
                                            height="26"
                                        />
                                        {{ dictionary.sourceLanguage }}
                                    </td>
                                </tr>
                                <tr>
                                    <td class="font-weight-bold">目标语言：</td>
                                    <td>
                                        <img 
                                            :src="'/images/flags/' + dictionary.targetLanguage.toLowerCase() + '.png'" 
                                            class="mr-2 border" 
                                            width="40" 
                                            height="26"
                                        />
                                        {{ dictionary.targetLanguage }}
                                    </td>
                                </tr>
                                <tr>
                                    <td class="font-weight-bold">词典名称：</td>
                                    <td>{{ dictionary.name }} ({{ dictionary.databasePrefix + dictionary.databaseName }})</td>
                                </tr>
                                <tr>
                                    <td class="font-weight-bold">颜色：</td>
                                    <td>
                                        <v-card
                                            class="border"
                                            outlined
                                            :color="dictionary.color"
                                            width="48px"
                                            height="26px"
                                        ></v-card>
                                    </td>
                                </tr>
                                <tr>
                                    <td class="font-weight-bold">文件：</td>
                                    <td>{{ dictionary.file.name }}</td>
                                </tr>
                                <tr>
                                    <td class="font-weight-bold">跳过 CSV 表头：</td>
                                    <td>{{ dictionary.csvSkipHeader ? '是' : '否' }}</td>
                                </tr>
                                <tr>
                                    <td class="font-weight-bold">CSV 分隔符：</td>
                                    <td>{{ dictionary.csvDelimiter }}</td>
                                </tr>
                            </tbody>
                        </v-simple-table>

                        <label class="font-weight-bold mt-4">示例</label>
                        <v-simple-table dense class="no-hover border rounded-lg">
                            <thead>
                                <tr>
                                    <th class="text-center">单词</th>
                                    <th class="text-center">翻译</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="(sample, index) in fileTestSample" :key="index">
                                    <td class="text-center">{{ sample.word }}</td>
                                    <td class="text-center">{{ sample.translation }}</td>
                                </tr>
                            </tbody>
                        </v-simple-table>
                    </v-stepper-content>

                    <!-- Step 4: finish -->
                    <v-stepper-content step="4">
                        <div v-if="importResult == 'success'">
                            <v-alert
                                class="rounded-lg"
                                color="success"
                                type="success"
                                border="left"
                                dark
                            >
                                词典已成功导入。
                            </v-alert>
                        </div>

                        <div v-if="importResult !== 'success'">
                            <v-alert
                                class="rounded-lg"
                                color="error"
                                type="error"
                                border="left"
                                dark
                            >
                                导入词典时发生错误。
                            </v-alert>
                        </div>
                        
                    </v-stepper-content>

                </v-stepper-items>
            </v-stepper>
        </v-card-text>
        
        <!-- Action bar -->
        <v-card-actions>
            <v-spacer></v-spacer>
            <v-btn 
                v-if="stepperPage == 1"
                rounded 
                text 
                @click="backToDictionaries"
            >返回</v-btn>

            <v-btn
                v-if="(stepperPage > 1 && stepperPage < 4) || (stepperPage == 4 && importResult !== 'success')"
                rounded 
                text 
                @click="stepperPage --;"
            >
                返回
            </v-btn>
            <v-btn
                v-if="stepperPage == 4 && importResult == 'success'"
                rounded 
                text 
                @click="close"
            >
                关闭
            </v-btn>
            <v-btn
                v-if="stepperPage < 3"
                rounded
                depressed
                color="primary"
                :disabled="(stepperPage == 1 && (!this.dictionary.nameValidated || !this.dictionary.databaseValidated)) ||
                    (stepperPage == 2 && (fileTestLoading || fileTestError || !this.dictionary.file))"
                :loading="stepperPage == 2 && fileTestLoading"
                @click="stepperPage ++;"
            >
                继续
            </v-btn>

            <v-btn
                v-if="stepperPage == 3"
                rounded
                depressed
                color="primary"
                @click="importDictionary"
                :loading="importing"
                :disabled="importing"
            >
                导入
            </v-btn>
        </v-card-actions>
    </v-card>
</template>

<script>
    export default {
        props: {
            language: String
        },
        data: function() {
            return {
                databaseNameLanguageCodes: null,
                configFileLoading: true,
                stepperPage: 1,
                importing: false,
                importResult: '',
                fileTestLoading: false,
                fileTestError: false,
                fileTestSample: [],
                fileRecordCount: -1,
                colorPicker: false,
                dictionary: {
                    name: '',
                    databaseName: '',
                    databasePrefix: 'dict_jp_',
                    color: '#B59686',
                    sourceLanguage: this.$props.language,
                    targetLanguage: 'english',
                    file: null,
                    csvDelimiter: '|',
                    csvSkipHeader: false,


                    nameValidated: false,
                    databaseValidated: false,
                },

                sourceLanguages: [],
                targetLanguages: [],

                rules: {
                    dictionaryName: [
                        value => {
                            if (!value.length) {
                                this.dictionary.nameValidated = false;
                                return 'You must type in a dictionary name!';
                            }

                            if (value.toLowerCase().includes('deepl')) {
                                this.dictionary.nameValidated = false;
                                return 'Cannot contain the word "deepl".';
                            }

                            if (value.toLowerCase() === 'jmdict') {
                                this.dictionary.nameValidated = false;
                                return 'Cannot be named jmdict.';
                            }

                            this.dictionary.nameValidated = true;
                            return true;
                        }
                    ],
                    databaseName: [
                        value => {
                            if (!value.length) {
                                this.dictionary.databaseValidated = false;
                                return 'You must type in a database name!';
                            }

                            let regex = /^[a-z0-9_]+$/;
                            if (!regex.test(value)) {
                                this.dictionary.databaseValidated = false;
                                return 'Can only contain lowercase letters, numbers and underscore!';
                            }

                            this.dictionary.databaseValidated = true;
                            return true;
                        }
                    ],
                    csvDelimiter: [
                        value => {
                            if (!value.length) {
                                return 'You must choose a delimiter character.';
                            }

                            if (value == ';') {
                                return 'You cannot use ; character as a delimiter, because it is used to separate multiple translations.';
                            }

                            return true;
                        }
                    ],
                }
            };
        },
        mounted: function() {
            axios.all([
                axios.get('/config/get/linguacafe.languages.supported_languages'),
                axios.get('/config/get/linguacafe.languages.supported_target_languages'),
                axios.get('/config/get/linguacafe.languages.database_name_language_codes')
            ]).then(axios.spread((response1, response2, response3) => {
                this.configFileLoading = false;

                // add supported source languages
                for (let languageIndex = 0; languageIndex < response1.data.length; languageIndex++) {
                    this.sourceLanguages.push({
                        name: response1.data[languageIndex].toLowerCase(),
                        selected: false
                    });
                }

                // add supported target languages
                for (let languageIndex = 0; languageIndex < response2.data.length; languageIndex++) {
                    this.targetLanguages.push({
                        name: response2.data[languageIndex].toLowerCase(),
                        selected: false
                    });
                }

                // update database name
                this.databaseNameLanguageCodes = response3.data;
                this.updateDatabaseName();
            }));
        },
        methods: {
            updateDatabaseName() {
                this.dictionary.databasePrefix = 'dict_' + this.databaseNameLanguageCodes[this.dictionary.sourceLanguage] + '_';
                this.dictionary.databaseName = this.dictionary.name.split(' ').join('_').toLowerCase().replace(/[^a-z0-9_]/g, '');

                // remove underscores from the start of the text
                while (this.dictionary.databaseName[0] == '_') {
                    this.dictionary.databaseName = this.dictionary.databaseName.slice(1);
                }

                // remove underscores from the end of the text
                while (this.dictionary.databaseName[this.dictionary.databaseName.length - 1] == '_') {
                    this.dictionary.databaseName = this.dictionary.databaseName.slice(0, -1);
                }
                
            },
            fileInputChange() {
                if (!this.dictionary.csvDelimiter.length || this.dictionary.csvDelimiter == ';') {
                    this.fileTestError = false;
                    return;
                }
                
                if (this.dictionary.file === null || this.dictionary.file === undefined) {
                    this.dictionary.file = null;
                    this.fileTestError = false;
                    return;
                }
                
                this.fileTestSample = [];
                this.fileTestLoading = true;

                let formData = new FormData();
                formData.append("dictionary", this.dictionary.file);
                formData.append("delimiter", this.dictionary.csvDelimiter);
                formData.append("skipHeader", this.dictionary.csvSkipHeader);

                axios.post('/dictionaries/test-csv-file', formData).then((response) => {
                    this.fileTestError = response.data.status !== 'success';
                    this.fileTestLoading = false;

                    if (this.fileTestError) {
                        this.dictionary.file = null;
                    } else {
                        this.fileTestSample = response.data.sample;
                        this.fileRecordCount = response.data.recordCount;
                    }
                });
            },
            importDictionary() {
                this.importing = true;
                let formData = new FormData();
                formData.append("dictionary", this.dictionary.file);
                formData.append("delimiter", this.dictionary.csvDelimiter);
                formData.append("skipHeader", this.dictionary.csvSkipHeader);
                formData.append("dictionaryName", this.dictionary.name);
                formData.append("databaseName", this.dictionary.databasePrefix + this.dictionary.databaseName);
                formData.append("sourceLanguage", this.dictionary.sourceLanguage.toLowerCase());
                formData.append("targetLanguage", this.dictionary.targetLanguage.toLowerCase());
                formData.append("color", this.dictionary.color);

                axios.post('/dictionaries/import-csv-file', formData).then((response) => {
                    this.importing = false;
                    this.stepperPage ++;
                    if (response.status === 200) {
                        this.importResult = 'success';
                    } else {
                        this.importResult = 'error';
                    }
                }).catch((error) => {
                    this.importing = false;
                    this.stepperPage ++;
                    this.importResult = 'Error';
                });
            },
            backToDictionaries() {
                this.$emit('back-to-dictionaries');
            },
            close() {
                if (this.stepperPage == 4) {
                    this.$emit('import-finished');
                }

                this.$emit('close');
            }
        }
    }
</script>
