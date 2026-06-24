import pathlib

path = pathlib.Path("resources/js/components/ReviewCards/ReviewCardManage.vue")
content = path.read_text(encoding="utf-8")
lines = content.split("\n")
modifications = []

# Find lines to modify
for i, line in enumerate(lines):
    # toggleEnabled: add after loadData() in .then block
    if i > 0 and "loadData()" in line and "showSnackbar" in lines[i-1] and "success" in lines[i-1]:
        if "已恢复" in lines[i-1] or "已重" in lines[i-1]:
            modifications.append(("after", i, '                this.loadFsrsStats();'))
    elif "loadData()" in line:
        # Check context for other operations
        ctx_start = max(0, i-5)
        ctx = " ".join(lines[ctx_start:i])
        if "confirmArchive" in ctx or "doArchive" in ctx:
            modifications.append(("after", i, '                this.loadFsrsStats();'))
        elif "doBulkDelete" in ctx:
            modifications.append(("after", i, '                    this.loadFsrsStats();'))
        elif "bulkArchive" in ctx or "bulkRestore" in ctx:
            modifications.append(("after", i, '                    this.loadFsrsStats();'))
        elif "doDelete" in ctx:
            modifications.append(("after", i, '                    this.loadFsrsStats();'))
        elif "doReset" in ctx:
            modifications.append(("after", i, '                    this.loadFsrsStats();'))

# Sort modifications by line number (descending so insertions don't shift indices)
modifications.sort(key=lambda x: x[1], reverse=True)

for action, line_idx, text in modifications:
    if action == "after":
        indent = ""
        # Use same indentation as current line
        current_line = lines[line_idx]
        indent = current_line[:len(current_line) - len(current_line.lstrip())]
        lines.insert(line_idx + 1, indent + text)

# Handle setDueNow - find the $set line and add after
for i, line in enumerate(lines):
    if "this.$set" in line and "this.items" in line:
        # Add loadFsrsStats() after the closing braces
        for j in range(i, min(i+5, len(lines))):
            if "}" in lines[j] and ")" in lines[j] and lines[j].strip() == "})":
                indent = lines[j][:len(lines[j]) - len(lines[j].lstrip())]
                lines.insert(j + 1, indent + "this.loadFsrsStats();")
                break

content = "\n".join(lines)
path.write_text(content, encoding="utf-8")
print("Done! Modifications applied.")
