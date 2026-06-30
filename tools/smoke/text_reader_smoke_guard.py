"""
Text Reader Smoke Guard
=======================
Zero-dependency browser smoke tests for reading page core behaviors.

Usage:
    python tools/smoke/text_reader_smoke_guard.py [--auth AUTH_FILE] [--base-url URL] [--chapter-id N]

    --auth AUTH_FILE  Optional: path to Playwright storage state JSON file
                      (pre-saved session cookie). Use this to skip manual login.
    --base-url URL    Optional: base URL of the dev server.
                      Default: http://localhost:8000
    --chapter-id N    Optional: chapter id to open on the reader page.
                      The smoke opens {base-url}/chapters/read/{N}.
                      Default: 5. Choose a chapter the logged-in user actually
                      owns — NEVER modify book/chapter/user_id to make chapter 5
                      accessible. See docs/testing/text-reader-smoke-guard.md
                      "可配置 smoke chapter" for the rationale (Lab-4 lesson).

Requirements:
    - Python 3.8+
    - pip install playwright && playwright install chromium
    - Local dev server (default http://localhost:8000)
    - User already logged in browser session (or --auth file)

Exit codes:
    0  All P0 tests passed
    1  One or more P0 tests failed
    2  Environment not ready (server down / not logged in / no playwright)
"""

import os
import sys
import json
import argparse

SCREENSHOT_DIR = r"D:\Document\lingl\text-reader-smoke-guard-screenshots"
DEFAULT_BASE_URL = "http://localhost:8000"

results = {"pass": [], "fail": [], "skip": []}

def report(name, passed, detail=""):
    if passed:
        results["pass"].append((name, detail))
        print(f"  [PASS] {name}")
    else:
        results["fail"].append((name, detail))
        print(f"  [FAIL] {name} — {detail}")

def ensure_dir():
    os.makedirs(SCREENSHOT_DIR, exist_ok=True)

def take_screenshot(page, name):
    path = os.path.join(SCREENSHOT_DIR, name)
    page.screenshot(path=path, full_page=False)
    return path

# ----------------------------------------------------------------
# 1.  Check Playwright availability
# ----------------------------------------------------------------
try:
    from playwright.sync_api import sync_playwright
    PLAYWRIGHT_AVAILABLE = True
except ImportError:
    PLAYWRIGHT_AVAILABLE = False
    print("[SKIP] Playwright not installed. Install with: pip install playwright && playwright install chromium")
    print("[SKIP] Falling back to manual-check instructions in docs/testing/text-reader-smoke-guard.md")
    sys.exit(2)

# ----------------------------------------------------------------
# 2.  Run smoke
# ----------------------------------------------------------------
ensure_dir()

# Parse arguments
parser = argparse.ArgumentParser(description="Text Reader Smoke Guard")
parser.add_argument("--auth", type=str, default=None, help="Path to Playwright storage state JSON file")
parser.add_argument("--base-url", type=str, default=DEFAULT_BASE_URL, help="Base URL of the dev server (default: http://localhost:8000)")
parser.add_argument("--chapter-id", type=int, default=5, help="Chapter id to open on the reader page (default: 5). Pick a chapter the logged-in user actually owns; do NOT modify DB ownership to make chapter 5 accessible.")
args = parser.parse_args()

base_url = args.base_url.rstrip("/")
chapter_url = f"{base_url}/chapters/read/{args.chapter_id}"
print(f"  Base URL: {base_url}")
print(f"  Chapter URL: {chapter_url}")
print(f"  Chapter ID: {args.chapter_id}")

