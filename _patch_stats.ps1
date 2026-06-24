$path = "resources/js/components/ReviewCards/ReviewCardManage.vue"
$text = [System.IO.File]::ReadAllText((Resolve-Path $path))

# Patch 4: Add FSRS stats chips in template (after header closing </div> before <!-- Toolbar -->)
$old = "        </div>

        <!-- Toolbar -->
        <v-row"
$new = "        </div>

        <!-- FSRS Stats -->
        <v-progress-linear v-if=""statsLoading"" indeterminate class=""mb-2"" />
        <v-alert v-if=""statsError"" type=""warning"" dense class=""mb-2"">{{ statsError }}</v-alert>
        <div v-if=""!statsLoading && !statsError"" class=""d-flex flex-wrap mb-3"" style=""gap: 6px;"">
            <v-chip small outlined>{{ ""总词义卡 "" + fsrsStats.total }}</v-chip>
            <v-chip small outlined>{{ ""启用中 "" + fsrsStats.enabled }}</v-chip>
            <v-chip small outlined>{{ ""已归档 "" + fsrsStats.archived }}</v-chip>
            <v-chip small outlined>{{ ""当前到期 "" + fsrsStats.due }}</v-chip>
            <v-chip small outlined>{{ ""新卡 "" + fsrsStats.by_state.new }}</v-chip>
            <v-chip small outlined>{{ ""学习中 "" + fsrsStats.by_state.learning }}</v-chip>
            <v-chip small outlined>{{ ""复习中 "" + fsrsStats.by_state.review }}</v-chip>
            <v-chip small outlined>{{ ""重新学习 "" + fsrsStats.by_state.relearning }}</v-chip>
            <v-chip small outlined>{{ ""今日已复习 "" + fsrsStats.reviewed_today }}</v-chip>
            <v-chip small outlined>{{ ""今日重置 "" + fsrsStats.reset_count }}</v-chip>
        </div>

        <!-- Toolbar -->
        <v-row"
$text = $text.Replace($old, $new)

# Patch 6a: Add loadFsrsStats() after toggleEnabled success
$old = "                this.showSnackbar('已恢复。该卡会重新进入日常复习。', 'success');
                this.loadData();"
$new = "                this.showSnackbar('已恢复。该卡会重新进入日常复习。', 'success');
                this.loadData();
                this.loadFsrsStats();"
$text = $text.Replace($old, $new)

# Patch 6b: Add loadFsrsStats() after doArchive success
$old = "                this.showSnackbar('已归档。该卡不会进入日常复习。', 'warning');
                this.loadData();"
$new = "                this.showSnackbar('已归档。该卡不会进入日常复习。', 'warning');
                this.loadData();
                this.loadFsrsStats();"
$text = $text.Replace($old, $new)

# Patch 6c: Add loadFsrsStats() after doDelete success
$old = "                    this.selectedIds = this.selectedIds.filter(id => id !== item.review_card_id);
                    this.loadData();"
$new = "                    this.selectedIds = this.selectedIds.filter(id => id !== item.review_card_id);
                    this.loadData();
                    this.loadFsrsStats();"
$text = $text.Replace($old, $new)

# Patch 6d: Add loadFsrsStats() after doBulkDelete success
$old = "                    this.showSnackbar(response.data.message || '已彻底删除词义复习卡。', 'success');
                    this.loadData();"
$new = "                    this.showSnackbar(response.data.message || '已彻底删除词义复习卡。', 'success');
                    this.loadData();
                    this.loadFsrsStats();"
$text = $text.Replace($old, $new)

# Patch 6e: Add loadFsrsStats() after bulkArchive success
$old = "                    this.showSnackbar(response.data.message || '已批量归档。', 'warning');
                    this.loadData();"
$new = "                    this.showSnackbar(response.data.message || '已批量归档。', 'warning');
                    this.loadData();
                    this.loadFsrsStats();"
$text = $text.Replace($old, $new)

# Patch 6f: Add loadFsrsStats() after bulkRestore success
$old = "                    this.showSnackbar(response.data.message || '已批量恢复。', 'success');
                    this.loadData();"
$new = "                    this.showSnackbar(response.data.message || '已批量恢复。', 'success');
                    this.loadData();
                    this.loadFsrsStats();"
$text = $text.Replace($old, $new)

# Patch 6g: Add loadFsrsStats() after setDueNow success
$old = "                    if (idx >= 0) {
                        this.\$set(this.items, idx, response.data);
                    }"
$new = "                    if (idx >= 0) {
                        this.\$set(this.items, idx, response.data);
                    }
                    this.loadFsrsStats();"
$text = $text.Replace($old, $new)

# Patch 6h: Add loadFsrsStats() after doReset success
$old = "                    this.showSnackbar(response.data.message || '已重置为新学卡。该卡会重新进入复习队列。', 'success');
                    this.loadData();"
$new = "                    this.showSnackbar(response.data.message || '已重置为新学卡。该卡会重新进入复习队列。', 'success');
                    this.loadData();
                    this.loadFsrsStats();"
$text = $text.Replace($old, $new)

[System.IO.File]::WriteAllText((Resolve-Path $path), $text)
Write-Host "Done"
