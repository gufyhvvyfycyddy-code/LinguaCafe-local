<template>
    <div id="user-manual-vocabulary-import" class="ma-4 mb-16">
        <div class="subheader d-flex">词汇导入</div>
        <v-card outlined class="rounded-lg pa-4">
            如果你在使用 LinguaCafe 前已经认识一批单词，可以通过 .csv 文件导入。
            
            <div class="mt-5">
                导入路径：进入“词汇”页面，打开“数据”菜单，然后点击“导入”。在导入弹窗中选择 .csv 文件并设置选项。如果启用“只更新已有词汇”，系统不会新增单词，只会更新已经在 LinguaCafe 中出现过的词。
            </div>

            <div class="mt-5">
                .csv 文件可以按顺序包含这些列：
                <ul>
                    <li>Word：必填，不能包含空格。</li>
                    <li>Translation：可选，可留空。</li>
                    <li>Lemma：可选，可留空。</li>
                    <li>Reading：可选，可留空。</li>
                    <li>Lemma reading：可选，可留空。</li>
                    <li>Level：可选，但如果提供该列则不能留空。可用值：new、ignored、learned、1、2、3、4、5、6、7。</li>
                </ul>

                文件至少需要第一列。后续列可以按上面的顺序追加；没有提供的列不会更新数据库。如果提供了某一列但某一行留空，该字段会被更新为空值。

                <div class="my-5">
                    <v-icon large class="mr-2" color="warning">mdi-alert</v-icon>导入后的修改无法撤销。
                </div>

                导入完成后，页面会显示创建、更新和被拒绝的词数。
            </div>
        </v-card>
    </div>
</template>

<style scoped>
    #user-manual-vocabulary-import {
        width: 100%;
        max-width: 1000px;
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
