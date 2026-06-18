<template>
    <div id="user-manual-reading" class="ma-4 mb-16">
        <div class="subheader d-flex">阅读材料</div>
        <v-card outlined class="rounded-lg pa-4">
            LinguaCafe 会把所有文本组织成“书籍”和“章节”。这些名称只是为了方便管理，内容可以是书、新闻文章、播客逐字稿或电影字幕。你可以在“阅读材料”页面导入和管理这些内容。
        </v-card>

        <div class="subheader subheader-margin-top d-flex">阅读</div>
        <v-card outlined class="rounded-lg pa-4">
            第一次阅读文本时，未见过的新词会以黄色显示。点击不熟悉的单词后，可以从词典中选择或填写释义。保存后的单词会进入学习状态，并在之后导入的文本中继续高亮。

            <div class="mt-5">
                每个单词都有一个等级，用来表示你对它的熟悉程度。数字越接近 0，表示越熟悉。等级可以手动调整，也可以通过复习逐步变化。
            </div>

            <div class="mt-5">
                你也可以选择多个词创建“短语”。短语在阅读页中的使用方式和单词类似。
            </div>

            <div class="mt-5">
                读完整个章节后，点击“完成阅读”。系统会更新本章阅读统计，并按设置处理新词。
            </div>
        </v-card>

        <div class="subheader subheader-margin-top d-flex">悬浮词汇框</div>
        <v-card outlined class="rounded-lg pa-4">
            悬浮词汇框会在鼠标移到单词上时出现，用于快速查看单词或短语释义。
            
            <div class="mt-5">
                它会显示几类释义：
                <ul>
                    <li><v-icon small class="mr-1">mdi-account-edit</v-icon>你手动添加的释义。</li>
                    <li><v-icon small class="mr-1">mdi-list-box</v-icon>已启用词典中的精确匹配。</li>
                    <li><v-icon small class="mr-1">mdi-translate</v-icon>DeepL 翻译。</li>
                </ul>
            </div>

            如果只想显示手动添加的释义，可以在设置里关闭悬浮词汇框的词典搜索。

            <div class="mt-5">
                鼠标移到单词后会有短暂延迟才显示悬浮词汇框，这样可以减少不必要的请求并提升性能。你也可以在设置里调整这个延迟。
            </div>
        </v-card>
    </div>
</template>

<style scoped>
    #user-manual-reading {
        width: 100%;
        max-width: 1000px;
    }

    #user-manual-reading ul {
        padding-left: 0px;
    }

    #user-manual-reading ul li {
        list-style: none;
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
