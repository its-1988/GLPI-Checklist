<?php
/**
 * ajax/reorder_template_items.php — Sauvegarder l'ordre des tâches d'un template
 */

declare(strict_types=1);

header('Content-Type: application/json');

// GLPI 11 : bootstrap déjà chargé par le routeur Symfony. Ne pas re-inclure includes.php.

Session::checkRight('plugin_checklist_template', UPDATE);
// GLPI 11 vérifie le CSRF automatiquement via le header X-Glpi-Csrf-Token.

$template_id = (int) ($_POST['template_id'] ?? 0);
$ids         = $_POST['ids'] ?? [];

if ($template_id <= 0 || empty($ids)) {
    echo json_encode(['success' => false, 'error' => 'Missing parameters']);
    exit;
}

// Per-record gate: prove UPDATE on THIS template (loads the row + enforces
// per-entity access via canUpdateItem). The coarse checkRight above is only the
// itemtype-level gate — without this, any holder of the template UPDATE right
// could reorder tasks of a template belonging to another entity. check() throws
// AccessDeniedHttpException on denial (kernel → 403), the correct hard stop for
// a forged cross-entity request.
$template = new PluginChecklistTemplate();
$template->check($template_id, UPDATE);

$ok = PluginChecklistTemplateItem::updateRanks($template_id, array_map('intval', $ids));

echo json_encode(['success' => $ok]);
