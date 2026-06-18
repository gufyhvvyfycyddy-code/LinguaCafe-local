<template>
    <v-container v-if="$store.getters['shared/userAdmin']">
        <v-tabs v-model="tab" background-color="foreground" class="rounded-lg border overflow-hidden" @change="tabChanged">
            <v-tab>概览</v-tab>
            <v-tab>用户</v-tab>
            <v-tab>语言</v-tab>
            <v-tab>词典</v-tab>
            <v-tab>字体</v-tab>
            <v-tab>API</v-tab>
            <v-tab>复习</v-tab>
        </v-tabs>
        <v-tabs-items v-model="tab" id="admin-tab-items" elevation="0" class="no-background rounded-lg mt-4 pa-6">
            <v-tab-item :value="0">
                <admin-dashboard></admin-dashboard>
            </v-tab-item>
            <v-tab-item :value="1">
                <admin-user-settings></admin-user-settings>
            </v-tab-item>
            <v-tab-item :value="2">
                <admin-language-settings></admin-language-settings>
            </v-tab-item>
            <v-tab-item :value="3">
                <admin-dictionary-settings :language="$props.language"></admin-dictionary-settings>
            </v-tab-item>
            <v-tab-item :value="4">
                <admin-font-type-settings></admin-font-type-settings>
            </v-tab-item>
            <v-tab-item :value="5">
                <admin-api-settings></admin-api-settings>
            </v-tab-item>
            <v-tab-item :value="6">
                <admin-review-settings></admin-review-settings>
            </v-tab-item>
        </v-tabs-items>
    </v-container>
    <v-container v-else>
        你没有权限访问此页面。
    </v-container>
</template>

<script>
    export default {
        data: function() {
            return {
                tab: 0,
                loading: true,
                tabIndexes: {
                    'dashboard': 0,
                    'users': 1,
                    'languages': 2,
                    'dictionaries': 3,
                    'font-types': 4,
                    'api': 5,
                    'reviews': 6,
                },
                tabUrls: [
                    'dashboard',
                    'users',
                    'languages',
                    'dictionaries',
                    'font-types',
                    'api',
                    'reviews',
                ]
            }
        },
        props: {
            language: String,
        },
        beforeMount() {
        },
        mounted() {
            if (this.$route.params.page !== undefined) {
                this.tab = this.tabIndexes[this.$route.params.page];
            } 
        },
        methods: {
            tabChanged(event) {
                var page = this.tabUrls[event];

                if (this.$router.currentRoute.fullPath !== '/admin/' + page) {
                    this.$router.push('/admin/' + page);
                }
            }
        }
    }
</script>
