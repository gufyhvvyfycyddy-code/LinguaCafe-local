<template>
    <v-card outlined class="pa-4 fill-height rounded-lg">
        <div class="font-weight-medium mb-3">{{ title }}</div>
        <div v-if="!rows.length" class="text--secondary py-6 text-center">暂无数据</div>
        <div v-for="row in rows" :key="row.label" class="chart-row mb-2">
            <div class="chart-label text-truncate">{{ row.label }}</div>
            <div class="chart-track">
                <div
                    class="chart-bar"
                    :style="{ width: `${barWidth(row.value)}%`, backgroundColor: color }"
                ></div>
            </div>
            <div class="chart-value">{{ row.value }}</div>
        </div>
    </v-card>
</template>

<script>
export default {
    props: {
        title: { type: String, required: true },
        rows: { type: Array, default: () => [] },
        color: { type: String, default: '#5c6bc0' },
    },
    computed: {
        maximum() {
            return Math.max(1, ...this.rows.map(row => Number(row.value) || 0));
        },
    },
    methods: {
        barWidth(value) {
            return Math.max(2, Math.round((Number(value) || 0) * 100 / this.maximum));
        },
    },
};
</script>

<style scoped>
.chart-row {
    display: grid;
    grid-template-columns: minmax(72px, 1fr) 3fr 42px;
    gap: 8px;
    align-items: center;
    font-size: 12px;
}
.chart-track {
    height: 12px;
    border-radius: 8px;
    background: rgba(127, 127, 127, .16);
    overflow: hidden;
}
.chart-bar {
    height: 100%;
    border-radius: inherit;
}
.chart-value {
    text-align: right;
    font-variant-numeric: tabular-nums;
}
</style>
