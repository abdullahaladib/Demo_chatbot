<?php
/**
 * AI Chatbot - settings endpoint for the gear icon in the chat (POST JSON -> JSON).
 *
 *   {"action": "get"}                                  current provider, model, masked key, last change
 *   {"action": "models", "apiKey": "..."?}              chat models this key (or the saved key) can use
 *   {"action": "save", "apiKey": "..."?, "model": "..."} save; a blank apiKey keeps the current key
 *
 * Security, as ask.php: a signed-in ERP session of an enabled company (401), POST only (405), the
 * ERP's CSRF token (403). In addition the user must be in config.php 'settingsAdmins' (403),
 * checked here on the server - hiding the gear icon is not the protection. The full API key is
 * accepted but never returned.
 */

session_start();

require_once "../../../controllers/routing/default_values.php";
require_once SERVER_CORE . "ai_chatbot/bootstrap.php";

use AiChatbot\Identity;
use AiChatbot\Log;
use AiChatbot\Settings;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$reply = static function (int $status, array $body): never {
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
};

if (($_SESSION['mhafuz'] ?? '') !== 'Active' || empty($_SESSION['user']['id'])) {
    $reply(401, ['ok' => false, 'error' => 'Your ERP session has ended. Please sign in again.']);
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    $reply(405, ['ok' => false, 'error' => 'Method not allowed.']);
}
$token = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if ($token === '' || !hash_equals((string) ($_SESSION['csrf_token'] ?? ''), $token)) {
    $reply(403, ['ok' => false, 'error' => 'Security check failed. Please reload the page.']);
}

$session = $_SESSION;
session_write_close();

$body = json_decode((string) file_get_contents('php://input'), true);
$body = is_array($body) ? $body : [];

try {
    $identity = Identity::fromErpSession($session);
    if ($identity === null || !Settings::isAdmin($identity)) {
        $reply(403, ['ok' => false, 'error' => 'Only chatbot admins can change these settings.']);
    }

    switch ((string) ($body['action'] ?? '')) {
        case 'get':
            $reply(200, ['ok' => true, 'state' => Settings::state()]);
        case 'models':
            $key = trim((string) ($body['apiKey'] ?? ''));
            $list = Settings::listModels($key !== '' ? $key : null);
            $reply(200, $list + ['state' => Settings::state()]);
        case 'save':
            $result = Settings::save($identity, (string) ($body['apiKey'] ?? ''), (string) ($body['model'] ?? ''));
            $reply($result['ok'] ? 200 : 422, $result);
        default:
            $reply(400, ['ok' => false, 'error' => 'Unknown action.']);
    }
} catch (Throwable $e) {
    Log::error('settings.php: ' . $e->getMessage());
    $reply(500, ['ok' => false, 'error' => 'The settings could not be saved. Check the chatbot log on the server.']);
}
