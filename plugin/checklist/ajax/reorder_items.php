<?php
/**
 * ajax/reorder_items.php — Sauvegarder l'ordre des tâches après drag & drop
 */

declare(strict_types=1);

header('Content-Type: application/json');

// GLPI 11 : le bootstrap est déjà chargé par le routeur Symfony (LegacyFileLoadController).
// Ne pas re-inclure inc/includes.php.

Session::checkLoginUser();
// GLPI 11 vérifie le CSRF automatiquement via le header X-Glpi-Csrf-Token (CheckCsrfListener).

// Droit de profil : gestion des checklists (défense en profondeur)
if (!Session::haveRight('plugin_checklist_checklist', UPDATE)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Access denied']);
    exit;
}

$cl_id  = (int) ($_POST['cl_id']  ?? 0);
$column = $_POST['column'] ?? 'todo';
$ids    = $_POST['ids']    ?? [];

if ($cl_id <= 0 || empty($ids)) {
    echo json_encode(['success' => false, 'error' => 'Missing parameters']);
    exit;
}

// Contrôle d'accès natif : can() charge la checklist et valide l'accès à son
// élément GLPI parent (et à l'entité de celui-ci).
$checklist = new PluginChecklistChecklist();
if (!$checklist->can($cl_id, UPDATE)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Access denied']);
    exit;
}

// Restreint le réordonnancement aux tâches appartenant réellement à cette checklist
$ids = array_values(array_filter(array_map('intval', (array) $ids), static function ($id) use ($cl_id) {
    return countElementsInTable(PluginChecklistItem::getTable(), [
        'id'                             => $id,
        'plugin_checklist_checklists_id' => $cl_id,
    ]) > 0;
}));

if (empty($ids)) {
    echo json_encode(['success' => false, 'error' => 'No valid items']);
    exit;
}

$ok = PluginChecklistItem::updateRanks($ids, $column);

$response = ['success' => $ok];

/*
 * Reordering carries no progress of its own — ranks are pure ordering. The
 * progress travels anyway because a drag BETWEEN columns fires move_item.php
 * and then TWO reorder calls (source column and target column), and the client
 * has no way to know which response lands last. Every one of them therefore
 * carries the value read from the database at the moment it was served, so
 * whichever arrives last still leaves the card agreeing with the DB.
 *
 * Same rule as move_item.php: `progress` is ADDED, `success` is untouched.
 */
if ($ok) {
    $response['progress'] = PluginChecklistChecklist::computeProgress($cl_id);
}

echo json_encode($response);
