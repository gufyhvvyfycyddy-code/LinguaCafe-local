<template>
    <div id="user-manual-languages" class="ma-4 mb-16">
        <!-- Language info -->
        <div class="subheader d-flex">语言</div>
        <v-card outlined class="rounded-lg pa-4">
            LinguaCafe 会按当前选择的学习语言隔离数据。也就是说，你在一种语言中的操作不会影响其他语言的数据。
            所以第一次使用时，请先选择你的学习目标语言。你可以点击左下角的“学习语言”切换当前语言。

            <div class="mt-5">
                <b>词元生成：</b>导入文本时，文本处理器会为支持的语言自动生成词典形。
            </div>
            
            <div class="mt-2">
                <b>性别标记：</b>对于有语法性别且受支持的语言，LinguaCafe 会根据词语性别给名词附加额外信息。
            </div>
        </v-card>

        <!-- Supported language list -->
        <div class="subheader d-flex mt-4">支持的语言</div>
        <v-simple-table id="user-manual-language-table" class="rounded-lg border no-hover">
            <thead>
                <tr>
                    <th>旗帜</th>
                    <th>语言</th>
                    <th>DeepL</th>
                    <th>词元生成</th>
                    <th>性别标记</th>
                    <th>词典</th>
                </tr>
            </thead>
            <tbody>
                <!-- Chinese -->
                <tr>
                    <td>
                        <img src="/images/flags/chinese.png" width='25' />
                    </td>
                    <td>Chinese</td>
                    <td>&check;</td>
                    <td></td>
                    <td></td>
                    <td>wiktionary, cc-cedict, handedict</td>
                </tr>

                <!-- Czech -->
                <tr>
                    <td>
                        <img src="/images/flags/czech.png" width='25' />
                    </td>
                    <td>Czech</td>
                    <td>&check;</td>
                    <td></td>
                    <td></td>
                    <td>wiktionary, dict cc</td>
                </tr>

                <!-- Dutch -->
                <tr>
                    <td>
                        <img src="/images/flags/dutch.png" width='25' />
                    </td>
                    <td>Dutch</td>
                    <td>&check;</td>
                    <td>&check;</td>
                    <td></td>
                    <td>dict cc</td>
                </tr>

                <!-- English -->
                <tr>
                    <td>
                        <img src="/images/flags/english.png" width='25' />
                    </td>
                    <td>English</td>
                    <td>&check;</td>
                    <td>&check;</td>
                    <td></td>
                    <td>dict cc</td>
                </tr>

                <!-- Finnish -->
                <tr>
                    <td>
                        <img src="/images/flags/finnish.png" width='25' />
                    </td>
                    <td>Finnish</td>
                    <td>&check;</td>
                    <td>不够准确</td>
                    <td></td>
                    <td>wiktionary, dict cc</td>
                </tr>
                
                <!-- French -->
                <tr>
                    <td>
                        <img src="/images/flags/french.png" width='25' />
                    </td>
                    <td>French</td>
                    <td>&check;</td>
                    <td>&check;</td>
                    <td></td>
                    <td>wiktionary, dict cc</td>
                </tr>

                <!-- German -->
                <tr>
                    <td>
                        <img src="/images/flags/german.png" width='25' />
                    </td>
                    <td>German</td>
                    <td>&check;</td>
                    <td>&check;</td>
                    <td>&check;</td>
                    <td>wiktionary, dict cc</td>
                </tr>

                <!-- Greek -->
                <tr>
                    <td>
                        <img src="/images/flags/greek.png" width='25' />
                    </td>
                    <td>Greek</td>
                    <td>&check;</td>
                    <td>&check;</td>
                    <td></td>
                    <td>wiktionary, dict cc</td>
                </tr>

                <!-- Italian -->
                <tr>
                    <td>
                        <img src="/images/flags/italian.png" width='25' />
                    </td>
                    <td>Italian</td>
                    <td>&check;</td>
                    <td>&check;</td>
                    <td></td>
                    <td>wiktionary, dict cc</td>
                </tr>

                <!-- Japanese -->
                <tr>
                    <td>
                        <img src="/images/flags/japanese.png" width='25' />
                    </td>
                    <td>Japanese</td>
                    <td>&check;</td>
                    <td>&check;</td>
                    <td></td>
                    <td>jmdict, wiktionary</td>
                </tr>

                <!-- Korean -->
                <tr>
                    <td>
                        <img src="/images/flags/korean.png" width='25' />
                    </td>
                    <td>Korean</td>
                    <td>&check;</td>
                    <td>&check;</td>
                    <td></td>
                    <td>wiktionary, kengdic</td>
                </tr>

                <!-- Latin -->
                <tr>
                    <td>
                        <img src="/images/flags/latin.png" width='25' />
                    </td>
                    <td>Latin</td>
                    <td></td>
                    <td></td>
                    <td></td>
                    <td>wiktionary</td>
                </tr>

                <!-- Norwegian -->
                <tr>
                    <td>
                        <img src="/images/flags/norwegian.png" width='25' />
                    </td>
                    <td>Norwegian</td>
                    <td>&check;</td>
                    <td>&check;</td>
                    <td>&check;</td>
                    <td>wiktionary, dict cc</td>
                </tr>
                <!-- Russian -->
                <tr>
                    <td>
                        <img src="/images/flags/russian.png" width='25' />
                    </td>
                    <td>Russian</td>
                    <td>&check;</td>
                    <td>&check;</td>
                    <td></td>
                    <td>wiktionary, dict cc</td>
                </tr>
                <!-- Spanish -->
                <tr>
                    <td>
                        <img src="/images/flags/spanish.png" width='25' />
                    </td>
                    <td>Spanish</td>
                    <td>&check;</td>
                    <td>&check;</td>
                    <td></td>
                    <td>wiktionary, dict cc</td>
                </tr>
                <!-- Swedish -->
                <tr>
                    <td>
                        <img src="/images/flags/swedish.png" width='25' />
                    </td>
                    <td>Swedish</td>
                    <td>&check;</td>
                    <td>&check;</td>
                    <td></td>
                    <td>dict cc</td>
                </tr>
                <!-- Ukrainian -->
                <tr>
                    <td>
                        <img src="/images/flags/ukrainian.png" width='25' />
                    </td>
                    <td>Ukrainian</td>
                    <td>&check;</td>
                    <td></td>
                    <td></td>
                    <td>wiktionary</td>
                </tr>
                <!-- Welsh -->
                <tr>
                    <td>
                        <img src="/images/flags/welsh.png" width='25' />
                    </td>
                    <td>Welsh</td>
                    <td></td>
                    <td></td>
                    <td></td>
                    <td>wiktionary, eurfa</td>
                </tr>

                
            </tbody>
        </v-simple-table>
    </div>
</template>

<style scoped>
    #user-manual-languages {
        width: 100%;
        max-width: 1000px;
    }

    #user-manual-language-table {
        width: 100%;
    }
</style>

<script>
    export default {
        data: function() {
            return {
            }
        },
        props: {
        },
        methods: {
        }
    }
</script>
 
