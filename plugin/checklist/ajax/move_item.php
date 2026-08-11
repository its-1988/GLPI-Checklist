<?php
/**
 * ajax/move_item.php — Basculer une tâche todo ↔ done
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

$item_id = (int) ($_POST['item_id'] ?? 0);

if ($item_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Missing item_id']);
    exit;
}

// Contrôle d'accès natif : la chaîne CommonDBChild remonte de la tâche à sa
// checklist puis à l'élément GLPI parent (droits + entité).
$item = new PluginChecklistItem();
if (!$item->getFromDB($item_id)) {
    echo json_encode(['success' => false, 'error' => 'Item not found']);
    exit;
}
if (!$item->can($item_id, UPDATE)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Access denied']);
    exit;
}

$result = PluginChecklistItem::toggleStatus($item_id);

/*
 * Server-authoritative progress (v2.1.0).
 *
 * The client used to recompute the percentage itself, by counting <li> nodes in
 * the two kanban columns. That was a second implementation of
 * PluginChecklistChecklist::computeProgress() and the two drifted: the server
 * floors on purpose (999/1000 must read 99 %, not a false 100 %) while the
 * browser rounded, so the card announced 100 % while the stored value, the ITIL
 * search column and the timeline entry all still said 99 %.
 *
 * The recompute has already happened by this point — refreshProgress() runs off
 * PluginChecklistItem's native post_updateItem hook — so this is a re-read of
 * the truth, not a third calculation. computeProgress() is deliberately used
 * rather than the persisted columns: it counts the rows, so the response is
 * correct even if a future write path were to reach the items table without
 * going through the model.
 *
 * ADDED to the response, never replacing a key: success / new_status / item_id
 * keep their v2.0.x meaning for any external consumer.
 */
if (($result['success'] ?? false) === true) {
    $result['progress'] = PluginChecklistChecklist::computeProgress(
        (int) $item->fields['plugin_checklist_checklists_id']
    );
}

echo json_encode($result);
