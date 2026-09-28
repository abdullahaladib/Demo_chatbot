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

## Install (per tenant database)

Run these from `app/controllers/ai_chatbot/`. They are command-line only; over the web they
return 404.

1. Copy `config.local.php.example` to `config.local.php`. Fill in:
   - the AI key;
   - `adminDb`, an account with CREATE and CREATE VIEW on the tenant DB;
   - the `aiAccounts[<tenant db>]` username and a new strong password.
2. `php install/install_schema.php` creates the `ai_*` tables and the `ai_*`/`v_*` views.
3. `php install/build_catalog.php` works out which tables belong to which ERP module and hides
   sensitive columns. It takes about 4 minutes on the training DB. Re-run it after schema changes.
4. `php install/apply_grants.php <mysql-root-password>` creates the read-only AI account with
   column-level SELECT grants.
5. Optional: assign chatbot tiers in `ai_role_assignment` (pbi_id → `hr` / `dept_head` / `ceo`).
   Line managers and employees are derived automatically.
6. Optional: put policy text in `ai_knowledge_base`.
7. Run `php app/controllers/ai_chatbot/tests/run_tests.php` from the ERP root. It makes no AI
   calls; the model is scripted.

`install/local_demo_setup.php` is for the **local demo clone only**. It sets `Demo@1234` on
in-service logins.

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
