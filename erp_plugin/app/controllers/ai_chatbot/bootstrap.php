<?php
/**
 * AI Chatbot plug-in - bootstrap. Self-contained: plain PHP 8, PDO + curl, no Composer,
 * no framework, nothing from the ERP required (the ERP only includes the widget partial and
 * hosts the AJAX endpoint). Safe to require more than once.
 */

if (!defined('AI_CHATBOT_DIR')) {
    define('AI_CHATBOT_DIR', __DIR__);
    // Developer machine only: settings kept OUTSIDE the ERP folder (D:\Workspace\erp_local_dev\), so an
    // upload of the ERP never carries them. Absent on a server.
    define('AI_CHATBOT_DEV_DIR', dirname(__DIR__, 4) . '/erp_local_dev');
    // Where the plug-in writes at run time (log, install markers, grants record): data/ on a server,
    // the dev folder on the developer machine - so a zip of the ERP folder carries none of it.
    define('AI_CHATBOT_RUNTIME_DIR', @is_dir(AI_CHATBOT_DEV_DIR) ? AI_CHATBOT_DEV_DIR . '/ai_chatbot_runtime' : __DIR__ . '/data');
    if (!is_dir(AI_CHATBOT_RUNTIME_DIR)) {
        @mkdir(AI_CHATBOT_RUNTIME_DIR, 0775, true);
    }
    // First line of every file the plug-in writes under data/: that folder sits inside the web
    // root, so a direct request runs this and gets a 404 instead of the file (the ERP is not
    // always served by Apache, where data/../.htaccess would already deny it).
    define('AI_CHATBOT_FILE_GUARD', "<?php http_response_code(404); exit; ?>
");

    spl_autoload_register(static function (string $class): void {
        $prefix = 'AiChatbot\\';
        if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
            return;
        }
        $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
        $file = AI_CHATBOT_DIR . '/src/' . $relative . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    });
}