with sync_playwright() as p:
    browser = p.chromium.launch(headless=True)
    context_args = {"viewport": {"width": 1920, "height": 900}}
    if args.auth:
        context_args["storage_state"] = args.auth
    context = browser.new_context(**context_args)
    page = context.new_page()

    # Track network requests for guard assertions
    captured = {"ai_lookup_url": None, "senses_manual_posted": False}

    def on_request(request):
        if "/chapters/ai-assist/lookup/" in request.url:
            captured["ai_lookup_url"] = request.url
        if request.method == "POST" and "/senses/manual" in request.url:
            captured["senses_manual_posted"] = True

    page.on("request", on_request)

    # ------------------------------------------------------------
    # Step A: Navigate and check login
    # ------------------------------------------------------------
    print("\n=== Step A: Navigate to reader ===")
    try:
        page.goto(chapter_url, timeout=15000)
        page.wait_for_load_state("networkidle")
        page.wait_for_timeout(4000)
    except Exception as e:
        report("Server reachable", False, f"Cannot load {chapter_url}: {e}")
        browser.close()
        print("\nSmoke guard could not start — server or reader page unavailable.")
        sys.exit(2)

    # Check if redirected to login (not authenticated)
    if "/login" in page.url:
        report("User logged in", False,
               "Redirected to login page. Open Chrome first, log in at /login, then re-run this script.")
        take_screenshot(page, "00-redirected-to-login.png")
        browser.close()
        print("\nSmoke guard blocked — user not logged in.")
        sys.exit(2)

    report("Reader page loads without redirect", True)

    # Check for substantive text in page
    page_text = page.evaluate("() => document.body.innerText || ''")
    has_substantive = "substantive" in page_text.lower()
    report("Page contains 'substantive' text", has_substantive,
           "Chapter 5 may not contain the expected word 'substantive'" if not has_substantive else "")

    # ------------------------------------------------------------
    # Step B: Click "substantive"
    # ------------------------------------------------------------
    print("\n=== Step B: Click 'substantive' ===")
    click_result = page.evaluate("""
        () => {
            const words = document.querySelectorAll('.word');
            for (const w of words) {
                if (w.textContent.trim() === 'substantive') {
                    const r = w.getBoundingClientRect();
                    return { x: r.x + r.width/2, y: r.y + r.height/2, found: true };
                }
            }
            return { found: false };
        }
    """)

    if click_result.get("found"):
        page.mouse.click(click_result["x"], click_result["y"])
        page.wait_for_timeout(3000)
        take_screenshot(page, "01-substantive-clicked.png")
        report("Click 'substantive' finds the element", True)
    else:
        report("Click 'substantive' finds the element", False, "Word 'substantive' not found in DOM")
        take_screenshot(page, "01-substantive-not-found.png")

    # ------------------------------------------------------------
    # Step C: Check sidebar appears
    # ------------------------------------------------------------
    print("\n=== Step C: Sidebar shows ===")
    # Check sidebar. The element has position:fixed so offsetParent is null;
    # instead check offsetWidth and display.
    page.wait_for_timeout(2000)
    sidebar_visible = page.evaluate("""
        () => {
            const sb = document.querySelector('#vocab-side-box');
            const store = document.querySelector('#app').__vue__.$store;
            if (!sb) return { exists: false, visible: false, reason: 'not in DOM' };
            const style = window.getComputedStyle(sb);
            return {
                exists: true,
                visible: sb.offsetWidth > 0 && sb.offsetHeight > 0 && style.display !== 'none',
                width: sb.offsetWidth,
                height: sb.offsetHeight,
                display: style.display,
                transform: style.transform,
                store: store ? { sidebarHidden: store.state.vocabularyBox.sidebarHidden, active: store.state.vocabularyBox.active } : null
            };
        }
    """)
    if isinstance(sidebar_visible, dict):
        report("P0.1: Sidebar exists in DOM", sidebar_visible.get("exists", False))
        report("P0.1: Sidebar visible (has size)", sidebar_visible.get("visible", False),
               f"w={sidebar_visible.get('width')} h={sidebar_visible.get('height')} display={sidebar_visible.get('display')} transform={sidebar_visible.get('transform')}")
    else:
        report("P0.1: Sidebar visible", bool(sidebar_visible))
    take_screenshot(page, "02-sidebar-visible.png")

    # ------------------------------------------------------------
    # Step D: Check Vuex state (P0.2)
    # ------------------------------------------------------------
    print("\n=== Step D: Vuex state (P0.2) ===")
    vuex_state = page.evaluate("""
        () => {
            try {
                const store = document.querySelector('#app').__vue__.$store;
                const vb = store.state.vocabularyBox;
                return {
                    word: vb.word,
                    studyBase: vb.studyBase,
                    chapterId: vb.chapterId,
                    sentenceIndex: vb.sentenceIndex,
                    active: vb.active,
                };
            } catch(e) {
                return { error: e.message };
            }
        }
    """)
    if "error" in vuex_state:
        report("P0.2: Vuex state readable", False, vuex_state["error"])
    else:
        word_ok = vuex_state.get("word") == "substantive"
        chapter_ok = vuex_state.get("chapterId") == args.chapter_id or vuex_state.get("chapterId") == str(args.chapter_id)
        si_ok = vuex_state.get("sentenceIndex") is not None
        all_ok = word_ok and chapter_ok and si_ok
        detail = f"word={vuex_state.get('word')} chapterId={vuex_state.get('chapterId')} sentenceIndex={vuex_state.get('sentenceIndex')}"
        report("P0.2: Vuex word=substantive", word_ok, detail)
        report(f"P0.2: Vuex chapterId={args.chapter_id}", chapter_ok, detail)
        report("P0.2: Vuex sentenceIndex set", si_ok, detail)
        report("P0.2: All Vuex assertions", all_ok, detail)

    # ------------------------------------------------------------
    # Step E: Check AI lookup URL (P0.3)
    # ------------------------------------------------------------
    print("\n=== Step E: AI lookup URL (P0.3) ===")
    ai_lookup_url = captured["ai_lookup_url"]
    senses_manual_posted = captured["senses_manual_posted"]
    if ai_lookup_url:
        has_word_param = "word=substantive" in ai_lookup_url
        has_lemma_param = "lemma=" in ai_lookup_url
        has_si_param = "sentence_index=" in ai_lookup_url
        has_chapter_id = f"/lookup/{args.chapter_id}" in ai_lookup_url
        url_ok = has_word_param and has_chapter_id
        report(f"P0.3: AI lookup URL matches /lookup/{args.chapter_id}", has_chapter_id, ai_lookup_url)
        report(f"P0.3: AI lookup URL has word=substantive", has_word_param, ai_lookup_url)
        report(f"P0.3: AI lookup URL has sentence_index", has_si_param, ai_lookup_url)
        report("P0.3: AI lookup URL passes all checks", url_ok, ai_lookup_url)
    else:
        report("P0.3: AI lookup request captured", False, "No /chapters/ai-assist/lookup/ request detected")

    # ------------------------------------------------------------
    # Step F: Check AI suggestions visible (P0.4)
    # ------------------------------------------------------------
    print("\n=== Step F: AI suggestions visible (P0.4) ===")
    sidebar_text = page.evaluate("() => document.querySelector('#vocab-side-box')?.innerText || ''")
    has_ai_label = "AI 建议" in sidebar_text
    has_use_btn = "使用此释义" in sidebar_text
    report("P0.4: AI 建议 label visible", has_ai_label)
    report("P0.4: '使用此释义' button visible", has_use_btn)
    report("P0.4: AI suggestions area", has_ai_label and has_use_btn)

    take_screenshot(page, "03-ai-suggestions.png")

    # ------------------------------------------------------------
    # Step G: Click AI suggestion and check form (P0.5)
    # ------------------------------------------------------------
    print("\n=== Step G: AI → AddSenseForm (P0.5) ===")
    use_ai_btn = page.locator('button:has-text("使用此释义")').first
    if use_ai_btn.is_visible():
        use_ai_btn.click()
        page.wait_for_timeout(2000)
        take_screenshot(page, "04-ai-prefill-form.png")
        form_visible = page.evaluate("() => !!document.querySelector('.sense-form')")
        if form_visible:
            form_pos = page.evaluate("""
                () => {
                    const form = document.querySelector('.sense-form');
                    if (!form) return null;
                    const text = form.innerText;
                    return {
                        hasPOS: text.includes('词性') || text.includes('noun') || text.includes('verb'),
                        hasZh: text.includes('中文释义'),
                        hasSave: text.includes('保存新释义'),
                        hasCancel: text.includes('取消'),
                    };
                }
            """)
            report("P0.5: AddSenseForm visible after AI click", True)
            if form_pos:
                report("P0.5: Form has POS selector", form_pos.get("hasPOS", False))
                report("P0.5: Form has 中文释义 field", form_pos.get("hasZh", False))
                report("P0.5: Form has 保存新释义 button", form_pos.get("hasSave", False))
        else:
            report("P0.5: AddSenseForm visible after AI click", False, ".sense-form not found in DOM")
    else:
        report("P0.5: AI '使用此释义' button clickable", False, "Button not visible")

    # Close the form if it's open
    page.evaluate("""
        () => {
            const closeBtn = document.querySelector('.sense-form button .mdi-close');
            if (closeBtn) closeBtn.closest('button').click();
        }
    """)
    page.wait_for_timeout(500)

    # ------------------------------------------------------------
    # Step H: Click dictionary plus and check form (P0.6)
    # ------------------------------------------------------------
    print("\n=== Step H: Dictionary → AddSenseForm (P0.6) ===")

    # First check if dictionary section is expanded; if not, try to expand it
    dict_rows_present = page.locator(".dictionary-definition-row").count()
    if dict_rows_present == 0:
        page.evaluate("""
            () => {
                const toggles = document.querySelectorAll('[class*="header"]');
                for (const t of toggles) {
                    if (t.textContent.includes('词典')) { t.click(); return; }
                }
            }
        """)
        page.wait_for_timeout(2000)
        dict_rows_present = page.locator(".dictionary-definition-row").count()

    report("P1: Dictionary rows visible", dict_rows_present > 0, f"Found {dict_rows_present} rows")

    dict_plus = page.locator('.dictionary-add-btn').first
    if dict_plus.is_visible():
        dict_plus.click()
        page.wait_for_timeout(2000)
        take_screenshot(page, "05-dictionary-plus-form.png")
        form_visible_2 = page.evaluate("() => !!document.querySelector('.sense-form')")
        report("P0.6: AddSenseForm visible after dictionary plus", form_visible_2)
    else:
        report("P0.6: Dictionary plus button clickable", False, "No .dictionary-add-btn visible")

    # ------------------------------------------------------------
    # Step I: Check no POST /senses/manual (P0.7)
    # ------------------------------------------------------------
    print("\n=== Step I: No sense creation (P0.7) ===")
    report("P0.7: No POST /senses/manual detected", not senses_manual_posted,
           "WARNING: A sense was saved!" if senses_manual_posted else "No sense saving detected (correct)")

    # ------------------------------------------------------------
    # Step J: Narrow screen fallback (P1)
    # ------------------------------------------------------------
    print("\n=== Step J: Narrow screen 900px (P1) ===")
    page.set_viewport_size({"width": 900, "height": 800})
    page.wait_for_timeout(2000)
    take_screenshot(page, "06-narrow-900px.png")
    sidebar_narrow = page.evaluate("""
        () => {
            const sb = document.querySelector('#vocab-side-box');
            return sb ? sb.offsetParent !== null : false;
        }
    """)
    report("P1: Sidebar hidden at 900px", not sidebar_narrow,
           f"sidebar visible={sidebar_narrow} at 900px viewport")

    # ------------------------------------------------------------
    # Step K: Check toolbar position (P1)
    # ------------------------------------------------------------
    print("\n=== Step K: Toolbar position (P1) ===")
    toolbar_info = page.evaluate("""
        () => {
            const tb = document.querySelector('#toolbar-box');
            if (!tb) return null;
            const r = tb.getBoundingClientRect();
            const style = window.getComputedStyle(tb);
            return {
                left: r.left,
                float: style.float,
            };
        }
    """)
    if toolbar_info:
        report("P1: Toolbar float left", toolbar_info.get("float") == "left",
               f"float={toolbar_info.get('float')}")
    else:
        report("P1: Toolbar exists", False, "#toolbar-box not found")

    # ------------------------------------------------------------
    # Summary
    # ------------------------------------------------------------
    browser.close()

print("\n" + "=" * 60)
print("SMOKE GUARD RESULTS")
print("=" * 60)
total = len(results["pass"]) + len(results["fail"])
print(f"  Passed: {len(results['pass'])}")
print(f"  Failed: {len(results['fail'])}")
print(f"  Total:  {total}")
print(f"  Screenshots: {SCREENSHOT_DIR}")
print()

if results["fail"]:
    print("FAILURES:")
    for name, detail in results["fail"]:
        print(f"  - {name}: {detail}")
    print()
    print("Do NOT modify business code to make tests pass.")
    print("Report failures to orchestrator for investigation.")
    sys.exit(1)
else:
    print("All P0 tests passed. Smoke guard is green.")
    sys.exit(0)
