import pathlib

path = pathlib.Path("resources/js/components/ReviewCards/ReviewCardManage.vue")
content = path.read_text(encoding="utf-8")

# 1. Add loadFsrsStats() method after loadData() closing
old = "        },\n\n        updateSelectAllState() {"
new = """        },

        loadFsrsStats() {
            this.statsLoading = true;
            this.statsError = '';
            axios.get('/review-cards/stats')
                .then((response) => {
                    this.fsrsStats = response.data;
                })
                .catch(() => {
                    this.statsError = 'FSRS 统计加载失败。';
                })
                .finally(() => {
                    this.statsLoading = false;
                });
        },

        updateSelectAllState() {"""
assert old in content, "Anchor 1 not found!"
content = content.replace(old, new, 1)
print("Patch 1 applied: loadFsrsStats() method")

# 2. Add FSRS stats chips template
old = "        </div>\n\n        <!-- Toolbar -->\n        <v-row"
new = """        </div>

        <!-- FSRS Stats -->
        <v-progress-linear v-if="statsLoading" indeterminate class="mb-2" />
        <v-alert v-if="statsError" type="warning" dense class="mb-2">{{ statsError }}</v-alert>
        <div v-if="!statsLoading && !statsError" class="d-flex flex-wrap mb-3" style="gap: 6px;">
            <v-chip small outlined>{{ '总词义卡 ' + fsrsStats.total }}</v-chip>
            <v-chip small outlined>{{ '启用中 ' + fsrsStats.enabled }}</v-chip>
            <v-chip small outlined>{{ '已归档 ' + fsrsStats.archived }}</v-chip>
            <v-chip small outlined>{{ '当前到期 ' + fsrsStats.due }}</v-chip>
            <v-chip small outlined>{{ '新卡 ' + fsrsStats.by_state.new }}</v-chip>
            <v-chip small outlined>{{ '学习中 ' + fsrsStats.by_state.learning }}</v-chip>
            <v-chip small outlined>{{ '复习中 ' + fsrsStats.by_state.review }}</v-chip>
            <v-chip small outlined>{{ '重新学习 ' + fsrsStats.by_state.relearning }}</v-chip>
            <v-chip small outlined>{{ '今日已复习 ' + fsrsStats.reviewed_today }}</v-chip>
            <v-chip small outlined>{{ '今日重置 ' + fsrsStats.reset_count }}</v-chip>
        </div>

        <!-- Toolbar -->
        <v-row"""
assert old in content, "Anchor 2 not found!"
content = content.replace(old, new, 1)
print("Patch 2 applied: template chips")

# 3. Add refresh after toggleEnabled
old = "                this.showSnackbar('已恢复。该卡会重新进入日常复习。', 'success');\n                this.loadData();"
new = old + "\n                this.loadFsrsStats();"
assert old in content, "Anchor 3 not found!"
content = content.replace(old, new, 1)
print("Patch 3 applied: toggleEnabled refresh")

# 4. Add refresh after doArchive
old = "                this.showSnackbar('已归档。该卡不会进入日常复习。', 'warning');\n                this.loadData();"
new = old + "\n                this.loadFsrsStats();"
assert old in content, "Anchor 4 not found!"
content = content.replace(old, new, 1)
print("Patch 4 applied: doArchive refresh")

# 5. Add refresh after doDelete
old = "                    this.selectedIds = this.selectedIds.filter(id => id !== item.review_card_id);\n                    this.loadData();"
new = old + "\n                    this.loadFsrsStats();"
assert old in content, "Anchor 5 not found!"
content = content.replace(old, new, 1)
print("Patch 5 applied: doDelete refresh")

# 6. Add refresh after doBulkDelete
old = "                    this.showSnackbar(response.data.message || '已彻底删除词义复习卡。', 'success');\n                    this.loadData();"
new = old + "\n                    this.loadFsrsStats();"
assert old in content, "Anchor 6 not found!"
content = content.replace(old, new, 1)
print("Patch 6 applied: doBulkDelete refresh")

# 7. Add refresh after bulkArchive
old = "                    this.showSnackbar(response.data.message || '已批量归档。', 'warning');\n                    this.loadData();"
new = old + "\n                    this.loadFsrsStats();"
assert old in content, "Anchor 7 not found!"
content = content.replace(old, new, 1)
print("Patch 7 applied: bulkArchive refresh")

# 8. Add refresh after bulkRestore
old = "                    this.showSnackbar(response.data.message || '已批量恢复。', 'success');\n                    this.loadData();"
new = old + "\n                    this.loadFsrsStats();"
assert old in content, "Anchor 8 not found!"
content = content.replace(old, new, 1)
print("Patch 8 applied: bulkRestore refresh")

# 9. Add refresh after setDueNow
old = "                    if (idx >= 0) {\n                        this.$set(this.items, idx, response.data);\n                    }\n                })"
new = old.replace("\n                })", "\n                    this.loadFsrsStats();\n                })")
assert old in content, "Anchor 9 not found!"
content = content.replace(old, new, 1)
print("Patch 9 applied: setDueNow refresh")

# 10. Add refresh after doReset
old = "                    this.showSnackbar(response.data.message || '已重置为新学卡。该卡会重新进入复习队列。', 'success');\n                    this.loadData();"
new = old + "\n                    this.loadFsrsStats();"
assert old in content, "Anchor 10 not found!"
content = content.replace(old, new, 1)
print("Patch 10 applied: doReset refresh")

path.write_text(content, encoding="utf-8")
print("All patches applied successfully!")
