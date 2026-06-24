import pathlib

path = pathlib.Path("resources/js/components/ReviewCards/ReviewCardManage.vue")
content = path.read_text(encoding="utf-8")

# 1. Add refresh after toggleEnabled
old = "this.showSnackbar('已恢复。该卡会重新进入日常复习。', 'success');\n                this.loadData();"
new = old + "\n                this.loadFsrsStats();"
assert old in content, "Anchor 1 toggleEnabled not found!"
content = content.replace(old, new, 1)
print("Patch 1 applied: toggleEnabled refresh")

# 2. Add refresh after doArchive
old = "this.showSnackbar('已归档。该卡不会进入日常复习。', 'warning');\n                this.loadData();"
new = old + "\n                this.loadFsrsStats();"
assert old in content, "Anchor 2 doArchive not found!"
content = content.replace(old, new, 1)
print("Patch 2 applied: doArchive refresh")

# 3. Add refresh after doDelete
old = "this.selectedIds = this.selectedIds.filter(id => id !== item.review_card_id);\n                    this.loadData();"
new = old + "\n                    this.loadFsrsStats();"
assert old in content, "Anchor 3 doDelete not found!"
content = content.replace(old, new, 1)
print("Patch 3 applied: doDelete refresh")

# 4. Add refresh after doBulkDelete
old = "this.showSnackbar(response.data.message || '已彻底删除词义复习卡。', 'success');\n                    this.loadData();"
new = old + "\n                    this.loadFsrsStats();"
assert old in content, "Anchor 4 doBulkDelete not found!"
content = content.replace(old, new, 1)
print("Patch 4 applied: doBulkDelete refresh")

# 5. Add refresh after bulkArchive
old = "this.showSnackbar(response.data.message || '已批量归档。', 'warning');\n                    this.loadData();"
new = old + "\n                    this.loadFsrsStats();"
assert old in content, "Anchor 5 bulkArchive not found!"
content = content.replace(old, new, 1)
print("Patch 5 applied: bulkArchive refresh")

# 6. Add refresh after bulkRestore
old = "this.showSnackbar(response.data.message || '已批量恢复。', 'success');\n                    this.loadData();"
new = old + "\n                    this.loadFsrsStats();"
assert old in content, "Anchor 6 bulkRestore not found!"
content = content.replace(old, new, 1)
print("Patch 6 applied: bulkRestore refresh")

# 7. Add refresh after setDueNow
old = "if (idx >= 0) {\n                        this.$set(this.items, idx, response.data);\n                    }\n                })"
new = old.replace("\n                })", "\n                    this.loadFsrsStats();\n                })")
assert old in content, "Anchor 7 setDueNow not found!"
content = content.replace(old, new, 1)
print("Patch 7 applied: setDueNow refresh")

# 8. Add refresh after doReset
old = "this.showSnackbar(response.data.message || '已重置为新学卡。该卡会重新进入复习队列。', 'success');\n                    this.loadData();"
new = old + "\n                    this.loadFsrsStats();"
assert old in content, "Anchor 8 doReset not found!"
content = content.replace(old, new, 1)
print("Patch 8 applied: doReset refresh")

path.write_text(content, encoding="utf-8")
print("All remaining patches applied successfully!")
