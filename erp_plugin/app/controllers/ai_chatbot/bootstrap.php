<?php
/**
 * AI Chatbot plug-in - bootstrap. Self-contained: plain PHP 8, PDO + curl, no Composer,
 * no framework, nothing from the ERP required (the ERP only includes the widget partial and
 * hosts the AJAX endpoint). Safe to require more than once.
 */

if (!defined('AI_CHATBOT_DIR')) {
    define('AI_CHATBOT_DIR', __DIR__);
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
