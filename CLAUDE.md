# CLAUDE.md — project memory shared across all devices

This file is the **single source of truth for Claude on every machine** that works on this
repo. The owner works from several devices and syncs via git.

> **RULE FOR CLAUDE (owner instruction): update this file at EVERY step, not just at the end.**
> Whenever you start, finish or change anything in this project (code, schema, config,
> decisions, known issues, work in progress), update this file right then. Add a line to the
> [Change log](#change-log) with the date, the device if known, and what changed, and keep
> [Status / next steps](#11-status--next-steps) showing what is in progress. Keep the sections
> accurate: fix them, don't just append contradictions. Commit it locally with the related change.
>
> **NEVER `git push` without asking the owner first** (owner instruction, 2026-09-28). Local
> commits are fine. When a task is done, list the unpushed commits and ask whether to push.
> Only a push the owner has approved gets the changes to the other devices.
>
> **Don't burn the AI quota:** the owner's free-tier rate limit is tiny. Never run
> `verify/demo` or other live AI calls unless the owner explicitly asks. Test with the
> scripted provider or stubbed `fetch` instead. Don't leave the owner's dev server on :8080
> running, or occupy that port: use a temporary server on :8081 for tests and stop it afterwards.

For the full design rationale written for humans (the boss demo), read [README.md](README.md).
This file is the operational summary for Claude.

---

## 1. What this project is

A demo ERP (Yii 2) with an AI chatbot. It proves one architecture: **the AI writes SQL
dynamically, but can only run it against role-scoped read-only MySQL VIEWS, through a MySQL
account (`erp_ai_ro`) that has SELECT on those views and nothing else.**

- Information questions ("what's our leave policy?") are answered from the `company_info` table
  in the prompt, with no SQL.
- Data questions: the AI calls `getUserRole()`, then `runReadOnlyQuery(sql)`. The server
  validates, binds `:me` / `:dept` from the session, and runs the query on `dbAi`.
- **The refusal is the key demo moment**: an employee asking for the average salary gets
  "You're not authorised to access salary information."

## 2. Owner preferences (follow these)

- **Don't ask clarifying questions for things with a sensible default.** Decide, say what
  you chose, and proceed. Only ask when truly blocked (for example a missing credential).
- **Test every change.** Run `php yii verify/all` (and `verify/demo` for AI changes) before
  saying something works. Report failures honestly.
- **Hard stack constraints** (from the original spec; do not violate):
  - Yii 2 basic template only (not Yii 3, not Laravel). PHP 8, MySQL 8.
  - **No Composer packages beyond Yii.** No Guzzle (use curl), no LLM SDK, no vector DB,
    no Node/npm. Ask the owner before adding any package.
  - **No Yii RBAC** (`authManager`). Roles are the `employees.role` column + `config/access-map.php`.
  - No fixed library of pre-written queries: dynamic SQL under validation is the point.
  - The AI's output must never decide which view or which employee_id is used.
  - Never show raw SQL errors to users. Never skip the audit log (one row per chat turn).
- **Secrets are committed on purpose** (owner decision, 2026-09-27): `config/db-local.php`
  (DB passwords, cookie key) and `config/ai.php` (provider/model settings) are in git so every
  device can just pull and run. All data is fabricated. Don't "fix" this by re-ignoring them.
- **EXCEPTION: the AI API key is NOT in git.** The GitHub repo is public, so GitHub would block
  the push and Google would auto-revoke a leaked key. Keys live in the gitignored
  `config/ai-local.php`, which is merged over `config/ai.php`. Never commit a key.

## 3. Environment

| Thing | Value |
|---|---|
| OS (original device) | Windows 10, VS Code, Git Bash + PowerShell |
| PHP | 8.5 at `C:\php` (on PATH) |
| Composer | `C:\ProgramData\ComposerSetup\bin\composer.phar`; may not be on PATH, run `php C:/ProgramData/ComposerSetup/bin/composer.phar ...` |
| MySQL | 8.0 service `MySQL80`, client `C:\Program Files\MySQL\MySQL Server 8.0\bin\mysql.exe`. Use `--host=127.0.0.1` (PowerShell mangles `-h127.0.0.1`). |
| Database | `erp_demo`, utf8mb4_unicode_ci, no table prefix |
| MySQL accounts | `erp_app` (ALL on erp_demo), `erp_ai_ro` (SELECT on the 20 v_* views only). Both exist for `@localhost` and `@127.0.0.1`. The app connects via `127.0.0.1`. Passwords are in `config/db-local.php`. |
| MySQL root | Needed only for setup. The password is machine-specific and **not stored in the repo**; ask the owner. |
| Web | `php yii serve localhost:8080` → http://localhost:8080 |
| Demo logins | 10 users `*@demo.local`, all with password `Demo@1234`. Use the "Switch user" menu to change identity. |
| AI | `config/ai.php`: provider **`mistral`**, model **`ministral-14b-latest`** (the free tier excludes large/medium/small), `minIntervalMs` 2100, temperature 0, mode `live`, cache on. Fallbacks: `gemini` (`gemini-3.6-flash`) and `groq` (`openai/gpt-oss-120b`, no key yet). **Keys in `config/ai-local.php` (gitignored, one per device; copy `config/ai-local.php.example`).** |

## 4. Setting up a new device

```bash
git pull
php C:/ProgramData/ComposerSetup/bin/composer.phar install   # or: composer install
php yii setup/database <mysql-root-password>                   # DB + erp_app + migrate + erp_ai_ro
cp config/ai-local.php.example config/ai-local.php            # then paste the Gemini key into it (ask the owner)
php yii serve localhost:8080                                   # leave running
php yii verify/all                                             # expect 0 failed
php yii verify/demo                                            # live AI; free-tier quota permitting
```

`setup/database` is idempotent. If MySQL on that device refuses TCP logins for a user that
exists, check for an anonymous `''@'localhost'` row, or try host `localhost` in `db-local.php`.

## 5. Architecture: four security layers

1. **Two MySQL accounts.** `db` (erp_app) is for the app and migrations. `dbAi` (erp_ai_ro)
   is only for AI SQL. The views are `SQL SECURITY DEFINER`, so erp_ai_ro can read them with no
   base-table grants. `dbAi` also sets `MAX_EXECUTION_TIME=5000`,
   `transaction_read_only=1`, a pinned `sql_mode` (no ANSI_QUOTES / NO_BACKSLASH_ESCAPES, so
   MySQL tokenises strings the same way the validator does), and `EMULATE_PREPARES=false`.
2. **Role-scoped views** (`migrations/m260927_000005_create_role_views.php`, 20 views).
   Only `v_hr_employees_full`, `v_hr_payroll` and `v_exec_payroll_summary` touch `salaries`.
3. **Bound params.** A view can't know who is asking. The AI writes `:me` / `:dept`;
   `QueryExecutor` binds them from the session `Employee`. **Extra hardening beyond the
   spec:** the validator rewrites each scoped view into
   `(SELECT * FROM v_x WHERE col = :me) AS v_x`, so `WHERE employee_id = :me OR 1=1`
   still returns only the asker's rows.
4. **Validator** (`components/ai/SqlValidator.php`): a real tokenizer. It enforces SELECT
   only, one statement, no comments, no `@`/`?`, no system schemas, a per-role table
   allowlist after FROM/JOIN/comma, the required scope predicate (`col = :me`), and
   LIMIT ≤ 200 (appended or rewritten).

**Single source of truth:** `config/access-map.php` (role → views → scope column/param,
refusal wording). Both `SqlValidator` and `PromptBuilder` read it.

### Roles

| role | rows | salary | views |
|---|---|---|---|
| employee | own (`employee_id = :me`) | no | v_employee_directory, v_my_* |
| manager | + direct reports (`manager_id = :me`) | no | + v_team_* |
| dept_head | whole dept (`department_id = :dept`) | no | directory, v_my_*, v_dept_* |
| hr | all | yes | directory, v_my_*, v_hr_* (incl. extra `v_hr_leave_balances_all`) |
| ceo | all | yes | + v_exec_* |

### Chat flow (`components/ai/ChatService.php`)
1. The identity comes from the session. The request body is only `{"question": ...}`; any
   role or id in the body is ignored.
2. `PromptBuilder` builds the system prompt: the KB, the columns of only this role's views,
   and the SQL rules. It never contains the user's id.
3. Tool loop (max 6 rounds). A text-only reply is the info path. A reply starting
   `ACCESS_DENIED:` means the model refused (no SQL) and becomes path `denied`.
4. A `runReadOnlyQuery` refusal **ends the turn** with a server-written message. A failing
   query (bad column) gets a sanitised hint, with up to 2 retries.
5. Exactly one `chat_audit_log` row per turn (info/data/denied/error). `employee_id` has no FK.

## 6. File map

| Path | Purpose |
|---|---|
| `config/db-local.php` | Host, db name, erp_app + erp_ai_ro passwords, cookie key (committed) |
| `config/ai.php` | Provider, model, settings (committed, apiKey left empty) |
| `config/ai-local.php` | **API keys, gitignored**, per device. Template: `ai-local.php.example`. Merged over ai.php by `ProviderFactory::config()`. |
| `config/db.php`, `config/db-ai.php` | The `db` and `dbAi` connections |
| `config/access-map.php` | Role/view/scope map |
| `config/params.php` | `demoRoleSwitcher` flag |
| `sql/00-bootstrap.sql`, `sql/01-ai-readonly-user.sql` | Root scripts with `__PASSWORD__` placeholders (use `setup/database`) |
| `migrations/m260927_00000{1,2,3}_*` | Schema: org tables; leave/attendance; company_info + chat_audit_log |
| `migrations/m260927_000004_seed_demo_data.php` | Seed data: dates relative to run day, `mt_srand` fixed |
| `migrations/m260927_000005_create_role_views.php` | The 20 views |
| `components/ai/` | AccessMap, SqlValidator, RejectedQuery, ValidationResult, QueryExecutor, QueryFailed, QueryGateway, PromptBuilder, ChatService |
| `components/ai/provider/` | LlmProvider interface; **OpenAiCompatibleProvider (ONE class for Mistral + Groq; options toolMessageName/extraBody)**; GeminiProvider (native); ProviderFactory (client chosen by config `type`, transport by `mode`); Transport interface: HttpJson (curl + Throttle + 429/503 exponential retry, 3 attempts), RecordingTransport / ReplayTransport + FixtureStore (fixtures, never headers), StubTransport + ScriptedProvider (tests only); ProviderError (safe user messages) |
| `components/ai/ResponseCache.php` | Answer cache (Yii FileCache in runtime/ai-cache), key = user id + role + normalised question + provider/model + data version. Clear: `php yii ai-cache/clear` (`commands/AiCacheController.php`) |
| `tests/fixtures/ai/mistral/` | Recorded real Mistral responses for the 5 demo questions (replay mode) |
| `components/TestHttpClient.php` | Cookie+CSRF curl client used by the verify commands |
| `controllers/SiteController.php` | Login, logout, `switch-user` (POST+CSRF, gated by param) |
| `controllers/ChatController.php` | `index` (the "Demo Chatbot Testing Interface" dashboard, the default route), `ask` (POST JSON), `audit` (hr/ceo see all, others their own) |
| `controllers/SecurityTestController.php` | Web test bench: hand-written SQL through QueryGateway, shows the role's prompt |
| `commands/VerifyController.php` | `verify/phase1..6`, `verify/all`, `verify/demo` (live AI) |
| `commands/SecurityController.php` | `security/prompt <email>`, `security/check <email> "<sql>"`, `security/raw "<sql>"` (bypasses the validator to show a MySQL 1142) |
| `commands/SetupController.php` | `setup/database <rootpw>` |
| `models/Employee.php` | AR + IdentityInterface. `models/Department.php`, `models/ChatAuditLog.php`, `models/LoginForm.php` (email login) |
| `views/chat/index.php` | Dashboard page behind the chat (no chat code in it) |
| `views/layouts/_chat_widget.php` | **Floating chat widget** markup (bottom-right button + Messenger-style popup: SQL toggle, expand, clear, close, suggestion chips). Rendered by `layouts/main.php` for signed-in users on EVERY page. The per-role suggestions live here. |
| `web/js/chat-widget.js` | Widget behaviour: open/close, Esc, unread dot, expand, clear, safe markdown/table/SQL rendering, history in sessionStorage key `erp.chat.v1.<userId>` (other users' keys deleted on load; all wiped on the signed-out layout; max 50 messages) |
| `web/css/chat-widget.css` | Widget styles (380x560, expanded 640x720, height capped at `100vh-230px` so it never covers the navbar, lifted above the Yii debug toolbar, full-screen under 576px) |
| `assets/ChatWidgetAsset.php` | Asset bundle for the widget (depends on AppAsset for yii.js/CSRF) |

## 7. Seed data facts (used by the tests; update the tests if you change the seed)

- Departments: EXEC, ENG, SLS, HR. There are 10 employees; dev1 = Arif Hossain (id 5),
  enghead = Tanvir Ahmed, hr = Nusrat Jahan, ceo = Mahbubur Rahman Chowdhury (id 1).
- dev1's leave balance: Annual 16/20, Sick 12/14, Casual 10/10 (38 paid days left).
- Engineering has 5 pending leave requests (Arif ×2, Sadia, Rakibul, Farhana).
  Engineering's average current gross salary is **BDT 218,400** (5 people).
- Executive and HR have 1 person each (the small-group disclosure limitation).
- 31 leave requests, 40 balances, 400 attendance rows (40 working days, no Fri/Sat).
- Refresh the relative dates before a demo: `php yii migrate/redo 2` (grants survive it).

## 8. Testing

- `php yii verify/all` runs 177 checks across phases 1–7 and needs a running server. Use a temporary
  one: `php yii serve localhost:8081` plus `php yii verify/all --baseUrl=http://localhost:8081`, then
  stop it. **No AI calls**: phase 5 uses `ScriptedProvider` (including a malicious model), and its one HTTP
  `chat/ask` check sends an over-long question that is rejected before the AI. For a hard guarantee, move
  `config/ai-local.php` aside while testing and put it back afterwards.
- `php yii verify/demo` runs the 5 demo questions live (the cache is bypassed; `--useCache=1` allows it). It uses about 3 API
  calls per data question. **Only when the owner explicitly asks.** `--aiMode=record` re-records the fixtures.
- `php yii verify/demo --aiMode=replay` runs the same 5 questions from the fixtures: no network, no key, about 40 ms each.
  Use it freely. `verify/phase7` tests the real provider clients against canned wire JSON (StubTransport).
- Browser UI checks: headless Edge
  (`C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe --headless=new --screenshot`)
  on a temporary same-origin harness page in `web/` (for example `web/_uitest.html`). It logs in by POSTing to
  `site/switch-user`, loads app pages in an iframe, and **stubs `iframe.contentWindow.fetch`** with canned
  `chat/ask` JSON, so there are no AI calls. Launch it via PowerShell `Start-Process` (Git Bash gets no output), and
  pin the result text with `position:fixed`, because autofocus scrolls the page. Headless virtual time
  freezes CSS animations and transitions, so measure `offsetWidth` or the computed style, not
  `getBoundingClientRect`. Delete the harness afterwards.

## 9. Known issues / gotchas

- **Mistral free tier** (checked 2026-09-28 via `x-ratelimit-*` response headers): mistral-large-latest gives 403 "not
  available in your subscription tier"; mistral-medium/small and magistral have `x-ratelimit-limit-req-minute: 0`
  (always 429). Allowed: ministral-14b-latest 30/min (default), codestral-latest 125/min, ministral-8b-latest 188/min.
  The limits are per model per minute, so the throttle `minIntervalMs` must be >= 60000/limit. To find what a key
  can use, send a 1-token request per model and read the headers (GET /v1/models lists models the key cannot use too).
- **Gemini 2.5 is closed to new users** ("no longer available to new users") → use `gemini-3.x-flash`.
- **Free-tier limits**: 429 (quota) and 503 (high demand) are common. `HttpJson` retries with exponential backoff
  (1.5s, 3s; max 3 attempts, Retry-After honoured). Every retry still counts against quotas.
- **PHP JSON gotcha (fixed)**: an empty `"args": {}` from Gemini decodes to `[]` and would be
  re-sent as a list, which gives HTTP 400. `GeminiProvider` restores it as an object. Watch
  for the same thing with any other empty JSON object.
- **Windows TLS**: PHP has no CA bundle, so HttpJson uses `CURLSSLOPT_NATIVE_CA`. Never disable verification.
- The Yii CSRF token rotates on login, and TestHttpClient refreshes it after redirects.
- Native Windows PHP can't see Git Bash's `/tmp`, so use the repo's `runtime/` or the scratchpad.
- The stock yii2-app-basic `LoginForm.php` shipped with a syntax error; it has been replaced.
- **Stable ids (fixed 2026-09-28):** `migrate/redo 2` used to re-seed employees as ids 11-20 (auto-increment
  kept counting). The seed `safeDown` now resets AUTO_INCREMENT, so ids are always ceo=1, hr=2, enghead=3,
  engmgr=4, dev1=5 ... sales2=10. Code and tests look users up by email anyway; the ids matter for docs and demo visuals.

## 10. Documented limitations (README "Known limitations")

Mistral Free mode trains on inputs unless opted out (the owner has opted out); still a blocker for real data. Small
groups make aggregates individual disclosure (min-group-size is future work). The tier split
between views is enforced by the validator, not MySQL: one erp_ai_ro account for all roles,
and the hardening step is one account per tier. The validator is a conservative allowlist.
The demo role switcher must be off outside demos.

## 11. Status / next steps

- Phases 1–7 are built and pass `verify/all` (177/177, no AI calls).
- **Floating chat widget: DONE (2026-09-28).** The home page is the "Demo Chatbot Testing Interface"
  dashboard; the bottom-right button toggles a Messenger-style popup on every signed-in page; history is
  kept per user in sessionStorage. Verified by `verify/phase6` and a headless-Edge run with stubbed fetch
  (open/close, history kept on toggle and across pages, Esc, unread dot, expand, HR sees a fresh chat,
  sign-out wipes storage). There were no backend changes.
- **Mistral is the primary provider: DONE (2026-09-28).** Live `verify/demo` on ministral-14b-latest: **5/5 pass**
  (recorded to fixtures); `--aiMode=replay` gives 5/5 with no key. Earlier live Gemini runs: #1-#3 passed, #4/#5 hit quota.
- Suggested: add a Groq key as a demo-day fallback.

## Change log

Newest first. Format: `YYYY-MM-DD (device) — change`.

- 2026-09-28 (original Windows device) — **Primary AI switched to Mistral** (owner spec). One OpenAI-compatible client
  for Mistral + Groq (Gemini kept native). Added a Transport layer: throttle (minIntervalMs, cross-request, flock) and
  429/503 exponential retry (3 attempts); a per-user ResponseCache + `ai-cache/clear`; live/record/replay `mode` with
  fixtures in tests/fixtures/ai; `verify/phase7` (28 no-network checks); `verify/demo --aiMode/--useCache`. The free tier
  excludes mistral-large, so the model is ministral-14b-latest (2100ms throttle). A 403 about model/tier maps to
  "model not available". README provider table, limits and Mistral training note (owner opted out) updated.
  Not touched: schema, views, validator, access map, audit.
- 2026-09-28 (original Windows device) — New owner rule: never `git push` without asking; local commits only.
- 2026-09-28 (original Windows device) — **Floating chat widget** replaces the full-page chat: new
  `_chat_widget.php`, `chat-widget.js/.css`, `ChatWidgetAsset`; the home page is now a dashboard; the nav link "Chat" became
  "Dashboard"; old full-page chat CSS was removed from site.css. The seed `safeDown` resets AUTO_INCREMENT (stable ids
  1-10). `verify/phase5` no longer makes a live AI call; `verify/phase6` has widget checks (149 total).
- 2026-09-28 (original Windows device) — Owner switched model to `gemini-3.6-flash`. Added the rules:
  update CLAUDE.md at every step, never burn the AI quota, keep :8080 free.
- 2026-09-27 (original Windows device) — Initial build, phases 1–6. Added `setup/database`.
  Switched Gemini model to `gemini-3.8-flash`. Fixed the Gemini empty-args replay bug. Added
  429/503 retry. Committed DB secrets on purpose (owner decision); the AI key is kept out of git in
  gitignored `config/ai-local.php` because the repo is public. Created this file.
