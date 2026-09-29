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
  device can just pull and run (these hold only local DB passwords and settings). Don't "fix" this by re-ignoring them.
  The ERP DATA itself is a copy of the real training database: the dump is gitignored, and never paste its
  personal data (names, salaries, password hashes) into commits, docs or chat beyond what a task needs.
- **EXCEPTION: the AI API key is NOT in git.** The GitHub repo is public, so GitHub would block
  the push and Google would auto-revoke a leaked key. Keys live in the gitignored
  `config/ai-local.php`, which is merged over `config/ai.php`. Never commit a key.

## 3. Environment

| Thing | Value |
|---|---|
| OS (original device) | Windows 10, VS Code, Git Bash + PowerShell |
| PHP | 8.5 at `C:\php` (on PATH) |
| Composer | `C:\ProgramData\ComposerSetup\bin\composer.phar`; may not be on PATH, run `php C:/ProgramData/ComposerSetup/bin/composer.phar ...` |
| MySQL | 8.0.46 service `MySQL80`, client `C:\Program Files\MySQL\MySQL Server 8.0\bin\mysql.exe`. Use `--host=127.0.0.1` (PowerShell mangles `-h127.0.0.1`). `lower_case_table_names=1`. |
| Database | **`erp_training`**: the imported training ERP (1288 tables) plus our `ai_*` tables and views. (`erp_demo`, the old self-made demo DB, still exists but is unused.) `erp_master`: local master DB for the ERP clone (cid `training` → erp_training). |
| Source dump | `trainingclouderp_training_db.sql` (91 MB, MariaDB 10.11) in the project root. **Gitignored: PII + password hashes, and the repo is public. Never commit it; copy it between devices by hand.** |
| MySQL accounts | `erp_app` (ALL on erp_training + erp_master), `erp_ai_ro` (SELECT on the 25 v_* views only), `erp_ai_plugin` (ERP plug-in: column-level SELECT on 681 catalogued tables + 16 views; password only in the ERP clone's gitignored-by-design `config.local.php`). Both exist for `@localhost` and `@127.0.0.1`. The app connects via `127.0.0.1`. Passwords are in `config/db-local.php`. |
| MySQL root | Needed only for setup/import. The password is machine-specific and **not stored in the repo**; ask the owner. |
| Web | `php yii serve localhost:8080` → http://localhost:8080 (the owner's; use :8081 for tests) |
| Demo logins | ERP usernames `bimol`, `hr.demo`, `1005`, `1002`, `tanvir`, `1954` (listed in `config/params.php` `demoPeople`), password **`Demo@1234`**. Every in-service employee's login also accepts Demo@1234. |
| AI | `config/ai.php`: provider `gemini`, model **`gemini-3.7-flash`**, temperature 0. **Key in `config/ai-local.php` (gitignored, one per device; copy `config/ai-local.php.example`).** Groq fallback `openai/gpt-oss-120b`, no key yet. |

## 4. Setting up a new device

```bash
git pull
php C:/ProgramData/ComposerSetup/bin/composer.phar install   # or: composer install
# copy trainingclouderp_training_db.sql into the project root (it is not in git)
php yii setup/database <mysql-root-password>   # erp_app + import dump (~6 min) + migrate + erp_ai_ro
cp config/ai-local.php.example config/ai-local.php            # then paste the Gemini key into it (ask the owner)
php yii serve localhost:8081                                   # temporary test server
php yii verify/all --baseUrl=http://localhost:8081             # expect 151 passed, 0 failed
```

`setup/database` rebuilds erp_training from the dump every time (it DROPS the database). If MySQL
on that device refuses TCP logins for a user that exists, check for an anonymous `''@'localhost'`
row, or try host `localhost` in `db-local.php`.

## 5. Architecture: four security layers

1. **Two MySQL accounts.** `db` (erp_app) is for the app and migrations. `dbAi` (erp_ai_ro)
   is only for AI SQL. The views are `SQL SECURITY DEFINER`, so erp_ai_ro reads them with no
   grant on any of the 1288 ERP tables. `dbAi` also sets `MAX_EXECUTION_TIME=5000`,
   `transaction_read_only=1`, a pinned `sql_mode` (no ANSI_QUOTES / NO_BACKSLASH_ESCAPES, so
   MySQL tokenises strings the same way the validator does), and `EMULATE_PREPARES=false`.
2. **Role-scoped views** (`migrations/training/m260928_000003_create_role_views.php`): 25 `v_*`
   views over 6 internal `ai_*` helper views. The helpers are not granted and not in any
   allowlist. Only `v_hr_employees_full`, `v_hr_payroll` and `v_exec_payroll_summary` read
   `salary_info`. No v_* view exposes passwords, tokens, DOB, religion, NID/TIN/CV/photo paths,
   personal phone or bank account/branch. The views also clean the ERP data (zero/1970 dates,
   orphan leave rows, numeric leave types → names, attendance flags → one status, "newTest"
   leave type ignored).
3. **Bound params.** `:me` = session employee's `pbi_id`; `:dept` = the department the role is
   scoped to (a dept head's assigned dept). `QueryExecutor` binds them from the session
   `Employee`. The validator also wraps each scoped view in
   `(SELECT * FROM v_x WHERE col = :me) AS v_x`, so `... OR 1=1` cannot widen the rows.
4. **Validator** (`components/ai/SqlValidator.php`): a real tokenizer. It enforces SELECT
   only, one statement, no comments, no `@`/`?`, no system schemas, a per-role table
   allowlist after FROM/JOIN/comma, the required scope predicate (`col = :me`), and
   LIMIT ≤ 200 (appended or rewritten). No code change was needed for the new DB.

**Single source of truth:** `config/access-map.php` (role → views → scope column/param,
refusal wording, how each tier reads "my team"). Both `SqlValidator` and `PromptBuilder` read it.

### Roles and how a tier is decided (`components/RoleResolver.php`, per request)

1. `ai_role_assignment` (pbi_id → ceo | hr | dept_head [+ dept_id]) wins;
2. else line manager (`incharge_id` or `incharge_id_2`) of ≥1 in-service employee → manager;
3. else employee.

**Never** use the ERP's `user_activity_management.level`: it is a module privilege, and 52/76
logins are 5 "Supreme Administrator".

| role | rows | salary | views |
|---|---|---|---|
| employee | own (`employee_id = :me`) | no | v_employee_directory, v_my_* (5) |
| manager | + reporting line (`supervisor_id = :me`, depth 1 = direct) | no | + v_team_* (4) |
| dept_head | + whole dept (`department_id = :dept`) | no | + v_dept_* (4) |
| hr | all | yes | directory, v_my_*, v_hr_* (7) |
| ceo | all + own reporting line | yes | directory, v_my_*, v_team_*, v_hr_*, v_exec_* (4) |

The team views use `ai_reporting_line`, a recursive CTE over incharge_id + incharge_id_2. It is
cycle-safe (a path check, because the ERP has circular chains), capped at depth 6, with one
row per (employee, supervisor) and MIN(depth). `v_exec_payroll_summary` suppresses pay for
groups under 5 (`MIN_GROUP` in the migration).

### Identity / login
- `models/Employee.php` = `personnel_basic_info` (pk pbi_id). It exposes a stable surface the
  security code uses: `id` (pbi_id), `role`, `department_id` (scoped dept), `full_name`,
  `email`, `designation`, `department` (relation to `department`, NOT `setup_department`).
- `models/ErpUser.php` = `user_activity_management` (username; PBI_ID link). Password: bcrypt in
  `ai_user_credential` if issued, else the ERP's unsalted MD5; plaintext rows are never accepted.
- Only logins of an **In Service** employee can sign in (`LoginForm`). Cookie auto-login is
  off (the ERP has no auth key). The role switcher and login page list `params['demoPeople']`.

### Chat flow (`components/ai/ChatService.php`)
1. The identity comes from the session. The request body is only `{"question": ...}`; any
   role or id in the body is ignored.
2. `PromptBuilder` builds the system prompt: `ai_knowledge_base`, the columns of only this role's
   views, and the SQL rules. It never contains the user's id.
3. Tool loop (max 6 rounds). A text-only reply is the info path. A reply starting
   `ACCESS_DENIED:` means the model refused (no SQL) and becomes path `denied`.
4. A `runReadOnlyQuery` refusal **ends the turn** with a server-written message. A failing
   query (bad column) gets a sanitised hint, with up to 2 retries.
5. Exactly one `ai_chat_audit_log` row per turn (info/data/denied/error). `employee_id` = pbi_id, no FK.

## 6. File map

| Path | Purpose |
|---|---|
| `config/db-local.php` | Host, dbname **erp_training**, erp_app + erp_ai_ro passwords, cookie key (committed) |
| `config/ai.php` | Provider, model, settings (committed, apiKey left empty) |
| `config/ai-local.php` | **API keys, gitignored**, per device. Template: `ai-local.php.example`. Merged over ai.php by `ProviderFactory::config()`. |
| `config/db.php`, `config/db-ai.php` | The `db` and `dbAi` connections |
| `config/access-map.php` | Role/view/scope map (25 views) |
| `config/params.php` | `demoRoleSwitcher` flag, `demoPeople` (usernames shown in switcher/login) |
| `config/console.php` | `migrate` → `@app/migrations/training`, history table `ai_migration` |
| `sql/00-bootstrap.sql`, `sql/01-ai-readonly-user.sql` | Root scripts with `__PASSWORD__` placeholders (use `setup/database`); 01 has one GRANT per v_* view |
| `migrations/training/m260928_000001_create_ai_tables.php` | ai_role_assignment, ai_user_credential, ai_knowledge_base, ai_chat_audit_log |
| `migrations/training/m260928_000002_demo_people_and_access.php` | Tier assignments; creates demo HR employee 45728 + login `hr.demo` (copied from a template row, personal fields blanked, zero dates ERP-style); Demo@1234 for every in-service login |
| `migrations/training/m260928_000003_create_role_views.php` | 6 ai_* helpers + 25 v_* views |
| `migrations/training/m260928_000004_knowledge_base.php` | KB generated from hrm_leave_type, hrm_schedule_info, hris_holiday_setup, hris_late_policy_config, departments, titles (+ drafted procedural rows, labelled) |
| `migrations/demo/` | The old self-made demo schema. Unused, kept for reference |
| `components/TrainingDumpConverter.php` | MariaDB → MySQL 8 rewrite of the dump (ENUM dedup, DATE default → `(curdate())`, `innodb_strict_mode = 0`) |
| `components/RoleResolver.php` | Tier resolution (see §5) |
| `components/ai/` | AccessMap, SqlValidator, RejectedQuery, ValidationResult, QueryExecutor, QueryFailed, QueryGateway, PromptBuilder, ChatService |
| `components/ai/provider/` | LlmProvider interface, GeminiProvider, OpenAiCompatibleProvider (Groq), ScriptedProvider (tests), ProviderFactory, HttpJson (curl), ProviderError |
| `components/TestHttpClient.php` | Cookie+CSRF curl client used by the verify commands |
| `controllers/SiteController.php` | Login, logout, `switch-user` (POST+CSRF, gated by param) |
| `controllers/ChatController.php` | `index` (the "Demo Chatbot Testing Interface" dashboard, the default route), `ask` (POST JSON), `audit` (hr/ceo see all, others their own) |
| `controllers/SecurityTestController.php` | Web test bench: hand-written SQL through QueryGateway, shows the role's prompt |
| `commands/VerifyController.php` | `verify/phase1..6`, `verify/all`, `verify/demo` (live AI) |
| `commands/SecurityController.php` | `security/prompt <username>`, `security/check <username> "<sql>"`, `security/raw "<sql>"` (bypasses the validator to show a MySQL 1142) |
| `commands/SetupController.php` | `setup/database <rootpw>` (full rebuild), `setup/import-training <rootpw>` (import only; runs the mysql client AS ROOT, because the converted dump sets innodb_strict_mode) |
| `models/Employee.php`, `ErpUser.php`, `Department.php`, `Designation.php`, `LoginForm.php` (username), `ChatAuditLog.php` (ai_chat_audit_log) | |
| `views/chat/index.php` | Dashboard page behind the chat (no chat code in it) |
| `views/layouts/_chat_widget.php` | **Floating chat widget** markup (bottom-right button + Messenger-style popup: SQL toggle, expand, clear, close, suggestion chips). Rendered by `layouts/main.php` for signed-in users on EVERY page. The per-tier suggestions live here. |
| `web/js/chat-widget.js` | Widget behaviour: open/close, Esc, unread dot, expand, clear, safe markdown/table/SQL rendering, history in sessionStorage key `erp.chat.v1.<userId>` (other users' keys deleted on load; all wiped on the signed-out layout; max 50 messages) |
| `web/css/chat-widget.css` | Widget styles (380x560, expanded 640x720, height capped at `100vh-230px` so it never covers the navbar, lifted above the Yii debug toolbar, full-screen under 576px) |
| `assets/ChatWidgetAsset.php` | Asset bundle for the widget (depends on AppAsset for yii.js/CSRF) |

## 7. Data facts (used by the tests; update `VerifyController` constants if the dump changes)

- A software company. `personnel_basic_info` has 56 rows (55 from the dump + demo HR 45728); 35 are In
  Service. Department 10 "Engineer" has 31 of them. `user_activity_management` has 77 rows.
- Demo people: `bimol` = 1001 Bimol Chandra Das (CTO) → ceo, 21 people in his reporting line;
  `1005` = Payer Alam Rony (CTO Operation) → dept_head of dept 10; `1002` = Kawsar Mahmud (Sr PM) →
  manager of 3 (Jobaraj Miah, Zawad-Al-Mustakin, Iftekhar Ahmed Rifat); `tanvir` = 1960 Tanvir Ahmmed
  (Jr SE) → employee; `1954` = Md Nizam Uddin → employee; `hr.demo` = 45728 Farzana Rahman (Demo HR) → hr.
- Tanvir's 2026 balance: Casual 13 of 15 (2 used), Sick 6, Marriage 10, Maternity 30, Paid 5.
- Engineer average current gross salary: **BDT 66,429** (17 people with gross > 0). The other
  departments with salary have 1 person each → suppressed in the exec summary.
- Engineering has 54 pending leave requests (Dec 2024 – Aug 2026). Leave data ends Aug 2026;
  daily attendance for active staff ends 2026-02-02; monthly attendance ends Jan 2026.
- Office: Day Shift 10:20–18:00, 20-min grace; Friday weekend; 2026 holidays in hris_holiday_setup.

## 8. Testing

- `php yii verify/all` runs **151** checks across phases 1–6 and needs a running server. Use a
  temporary one: `php yii serve localhost:8081` plus `php yii verify/all --baseUrl=http://localhost:8081`,
  then stop it. **No AI calls**: phase 5 uses `ScriptedProvider` (including a malicious model), and its
  one HTTP `chat/ask` check sends an over-long question that is rejected before the AI. For a hard
  guarantee, move `config/ai-local.php` aside while testing and put it back afterwards.
- `php yii verify/demo` runs the 5 demo questions live. It uses about 3 API calls per data
  question. **Only when the owner explicitly asks.**
- Browser UI checks: headless Edge
  (`C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe --headless=new --screenshot`)
  on a temporary same-origin harness page in `web/` (for example `web/_uitest.html`). It logs in by POSTing
  to `site/switch-user` (`id=<pbi_id>`), loads app pages in an iframe, and **stubs
  `iframe.contentWindow.fetch`** with canned `chat/ask` JSON, so there are no AI calls. Launch it via
  PowerShell `Start-Process` (Git Bash gets no output), and pin the result text with `position:fixed`,
  because autofocus scrolls the page. Headless virtual time freezes CSS animations, so measure
  `offsetWidth` or the computed style, not `getBoundingClientRect`. Delete the harness afterwards.
- Quick SQL inspection: the mysql client as `erp_app` against `erp_training` (password in db-local.php).
  Don't `grep -r` the project root: the 91 MB dump makes it crawl. Use the Grep tool with a glob.

## 9. Known issues / gotchas

- **MariaDB → MySQL 8 import** needs three rewrites (see TrainingDumpConverter). Zero dates import
  fine because the dump sets `SQL_MODE = "NO_AUTO_VALUE_ON_ZERO"`. Writing ERP rows from the app
  (strict mode) fails on zero dates: relax `sql_mode` for that session, as migration 0002 does.
- **Collations**: ERP tables are utf8mb4_unicode_ci and the connection is utf8mb4_0900_ai_ci. In views,
  compare codes numerically (`CAST(x AS UNSIGNED)`), not `CAST(id AS CHAR) = col` (error 1267).
- MySQL sorts ENUM columns by definition order, not alphabetically.
- **A view keeps the collation of the connection that CREATED it.** Yii's `charset => utf8mb4` sends
  `SET NAMES utf8mb4`, which gives the server default `utf8mb4_0900_ai_ci`. A bare PDO DSN `charset=utf8mb4`
  gives `general_ci`. Views created under general_ci made Yii's `status = 'Pending'` queries fail with 1267
  (verify/all 146/151, 2026-09-28). The ERP plug-in's `Db` now also runs `SET NAMES utf8mb4`. Check with
  `SELECT COLLATION_CONNECTION, COUNT(*) FROM information_schema.VIEWS WHERE TABLE_SCHEMA='erp_training' GROUP BY 1`
  (expect only 0900_ai_ci).
- **Gemini 2.5 is closed to new users** ("no longer available to new users") → use `gemini-3.x-flash`.
- **Free-tier limits**: 429 (quota) and 503 (high demand) are common. `HttpJson` retries twice
  (after 2s, then 5s). If you're still throttled, wait, or switch `provider` to `groq` (needs a key).
- **PHP JSON gotcha (fixed)**: an empty `"args": {}` from Gemini decodes to `[]` and would be
  re-sent as a list, which gives HTTP 400. `GeminiProvider` restores it as an object.
- **Windows TLS**: PHP has no CA bundle, so HttpJson uses `CURLSSLOPT_NATIVE_CA`. Never disable verification.
- The Yii CSRF token rotates on login, and TestHttpClient refreshes it after redirects.
- Native Windows PHP can't see Git Bash's `/tmp`, so use the repo's `runtime/` or the scratchpad.
- `sed` replacements containing `\a`/`\e` (e.g. `\app\models`) insert control characters; do such
  edits with the Edit tool or PHP.
- **Mistral free tier (tried and rolled back on 2026-09-28):** the owner's key got 403 "model not available in your
  subscription tier" for mistral-large-latest, and 0 req/min for mistral-medium/small and magistral. Only
  ministral-14b-latest (30/min), codestral-latest and ministral-8b-latest were usable. Code is in commit 6e6bd56.

## 10. Documented limitations (README "Known limitations")

**Free-tier AI data use now matters:** the chatbot runs on a copy of the real training ERP, so the
returned rows (names, leave reasons, and for HR/exec, salaries) go to the provider, which may train on
free-tier input. Use a paid tier or a self-hosted model beyond a controlled demo. The ERP stores passwords
as unsalted MD5 (2 as plaintext), which is a finding for the owner. Small groups: the exec payroll summary
suppresses groups under 5. The tier split between views is enforced by the validator, not MySQL (one
erp_ai_ro for all roles; hardening = one account per tier). The validator is a conservative allowlist.
The demo role switcher must be off outside demos.

## 11. Status / next steps

- **DONE (2026-09-28): the demo DB is replaced by the training ERP** (`erp_training`). Import + 4 training
  migrations + erp_ai_ro grants. `verify/all` passes 151/151 with the AI keys parked. The dashboard and chat
  widget render with real data (headless Edge screenshot as the dept head). **No live AI run on the new
  DB yet**: run `verify/demo` only when the owner asks.
- Scope is HR only (employees, leave, attendance, salary, directory). Other ERP modules (accounts, sales,
  CRM, hotel...) are possible later phases: each needs views + access-map entries + tests.
- **DONE (2026-09-28): the chatbot is plugged into the company ERP clone** at `D:\Workspace\app`. See section 13.
  It passes 99/99 offline tests (`tests/run_tests.php`, scripted model). The widget was checked in headless Edge on
  the dashboard and the Accounts module with a stubbed fetch. The owner's first live questions reached Gemini, which
  answered 503 (overloaded). That led to the 90 s turn budget and the clearer "busy" message (commit dc3d940).
  **2026-09-29: the owner confirmed successful live answers** (bimol: employees per department, vouchers this month;
  55-75 s each). An admin can now change the key/model from the chat itself (gear icon; see section 13).
- **WHERE WE LEFT OFF (end of 2026-09-28)**, to pick up next session:
  - The ERP server on :8090 ran from a Claude session and stops with it. Restart it with the command in section 13.
  - Deployment guide PDF for the owner: `D:\ERP_AI_Chatbot_Plugin_Guide.pdf`. The owner asked to leave Demo_chatbot out
    of it. It is not in git; its HTML source was in the session scratchpad and may be gone.
  - The owner was told how to fill `ai_knowledge_base` (section/title/body rows) and `ai_role_assignment` (pbi_id,
    role hr|dept_head|ceo, dept_id for dept_head) with SQL. Offered: a small ERP admin page to manage both without SQL.
  - **2026-09-29: `D:\Workspace\app` is UPLOAD-READY.** The owner will zip the whole folder and upload it to the live
    cPanel server. Nothing local is left in the folder; every developer-only setting lives in `D:\Workspace\erp_local_dev\`
    (see section 13). On the server it needs no command line:
    - `db_master_config.php` resolves to the original production values;
    - the plug-in installs its own tables/views on the first visit of an enabled company (`enabledCompanies` = `training`);
    - AI SQL runs on the company's own login, read-only (`tenantAccountFallback`);
    - the catalogue ships prebuilt.

    Alternative: the plug-in-only package `D:\ERP_AI_Chatbot_Upload.zip` (44 files) plus the two one-line hooks.
    **Warning given:** uploading the whole clone over live replaces every ERP file with the clone's version. That is
    only safe if the live code has not changed since the clone was taken.
  - Open items:
    - test the plug-in on a MariaDB staging copy (the MariaDB path is untested);
    - confirm on the live server: the PHP version, DB root/WHM access for `apply_grants.php`, and outbound HTTPS to Google;
    - the Yii demo has the same 30 s timeout weakness when Gemini is slow (fixed only in the plug-in);
    - get a paid Gemini tier before using real data.
- **Coming later (owner):** a new UI supplied by the boss. It must keep the floating Messenger-style chat
  widget on every signed-in page. Wait for the owner to say what goes where.
- Suggested: add a Groq key as a demo-day fallback.

## 12. Checkpoints (known-good states to return to)

| Name | Git tag | DB snapshot (local only, gitignored) | State |
|---|---|---|---|
| Training DB replaced | `checkpoint-2026-09-28-training-db` | `checkpoints/erp_training-2026-09-28.sql.gz` (original Windows device only) | App on erp_training; 4 training migrations; 151/151 verify; floating chat widget; Gemini `gemini-3.6-flash`; no live AI run on the new DB yet |

**Return to a checkpoint:**
```bash
git fetch --tags
git checkout -b back-to-checkpoint checkpoint-2026-09-28-training-db   # code
# DB, on the device that has the snapshot (fast):
gunzip -c checkpoints/erp_training-2026-09-28.sql.gz | mysql -u root -p   # recreates erp_training
# DB anywhere else (rebuild from the dump, ~7 min):
php yii setup/database <mysql-root-password>
```
Tell the owner before resetting `main` itself; prefer a branch from the tag.

## 13. ERP plug-in (the chatbot inside the company ERP)

The same idea as the Yii demo, packaged as a plug-in for the company's real ERP. The ERP is raw
PHP with no framework. The live copy is in the ERP clone; **`erp_plugin/` in this repo is a mirror**
(the ERP folder is not a git repo). Edit the plug-in in the ERP clone, then run
`bash erp_plugin/sync_from_erp.sh`. The script copies only plug-in files, never secrets or
generated data, and fails if it spots a key. Human docs: `erp_plugin/app/controllers/ai_chatbot/README.md`.

**Run the ERP locally:**
- Start it: `cd /d/Workspace/app && php -d extension=mysqli -d extension=gd -d short_open_tag=On -d display_errors=0 -d log_errors=1 -d error_log=/d/Workspace/erp_local_dev/php_errors.log -S 127.0.0.1:8090 -t /d/Workspace/app`
  (the log goes OUTSIDE the ERP folder, so an upload never carries it)
  - mysqli is OFF in `C:\php\php.ini`; enable it per command and don't edit php.ini.
- Open http://training.localhost:8090. The subdomain is the company id, which some print views need.
- Log in with company id `training`, a username, and `Demo@1234`:
  - `tanvir`: employee; Accounts, Procurement, Inventory, Sales, Production, CRM...
  - `1005`: dept head; Accounts, Procurement, Inventory, Sales.
  - `1002`: manager.
  - `bimol`: ceo, 19 modules.
  - `hr.demo`: hr; HRIS modules plus salary.
  - Avoid `1954`: two logins share that username, so the ERP rejects it.

**What changed in the ERP clone.** Originals are backed up in `D:\Workspace\app_originals\`.
**Never** copy those into a repo: `db_master_config.php` holds real production credentials.
- `app/controllers/config/db_master_config.php` holds the ORIGINAL production values again (since 2026-09-29).
  Its first lines load `D:\Workspace\erp_local_dev\db_master_config.php` if that file exists (it does only on this
  machine). That file is the local stand-in: localhost `erp_master` → company_info `training` → erp_training via erp_app.
  `dirname(__DIR__, 4)` of the config folder is `D:\Workspace`; on a server that path has no `erp_local_dev`.
- **`D:\Workspace\erp_local_dev\`** (outside the ERP folder, never uploaded, never in git) holds:
  - the local master config;
  - `ai_chatbot.config.php` (the local AI account `erp_ai_plugin` + `adminDb`, merged over config.local.php);
  - `ai_chatbot_runtime/` (plug-in log, install markers, grants record);
  - `php_errors.log`.
  If it is missing, the local clone would try the PRODUCTION master server first. Recreate it before running locally.
- One line before `</body>` in `app/controllers/routing/inc.main_layout.php` (every module page) and in
  `app/views/auth/masters/home.php` (the dashboard): `require_once SERVER_CORE."routing/inc.ai_chatbot.php"`.
- Added: `app/controllers/ai_chatbot/` (the plug-in), `app/controllers/routing/inc.ai_chatbot.php` (widget
  partial), `app/views/ai_chatbot/api/ask.php` (endpoint), `public/assets/ai_chatbot/` (css/js).

**Plug-in design** (`app/controllers/ai_chatbot/`, namespace `AiChatbot\`, autoloaded by `bootstrap.php`):
- `config.php` holds settings (Gemini `gemini-3.7-flash`).
- `config.local.php` holds only this server's Gemini key and is never mirrored to git. It ships with the upload.
  The local-only `aiAccounts` / `adminDb` live in `erp_local_dev/ai_chatbot.config.php`. Template: `config.local.php.example`.
- `enabledCompanies` (config.php, default `['training']`, matched against `$_SESSION['proj_id']`, case-insensitive).
  Other companies on the same server get no widget, and their DBs are never touched.
- `src/Installer.php`: `Identity::fromErpSession()` calls `Installer::ensure()`. On an enabled company's first visit it
  creates the ai_* tables and 31 views with the company's own login. It is flock-guarded and leaves a marker
  `installed.<db>.php` (VERSION const; bump it to force re-install). `data/seed.php` (training KB rows + roles
  1001 ceo / 1005 dept_head) goes only into EMPTY tables, and roles only if the employee is In Service.
- `tenantAccountFallback` (default true): with no `aiAccounts` entry, `Db::ai()` uses the session's company login with
  a read-only session. Layers 2-4 plus the column rewrite still hold; the MySQL column grants (layer 1) are then missing.
- `AI_CHATBOT_RUNTIME_DIR` (bootstrap) is where the log, markers and grants record go: `data/` on a server,
  `erp_local_dev/ai_chatbot_runtime/` here.
- `install/local_demo_setup.php` refuses to run unless `erp_local_dev` exists.
- **Settings panel** (gear icon in the chat, users in `settingsAdmins` only, checked server-side):
  - `app/views/ai_chatbot/api/settings.php` + `src/Settings.php` handle the provider, the API key (masked) and the model.
  - Stored in `AI_CHATBOT_RUNTIME_DIR/settings.php` and merged last by `Config`.
  - The live Gemini model list is cached 1 h per key (`models.<hash>.php`); history goes to `settings_history.log.php`.
- Identity comes from the ERP session. `mhafuz=Active`, `user.id`, `user.group` and the tenant `db_*` keys come
  from the login. Tier is decided the same way as `RoleResolver` (ai_role_assignment > line manager > employee).
  A login without an in-service employee gets tier `none`.
- **Access mirrors the ERP modules.** `install/build_catalog.php` works out which tables each module uses by
  scanning `views/<module_file>/` PHP for table names:
  - it keeps 681 tables (84 shared by 6+ modules) and drops empty, backup, excluded and unused ones;
  - it hides 130 sensitive columns and marks salary-type tables confidential (hr/ceo only);
  - HR personnel tables need an HR admin module;
  - the result is written to `data/catalog.json.php` (about 4 minutes).
- A user may query the tables of the modules enabled for them in `user_module_define`, plus shared tables and
  their tier's `v_*` views (`Catalog::VIEWS`, 16 of the 25).
- The AI's tools: `getUserRole`, `describeTables` (allowed tables only: columns, joins, notes, row counts; notes in
  `data/table_notes.php`) and `runReadOnlyQuery`. It writes its own SQL for any module: vouchers, ledgers,
  sales, stock...
- **Four layers:**
  1. MySQL `erp_ai_plugin` has column-level SELECT on the catalogued tables and SELECT on 16 views
     (`install/apply_grants.php <rootpw>`).
  2. The module-mirror policy (`AccessPolicy`).
  3. Bound scope: `:me`/`:dept` for views. Tables with `group_for` are wrapped as
     `(SELECT <allowed cols> FROM t WHERE group_for = <session company>)`.
  4. `SqlValidator`, ported from the Yii one, plus a `SELECT *` ban. Retryable mistakes go back to the model
     as hints; security refusals end the turn.
- Audit: `ai_chat_audit_log` gains an `erp_user_id` column. The plug-in writes employee_id = pbi_id and
  erp_user_id = the ERP login.
- `install/install_schema.php` creates the ai_* tables and the views; `install/views.php` is a verbatim port of
  training migration 0003 (**keep them in step**).
- After `php yii setup/database` (it drops erp_training), re-run the plug-in installers: install_schema, then
  build_catalog, then apply_grants.
- `data/` is inside the web root:
  - every file written there starts with `<?php http_response_code(404); exit; ?>` (constant `AI_CHATBOT_FILE_GUARD`);
  - `.htaccess` denies the folder on Apache;
  - installers and tests return 404 unless run from the CLI.
- Widget:
  - a vanilla-JS floating button on every signed-in page; history in sessionStorage `erp.aichat.v1.<proj>.<user>`;
  - CSRF via the ERP's `$_SESSION['csrf_token']`;
  - colours from the ERP's CSS variables (`--navy`/`--teal` on module pages, `--primary`/`--secondary` on the
    dashboard, dark mode followed);
  - a "host-page armour" block wins back fonts and inputs from the ERP's global `!important` rules;
  - suggestion chips per module in `data/suggestions.php` are only examples: no hard-coded SQL behind them.
- Time budget: one question is capped at `turnBudgetSeconds` (90). PHP's default 30 s limit killed slow Gemini calls, and
  `ask.php` now raises it. If Gemini is busy (503), the user sees "overloaded, try again in a minute", not a generic error.
- Tests: `cd /d/Workspace/app && php app/controllers/ai_chatbot/tests/run_tests.php`. It checks identity, policy,
  validator, MySQL grants, gateway, describeTables, the prompt, ChatService with a scripted model, and HTTP guards
  (401/405/403, identity from the session). The HTTP part needs the :8090 server; it is skipped otherwise.

## Change log

Newest first. Format: `YYYY-MM-DD (device) — change`.

- 2026-09-29 (original Windows device) — **Settings panel in the chat (gear icon)**, approved by the owner:
  - Choices: admins = `bimol` + the owner's own username (**not known yet**, ask them; the demo logins they used are
    bimol and tanvir); one key only; a live model list.
  - New: `src/Settings.php`, `app/views/ai_chatbot/api/settings.php` (POST get/models/save), gear + settings view in the
    widget (`?v=3`), config `settingsAdmins` and `providers.gemini.fallbackModels`.
  - The saved key/model live in the runtime folder `settings.php` (guarded, atomic write). `Config` merges them LAST, so
    a panel change applies to the next question. `ProviderFactory` now prefers the configured key over the
    `GEMINI_API_KEY` env var.
  - The live model list comes from Gemini `GET /models` (a metadata call, not a question), filtered to chat models,
    newest first, cached 1 h per key. A new key must pass that list before it is saved. The full key is never returned
    and only ever masked in `settings_history.log.php`.
  - `PromptBuilder` now tells the model the real SQL dialect (`SELECT VERSION()`: "MariaDB 10.11" on live, "MySQL 8" here).
  - Tests 99/99: a fake Google on :8096 (no real AI calls), plus HTTP admin/non-admin/CSRF checks. The panel was checked
    in headless Edge on the dashboard and the Accounts module.
  - The owner confirmed that live answers work ("gemini has done a good job"). Their later errors were Gemini 503/429
    (quota), not bugs. MariaDB: reviewed, owner accepts a first-deploy test. Privacy: all demo data (owner's call).
  - Upload package rebuilt (46 files).

- 2026-09-29 (original Windows device) — **`D:\Workspace\app` made upload-ready** (owner: "just zip the folder and upload
  it to cPanel and it will work"):
  - `db_master_config.php` is back to the original production values, plus a dev-only override from
    `D:\Workspace\erp_local_dev\`.
  - The plug-in's local DB accounts, log, markers and the PHP error log moved to `erp_local_dev`.
  - New: `enabledCompanies`, the automatic `Installer` (+ `data/seed.php`) and `tenantAccountFallback` (read-only).
  - `local_demo_setup.php` is locked to the dev machine.
  - Tests 77/77, incl. HTTP. A simulated server path resolves to the production config with no local accounts.
  - `D:\ERP_AI_Chatbot_Upload.zip` rebuilt (44 files, now incl. config.local.php with the key + the catalogue).

- 2026-09-29 (original Windows device) — **Live (cPanel) upload package** for the ERP plug-in:
  - Folder `D:\ERP_AI_Chatbot_Upload\` + `D:\ERP_AI_Chatbot_Upload.zip`, 40 files; not in git. Made with `tar -a`,
    because PowerShell 5.1's Compress-Archive writes backslash paths that break unzip on Linux.
  - Excludes config.local.php, tests/, `install/local_demo_setup.php` (it would reset every live password to Demo@1234),
    and the generated catalog/grants/log.
  - Hardening: `AccessPolicy::scopeOf` now wraps EVERY catalogued table to its non-sensitive columns (not only
    group_for tables). Hidden columns stay hidden even if the AI account only gets database-wide SELECT, as on cPanel
    without root, where column grants are impossible. New tests cover it: 70/70 incl. HTTP.
  - The two ERP hooks were re-checked against D:\Workspace\app_originals: exactly one added line each. The owner edits
    those two files on the server by hand, rather than uploading the local copies.

- 2026-09-28 (original Windows device) — End of day: section 11 records where we left off. The deployment PDF was
  revised to leave Demo_chatbot out. Code, the erp_plugin mirror and GitHub are in sync.

- 2026-09-28 (original Windows device) — **ERP plug-in: MariaDB support** (the live ERP DB is MariaDB 10.11). `Db::ai()` detects
  MariaDB and uses `max_statement_time` + `tx_read_only` instead of MySQL's `MAX_EXECUTION_TIME` + `transaction_read_only`
  (which would have failed every AI query). The MariaDB timeout code 1969 now maps to `timeout`. Tests pass 68/68 on MySQL;
  **not yet run on a real MariaDB**. Wrote the owner a deployment guide PDF at `D:\ERP_AI_Chatbot_Plugin_Guide.pdf`
  (source HTML in the session scratchpad; not in git). Findings for it: the plug-in needs no new extensions (the ERP
  already uses curl, mbstring and a PDO login helper), PHP 8.1+, MariaDB 10.2.2+, and a DB admin who can CREATE USER/GRANT.
  The live host looks cPanel-like (`/home/ezzyerp/`, per-folder php.ini), and the ERP web root exposes `phpinfo.php`.

- 2026-09-28 (original Windows device) — **ERP plug-in fix: "Could not reach the ERP server"** after a question. Cause: Gemini
  answered 503 (high demand), the retries plus a hung call passed PHP's 30 s `max_execution_time`, and PHP died with an
  HTML error that the widget could not parse. The database was never reached. Fix: a per-question time budget
  (`turnBudgetSeconds` = 90), so HttpJson skips retries and shortens curl timeouts to fit; `ask.php` sets the time limit to
  budget + 30 and always answers JSON (a shutdown handler); new ProviderError `busy` (503) and `timeout` messages; the
  widget tells a server error apart from a network failure. Tested against a local fake Gemini (503 / hang); no quota used.

- 2026-09-28 (original Windows device) — Owner switched the model to **`gemini-3.7-flash`** in both `config/ai.php` (Yii
  demo) and the ERP plug-in's `config.php`. No live AI test.

- 2026-09-28 (original Windows device) — **Chatbot plugged into the company ERP clone** (section 13). Added the plug-in, the
  widget partial, the endpoint and the assets in D:\Workspace\app, plus two one-line hooks and a local-only
  db_master_config. Built `erp_master`, the catalogue (681 tables) and the `erp_ai_plugin` column grants. Demo logins
  get Demo@1234. `ai_chat_audit_log.erp_user_id` added. Mirrored into `erp_plugin/` (with sync script). Tests 68/68 offline;
  headless-Edge widget check with a stubbed fetch. No live AI calls.

- 2026-09-28 (original Windows device) — Analysed the company ERP clone at D:\Workspace\app for plugging in the chatbot
  (see Status). No changes made yet; plan shown to the owner.
- 2026-09-28 (original Windows device) — **CHECKPOINT** `checkpoint-2026-09-28-training-db` (git tag, pushed) + local DB
  snapshot `checkpoints/erp_training-2026-09-28.sql.gz`. See section 12.
- 2026-09-28 (original Windows device) — **Demo DB replaced by the training ERP** (`erp_training`, imported from
  trainingclouderp_training_db.sql). Added: TrainingDumpConverter + `setup/import-training`; `setup/database` does
  the full rebuild; migrations/training (ai_* tables; demo people: ceo=1001, dept_head=1005, NEW hr.demo 45728;
  Demo@1234 for 35 logins; 25 v_* views + 6 ai_* helpers incl. a cycle-safe reporting chain; KB generated from ERP
  policy tables); RoleResolver; Employee/ErpUser/Department/Designation/LoginForm on ERP tables; access-map rewritten;
  the switcher/login use params demoPeople; security/* commands take usernames; bench presets updated; VerifyController
  rewritten (151 checks). Old migrations moved to migrations/demo. No live AI calls. Pages now say "copy of the training
  ERP database" (not "fabricated").
- 2026-09-28 (original Windows device) — Analysed trainingclouderp_training_db.sql and wrote the DB-replacement plan (see
  Status). Gitignored the dump. No code or DB changes yet.
- 2026-09-28 (original Windows device) — **Mistral switch ROLLED BACK at the owner's request** ("the mistral api is not
  working, go back to gemini"). Commit 6e6bd56 (Mistral primary, one OpenAI-compatible client, throttle, 429 backoff,
  per-user response cache, record/replay fixtures, verify/phase7) was undone by `git revert` (a9d7851); the code is still
  in history if wanted later. Provider is back to `gemini` / `gemini-3.6-flash`. verify/all 149/149 with keys parked.
  No live AI testing was done for the rollback. config/ai-local.php still holds the Mistral key (inert, gitignored).
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
