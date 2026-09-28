<?php
/**
 * DEMO / LOCAL CLONE ONLY - never run against a real company database.
 *
 *   php install/local_demo_setup.php
 *
 * Makes the training tenant usable for the chatbot demo:
 *   1. every login linked to an IN-SERVICE employee gets the ERP password "Demo@1234"
 *      (the ERP stores md5(password) - that is the ERP's own scheme, unchanged here);
 *   2. the demo HR login (hr.demo, created by the chatbot's training migrations) gets the
 *      HRIS modules, so the ERP shows them on its home page.
 * Idempotent.
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; } // command line only

require_once __DIR__ . '/../bootstrap.php';

use AiChatbot\Db;

const DEMO_PASSWORD = 'Demo@1234';

Db::useAdminAccount();
$db = Db::app();

$n = $db->exec(
    "UPDATE user_activity_management u
     JOIN personnel_basic_info p ON p.pbi_id = u.PBI_ID AND p.pbi_job_status = 'In Service'
     SET u.password = " . $db->quote(md5(DEMO_PASSWORD)) . "
     WHERE u.PBI_ID > 0 AND u.status IN ('Active', 'In Service')"
);
echo "demo password set on $n in-service logins\n";

$hrUser = $db->query("SELECT user_id FROM user_activity_management WHERE username = 'hr.demo'")->fetchColumn();
if ($hrUser) {
    $hrisModules = $db->query(
        "SELECT id FROM user_module_manage WHERE status = 'Yes'
           AND (module_file = 'hrm_mod' OR module_type = 'HRIS' OR module_file = 'user_mod')"
    )->fetchAll(PDO::FETCH_COLUMN);
    $insert = $db->prepare(
        "INSERT INTO user_module_define (user_id, module_id, status)
         SELECT :u, :m, 'enable' FROM DUAL
         WHERE NOT EXISTS (SELECT 1 FROM user_module_define WHERE user_id = :u2 AND module_id = :m2)"
    );
    $added = 0;
    foreach ($hrisModules as $m) {
        $insert->execute([':u' => $hrUser, ':m' => $m, ':u2' => $hrUser, ':m2' => $m]);
        $added += $insert->rowCount();
    }
    echo "hr.demo: $added HRIS module grant(s) added (" . count($hrisModules) . " HRIS modules)\n";
}
