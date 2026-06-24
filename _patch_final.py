import pathlib

path = pathlib.Path("resources/js/components/ReviewCards/ReviewCardManage.vue")
lines = path.read_text(encoding="utf-8").split("\n")

# Step 1: Remove spurious loadFsrsStats in saveEdit method
new_lines = []
for i, line in enumerate(lines):
    if i == 495:
        continue  # skip the stray line
    new_lines.append(line)

# Step 2: Fix setDueNow - find the two spurious calls and fix
# Find the pattern: `})\nloadFsrsStats\nloadFsrsStats*\n.catch
result = []
skip_next = False
for i in range(len(new_lines)):
    if skip_next:
        skip_next = False
        continue
    line = new_lines[i]
    # Check if this is a stray loadFsrsStats between }) and .catch
    if line.strip() == "})":
        next_i = i + 1
        while next_i < len(new_lines) and "loadFsrsStats" in new_lines[next_i]:
            next_i += 1
        if next_i < len(new_lines) and ".catch" in new_lines[next_i]:
            # This is setDueNow - add the loadFsrsStats inside .then()
            result.append("                    this.loadFsrsStats();")
            result.append("                })")
        else:
            result.append(line)
    elif line.strip() == "this.loadFsrsStats();" and i > 0 and new_lines[i-1].strip() == "})":
        # These are the stray lines inside the loop above, skip them
        continue
    else:
        result.append(line)

# Step 3: Add loadFsrsStats() method definition
# Find updateSelectAllState
for i, line in enumerate(result):
    if "updateSelectAllState()" in line:
        method_code = [
            "        loadFsrsStats() {",
            "            this.statsLoading = true;",
            "            this.statsError = '';",
            "            axios.get('/review-cards/stats')",
            "                .then((response) => {",
            "                    this.fsrsStats = response.data;",
            "                })",
            "                .catch(() => {",
            "                    this.statsError = 'FSRS \\u7edf\\u8ba1\\u52a0\\u8f7d\\u5931\\u8d25\\u3002';",
            "                })",
            "                .finally(() => {",
            "                    this.statsLoading = false;",
            "                });",
            "        },",
        ]
        indent = line[:len(line) - len(line.lstrip())]
        for idx, code in enumerate(reversed(method_code)):
            result.insert(i, code)
        break

# Step 4: Add template FSRS chips
# Find the closing </div> of header followed by <!-- Toolbar -->
for i, line in enumerate(result):
    if line.strip() == "<!-- Toolbar -->" and i > 0 and result[i-1].strip() == "</div>":
        chips = [
            "",
            "        <!-- FSRS Stats -->",
            '        <v-progress-linear v-if="statsLoading" indeterminate class="mb-2" />',
            '        <v-alert v-if="statsError" type="warning" dense class="mb-2">{{ statsError }}</v-alert>',
            '        <div v-if="!statsLoading && !statsError" class="d-flex flex-wrap mb-3" style="gap: 6px;">',
            "            <v-chip small outlined>{{ '\\u603b\\u8bcd\\u4e49\\u5361 ' + fsrsStats.total }}</v-chip>",
            "            <v-chip small outlined>{{ '\\u542f\\u7528\\u4e2d ' + fsrsStats.enabled }}</v-chip>",
            "            <v-chip small outlined>{{ '\\u5df2\\u5f52\\u6863 ' + fsrsStats.archived }}</v-chip>",
            "            <v-chip small outlined>{{ '\\u5f53\\u524d\\u5230\\u671f ' + fsrsStats.due }}</v-chip>",
            "            <v-chip small outlined>{{ '\\u65b0\\u5361 ' + fsrsStats.by_state.new }}</v-chip>",
            "            <v-chip small outlined>{{ '\\u5b66\\u4e60\\u4e2d ' + fsrsStats.by_state.learning }}</v-chip>",
            "            <v-chip small outlined>{{ '\\u590d\\u4e60\\u4e2d ' + fsrsStats.by_state.review }}</v-chip>",
            "            <v-chip small outlined>{{ '\\u91cd\\u65b0\\u5b66\\u4e60 ' + fsrsStats.by_state.relearning }}</v-chip>",
            "            <v-chip small outlined>{{ '\\u4eca\\u65e5\\u5df2\\u590d\\u4e60 ' + fsrsStats.reviewed_today }}</v-chip>",
            "            <v-chip small outlined>{{ '\\u4eca\\u65e5\\u91cd\\u7f6e ' + fsrsStats.reset_count }}</v-chip>",
            "        </div>",
            "",
        ]
        for idx, code in enumerate(reversed(chips)):
            result.insert(i, code)
        break

content = "\n".join(result)
path.write_text(content, encoding="utf-8")
print(f"Done! Final file written. {len(result)} lines.")
