<template>
    <v-card outlined class="goal d-flex flex-column rounded-lg mr-4 mb-4">
        <v-card-title>
            {{ title }}
            <v-spacer></v-spacer>
            
            <v-btn 
                v-if="$props.name !== 'Reviews'"
                icon 
                @click="edit"
            >
                <v-icon>mdi-pencil</v-icon>
            </v-btn>
        </v-card-title>
        <v-card-text class="d-flex flex-column align-center">
            <v-progress-circular
                :size="progressCircleSize"
                :width="progressCircleWidth"
                :value="percentage"
                :rotate="270"
                :color="color"
                class="mb-5"
            >{{ todaysAchievedQuantity }} / {{ goalQuantity }}</v-progress-circular>
            
            <div v-if="name == 'Reading'">
                从任意阅读材料中阅读 {{ goalQuantity }} 个词。
            </div>

            <div v-if="name == 'Reviews'">
                复习今天到期的 {{ goalQuantity }} 张卡片。
            </div>

            <div v-if="name == 'New words'">
                高亮并保存 {{ goalQuantity }} 个新词用于复习。
            </div>
        </v-card-text>
        <v-spacer></v-spacer>
        <v-card-actions>
            <v-spacer></v-spacer> 
            <v-btn plain to="/review/false/-1/-1" v-if="name == 'Reviews'">开始复习</v-btn>
            <v-btn plain to="/books" v-if="name == 'Reading' || name == 'New words'">阅读材料</v-btn>
        </v-card-actions>
    </v-card>
</template>

<script>
    export default {
        data: function() {
            return {
                progressCircleSize: window.innerWidth <= 545 ? 200 : 180,
                progressCircleWidth: window.innerWidth <= 545 ? 22 : 20,
                titles: {
                    'review': '复习',
                    'read_words': '阅读',
                    'learn_words': '新词',
                    'Reviews': '复习',
                    'Reading': '阅读',
                    'New words': '新词',
                }
            }
        },
        computed: {
            title() {
                return this.titles[this.$props.name] || this.$props.name;
            }
        },
        props: {
            id: Number,
            name: String,
            goalQuantity: Number,
            todaysAchievedQuantity: Number,
            color: String,
            percentage: Number
        },
        mounted() {
        },
        methods: {
            edit() {
                this.$emit('edit', {
                    id: this.$props.id,
                    name: this.$props.name,
                    goalQuantity: this.$props.goalQuantity,
                    achievedQuantity: this.$props.todaysAchievedQuantity
                });
            }
        }
    }
</script>
