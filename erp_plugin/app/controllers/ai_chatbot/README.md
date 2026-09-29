# ERP Assistant: AI chatbot plug-in

A floating chat widget on every signed-in ERP page. The user asks in plain English. The AI
writes its own SQL, but it can only read what that user's ERP login already lets them see.
It never writes anything.

The plug-in is plain PHP 8 with PDO and curl. It needs no Composer and no framework, and it
does not depend on the ERP's helper functions. The ERP only includes the widget partial and
serves the AJAX endpoint.

## Files

| Path (relative to the ERP root) | What it is |
|---|---|
| `app/controllers/ai_chatbot/` | The plug-in: `bootstrap.php` (autoloader), `config.php`, `config.local.php` (secrets, never commit), `src/` (engine), `install/`, `tests/`, `data/` |
| `app/controllers/routing/inc.ai_chatbot.php` | The widget partial (renders only for an active ERP session) |
| `app/views/ai_chatbot/api/ask.php` | AJAX endpoint: POST JSON `{"question": ...}` with the `X-CSRF-Token` header |
| `public/assets/ai_chatbot/chat-widget.css`, `chat-widget.js` | Widget UI, themed with the ERP's own CSS variables |

## Hooks into the ERP (two lines)

Add one line before `</body>` in each of these two files:

```php
// app/controllers/routing/inc.main_layout.php  (every module page), after the hrm_theme.js require
<? require_once SERVER_CORE."routing/inc.ai_chatbot.php"; /* AI Chatbot plug-in: floating chat widget */ ?>

// app/views/auth/masters/home.php  (the dashboard)
<?php require_once SERVER_CORE . "routing/inc.ai_chatbot.php"; /* AI Chatbot plug-in: floating chat widget */ ?>
```

## Install

**Upload and go (no command line needed).**
- `config.local.php` holds this server's Gemini key.
- `config.php` → `enabledCompanies` lists the company ids that get the chatbot (default:
  `training`). Other companies on the same server see no widget, and their databases are never touched.
- The first time someone from an enabled company opens an ERP page, the plug-in creates its own tables
  and views in that company's database (`src/Installer.php`). It uses the company's own ERP login.
- Starting content from `data/seed.php` goes only into empty tables.
- A marker file then skips this step on later visits.
- With no `aiAccounts` entry, the AI's SQL runs on the company's own login, locked read-only
  (`tenantAccountFallback`). The validator, the module allowlist and the column rewrite still apply.
- The catalogue (`data/catalog.json.php`) ships prebuilt for the training database.

**Stronger setup (optional, needs the command line).** Run these from `app/controllers/ai_chatbot/`.
They return 404 over the web.
1. `php install/build_catalog.php` rebuilds the catalogue for another database, or after schema changes
   (about 4 minutes).
2. `php install/apply_grants.php <mysql-root-password>` creates a dedicated SELECT-only AI account with
   column-level grants. Add it to `config.local.php` → `aiAccounts[<company db name>]`.
   - On cPanel without root, create a user in "MySQL Databases" with SELECT only instead, and add it the
     same way.
3. `php install/install_schema.php [company-id]` runs the same install by hand.
4. `php app/controllers/ai_chatbot/tests/run_tests.php` (from the ERP root, on the developer machine)
   makes no AI calls; the model is scripted.

**Later:**
- Chatbot tiers: `ai_role_assignment` (pbi_id → `hr` / `dept_head` / `ceo`). Line managers and
  employees are derived automatically.
- Policy text: `ai_knowledge_base`.

**Developer machine only:** `D:\Workspace\erp_local_dev\` sits outside the ERP folder, so it is never
uploaded. It holds:
- the local master-DB config and the local AI account;
- the log, install markers and grants record.

`install/local_demo_setup.php` (sets `Demo@1234` on logins) refuses to run anywhere else.

## Security model

1. **MySQL account.** AI SQL runs only as `erp_ai_plugin`:
   - it has SELECT on catalogued tables, restricted to their non-sensitive columns, plus the `v_*` views;
   - it has no access to logins, credentials, passwords, bank accounts, NID/DOB, and no writes;
   - each session runs read-only with a 5 s statement timeout.
2. **Module mirror.** A user may query only the tables of the ERP modules enabled for them
   (`user_module_define`), plus shared master tables.
   - Salary and payroll tables are confidential: `hr` and `ceo` tiers only.
   - HR personnel tables need an HR admin module.
3. **Bound scope.** The model writes `:me` / `:dept`, and the server binds them from the session.
   - Tables with `group_for` are wrapped automatically as
     `(SELECT <allowed cols> FROM t WHERE group_for = <session company>)`.
   - Self-service views are wrapped as `WHERE employee_id = :me`, so `OR 1=1` cannot widen them.
4. **Validator.** `src/SqlValidator.php` is a real tokenizer. It enforces:
   - SELECT only, one statement, no comments, variables or system schemas;
   - no `SELECT *`;
   - a per-user table allowlist;
   - LIMIT ≤ 200.

   Correctable mistakes go back to the model as hints. Security refusals end the turn with a
   server-written message.

Other rules:
- Identity always comes from the ERP session. The request body is only the question.
- The endpoint checks the ERP's own `$_SESSION['csrf_token']`.
- Every turn writes one `ai_chat_audit_log` row.
- `data/` is inside the web root. Every file the plug-in writes there starts with a PHP guard
  (a direct request gets a 404), and `.htaccess` denies the folder on Apache.

Known limitation: one AI account serves every tier, so MySQL enforces the column rules, but the
tier split (confidential tables) is enforced by the policy and the validator. The hardening
step would be one account per tier.
