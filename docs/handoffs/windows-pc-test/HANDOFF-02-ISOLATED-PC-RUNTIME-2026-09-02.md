# HANDOFF-02 — Isolated PC runtime — 2026-09-02

## Completed

- Started the dedicated `linguacafe-pc-test` Compose project with persistent MySQL/Redis/storage and the current Python tokenizer.
- Used Compose health waiting before database initialization.
- Ran normal Laravel migrations and seeders only against `linguacafe_pc_test`.
- Imported the existing local ECDICT CSV through a read-only container mount.
- Started the PC Web/Reverb service on loopback only.

## Evidence

- MySQL database observed from the real Web container: `linguacafe_pc_test`.
- ECDICT import: 768,739 rows, 0 malformed errors; final status `HEALTHY` (minimum 700,000).
- `GET http://127.0.0.1:9391/login`: HTTP 200.
- Web host bindings: `127.0.0.1:9391 -> 80`, `127.0.0.1:6001 -> 6001`.
- PC MySQL and Redis expose no host database/cache ports.
- Existing unrelated H-07 MySQL containers remained running and unchanged.
- No `.env` was read and no destructive migration/reset command was used.

## Incidental test-shell issue

Git Bash rewrote the literal container argument `/pc-test/ecdict.csv` into a Windows path during the manual acceptance command. The actual WPF executable calls Docker directly and is unaffected. Manual acceptance was rerun with MSYS path conversion disabled; the repository runtime path stayed `/pc-test/ecdict.csv`.

## Next

Launch the WPF/WebView2 executable against this isolated runtime. Prove first-run hidden `/setup`, canonical `/login`, administrator Home rendering, repeat-launch login behavior, and absence of any server-side authentication bypass.
