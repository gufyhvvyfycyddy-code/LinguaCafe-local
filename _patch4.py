import pathlib

path = pathlib.Path("resources/js/components/ReviewCards/ReviewCardManage.vue")
content = path.read_text(encoding="utf-8")
lines = content.split("\n")

# Process in reverse to maintain line numbers
insertions = []

# 1. toggleEnabled L510
insertions.append((511, '                this.loadFsrsStats();'))

# 2. doArchive L533  
insertions.append((534, '                this.loadFsrsStats();'))

# 3. doReset L555
insertions.append((556, '                    this.loadFsrsStats();'))

# 4. doDelete L580
insertions.append((581, '                    this.loadFsrsStats();'))

# 5. doBulkDelete L600
insertions.append((601, '                    this.loadFsrsStats();'))

# 6. bulkArchive L615
insertions.append((616, '                    this.loadFsrsStats();'))

# 7. bulkRestore L630
insertions.append((631, '                    this.loadFsrsStats();'))

# Process in reverse
insertions.sort(reverse=True)

for line_no, text in insertions:
    lines.insert(line_no, text)

# Handle setDueNow - find the .$set line
set_due_done = False
for i in range(len(lines) - 5, 0, -1):
    if 'this.$set' in lines[i] and 'response.data' in lines[i]:
        for j in range(i, min(i+5, len(lines))):
            if lines[j].strip() == "})":
                indent = lines[j][:len(lines[j]) - len(lines[j].lstrip())]
                lines.insert(j + 1, indent + "this.loadFsrsStats();")
                set_due_done = True
                break
    if set_due_done:
        break

content = "\n".join(lines)
path.write_text(content, encoding="utf-8")
print(f"Done! Inserted {len(insertions)} lines, setDueNow: {set_due_done}")
