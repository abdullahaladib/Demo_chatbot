<?php
/**
 * AI Chatbot - AJAX endpoint (POST JSON {"question": "..."} -> JSON answer).
 *
 * Follows the ERP's AJAX pattern (session + default_values.php), but deliberately does NOT
 * include layout.top.php: that bootstraps the whole ERP page stack (HTML redirects, output
 * buffering, activity log) which a JSON endpoint must not emit. The plug-in opens its own
 * connections from the same session credentials the ERP uses.
 *
 * Security:
 *   - only a signed-in ERP session (mhafuz = Active) may call it       -> 401 otherwise
 *   - POST only, and the ERP's own CSRF token must be sent back        -> 405 / 403
 *   - WHO is asking comes from the session only; the request body carries just the question
 */

session_start();

require_once "../../../controllers/routing/default_values.php";
require_once SERVER_CORE . "ai_chatbot/bootstrap.php";

use AiChatbot\ChatService;
use AiChatbot\Identity;
use AiChatbot\Log;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$fail = static function (int $status, string $message): never {
    http_response_code($status);
    echo json_encode(['answer' => $message, 'path' => 'error', 'queries' => [], 'trace' => [], 'table' => null]);
    exit;
};

if (($_SESSION['mhafuz'] ?? '') !== 'Active' || empty($_SESSION['user']['id'])) {
    $fail(401, 'Your ERP session has ended. Please sign in again.');
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    $fail(405, 'Method not allowed.');
}
$token = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if ($token === '' || !hash_equals((string) ($_SESSION['csrf_token'] ?? ''), $token)) {
    $fail(403, 'Security check failed. Please reload the page.');
}

$session = $_SESSION;
// Release the session lock: an AI answer takes seconds and must not block the user's other ERP tabs.
session_write_close();

$body = json_decode((string) file_get_contents('php://input'), true);
$question = is_array($body) ? (string) ($body['question'] ?? '') : '';

try {
    $identity = Identity::fromErpSession($session);
    if ($identity === null) {
        $fail(401, 'Your ERP session has ended. Please sign in again.');
    }
    echo json_encode((new ChatService())->ask($identity, $question), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $e) {
    Log::error('ask.php: ' . $e);
    $fail(500, 'The assistant is unavailable right now. Please try again.');
}
