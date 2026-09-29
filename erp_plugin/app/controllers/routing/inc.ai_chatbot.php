<?php
/**
 * AI Chatbot plug-in - floating chat widget partial.
 *
 * Included just before </body> by controllers/routing/inc.main_layout.php (every module
 * page) and views/auth/masters/home.php (the dashboard). Renders only for a signed-in ERP
 * session, and NEVER breaks the host page: any failure renders nothing (logged instead).
 * Paths use the ERP's own constants (SERVER_ROOT / SERVER_VIEW / SERVER_CORE).
 */

if (($_SESSION['mhafuz'] ?? '') === 'Active' && !empty($_SESSION['user']['id']) && !defined('AI_CHATBOT_WIDGET_RENDERED')) {
    define('AI_CHATBOT_WIDGET_RENDERED', true);
    try {
        require_once SERVER_CORE . 'ai_chatbot/bootstrap.php';

        $aicIdentity = \AiChatbot\Identity::fromErpSession($_SESSION);
        if ($aicIdentity !== null) {
            $aicPolicy = new \AiChatbot\AccessPolicy($aicIdentity);
            $aicModules = $aicPolicy->moduleNames();

            // Suggestion chips: self-service first, then one per module the user has.
            $aicAll = require SERVER_CORE . 'ai_chatbot/data/suggestions.php';
            $aicCatalogModules = \AiChatbot\Catalog::modules();
            $aicPools = [];
            if ($aicIdentity->pbiId !== null) {
                $aicPools[] = $aicAll['_self'];
            }
            foreach (array_keys($aicModules) as $aicId) {
                $aicFile = $aicCatalogModules[(string) $aicId]['file'] ?? '';
                if (isset($aicAll[$aicFile])) {
                    $aicPools[] = $aicAll[$aicFile];
                }
            }
            $aicChips = [];
            for ($aicRound = 0; count($aicChips) < 5 && $aicRound < 3; $aicRound++) {
                foreach ($aicPools as $aicPool) {
                    if (isset($aicPool[$aicRound]) && count($aicChips) < 5) {
                        $aicChips[] = $aicPool[$aicRound];
                    }
                }
            }

            $aicCanManage = \AiChatbot\Settings::isAdmin($aicIdentity);
            $aicConfig = [
                'askUrl' => SERVER_VIEW . 'ai_chatbot/api/ask.php',
                'settingsUrl' => $aicCanManage ? SERVER_VIEW . 'ai_chatbot/api/settings.php' : null,
                'csrf' => (string) ($_SESSION['csrf_token'] ?? ''),
                'userKey' => ($_SESSION['proj_id'] ?? '') . '.' . (int) $_SESSION['user']['id'],
                'firstName' => explode(' ', $aicIdentity->name)[0],
                'modulesLabel' => $aicModules ? (count($aicModules) . ' module' . (count($aicModules) === 1 ? '' : 's')) : 'your own records',
            ];
            $aicH = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
            ?>
<link rel="stylesheet" href="<?= $aicH(SERVER_ROOT . 'public/assets/ai_chatbot/chat-widget.css') ?>?v=3">
<div id="aic-widget" data-config="<?= $aicH(json_encode($aicConfig)) ?>">
    <section id="aic-panel" class="aic-panel" role="dialog" aria-label="ERP Assistant" hidden>
        <header class="aic-header">
            <div class="aic-avatar"><i class="fa-solid fa-robot"></i></div>
            <div class="aic-title">
                <b>ERP Assistant</b>
                <div class="aic-subtitle"><?= $aicH($aicIdentity->name) ?> <span class="aic-badge"><?= $aicH($aicIdentity->tierLabel()) ?></span></div>
            </div>
            <div class="aic-actions">
                <?php if ($aicCanManage) { ?><button type="button" class="aic-icon-btn" id="aic-gear" title="AI key and model" aria-label="AI key and model settings"><i class="fa-solid fa-gear"></i></button><?php } ?>
                <label class="aic-sql-toggle" title="Show the generated SQL"><input type="checkbox" id="aic-toggle-sql"> SQL</label>
                <button type="button" class="aic-icon-btn aic-expand-btn" id="aic-expand" title="Expand" aria-label="Expand"><i class="fa-solid fa-up-right-and-down-left-from-center"></i></button>
                <button type="button" class="aic-icon-btn" id="aic-clear" title="Clear chat" aria-label="Clear chat"><i class="fa-regular fa-trash-can"></i></button>
                <button type="button" class="aic-icon-btn" id="aic-close" title="Minimise" aria-label="Minimise"><i class="fa-solid fa-minus"></i></button>
            </div>
        </header>
        <div class="aic-messages" id="aic-messages" aria-live="polite"></div>
        <?php if ($aicCanManage) { ?>
        <div class="aic-settings" id="aic-settings" hidden>
            <div class="aic-settings-head">
                <b>AI key and model</b>
                <span class="aic-muted">Changes apply to the next question, for everyone.</span>
            </div>
            <label class="aic-field"><span>Provider</span><input type="text" id="aic-set-provider" readonly></label>
            <label class="aic-field"><span>API key</span>
                <input type="password" id="aic-set-key" autocomplete="off" spellcheck="false" placeholder="Paste a new key (leave empty to keep the current one)">
                <small class="aic-muted" id="aic-set-keyinfo"></small>
            </label>
            <button type="button" class="aic-btn aic-btn-ghost" id="aic-set-check">Check key</button>
            <label class="aic-field"><span>Model</span><select id="aic-set-model"></select>
                <small class="aic-muted" id="aic-set-modelinfo"></small>
            </label>
            <div class="aic-set-status" id="aic-set-status" role="status"></div>
            <div class="aic-settings-actions">
                <button type="button" class="aic-btn aic-btn-ghost" id="aic-set-cancel">Back to chat</button>
                <button type="button" class="aic-btn" id="aic-set-save">Save</button>
            </div>
            <small class="aic-muted" id="aic-set-meta"></small>
        </div>
        <?php } ?>
        <footer class="aic-footer">
            <?php if ($aicChips) { ?>
            <div class="aic-chips">
                <?php foreach ($aicChips as $aicChip) { ?><button type="button" class="aic-chip"><?= $aicH($aicChip) ?></button><?php } ?>
            </div>
            <?php } ?>
            <form id="aic-form" class="aic-form" autocomplete="off">
                <input type="text" id="aic-question" class="aic-input" maxlength="1000" placeholder="Ask about vouchers, ledgers, sales, stock, leave..." aria-label="Question">
                <button class="aic-send" id="aic-send" aria-label="Send"><i class="fa-solid fa-paper-plane"></i></button>
            </form>
        </footer>
    </section>
    <button type="button" id="aic-fab" class="aic-fab" aria-controls="aic-panel" aria-expanded="false" aria-label="Open ERP Assistant">
        <i class="fa-solid fa-comments aic-ico-chat"></i>
        <i class="fa-solid fa-xmark aic-ico-close"></i>
        <span class="aic-unread" id="aic-unread" hidden></span>
    </button>
</div>
<script src="<?= $aicH(SERVER_ROOT . 'public/assets/ai_chatbot/chat-widget.js') ?>?v=3"></script>
            <?php
        }
    } catch (Throwable $aicError) {
        if (class_exists(\AiChatbot\Log::class)) {
            \AiChatbot\Log::error('widget: ' . $aicError->getMessage());
        }
    }
}
