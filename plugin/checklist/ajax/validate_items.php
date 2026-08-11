<?php
/**
 * ajax/validate_items.php — Valider plusieurs tâches en une seule requête.
 *
 * Le modal « valider une tâche de checklist » envoie UNE requête pour toute la
 * sélection : avec `aggregate_bulk_validation`, 20 tâches cochées produisent un
 * seul suivi (et donc un seul e-mail), pas 20.
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
    echo json_encode(['success' => false, 'error' => __('Access denied', 'checklist')]);
    exit;
}

$raw_ids = $_POST['item_ids'] ?? [];
if (!is_array($raw_ids)) {
    $raw_ids = [$raw_ids];
}

$item_ids = array_values(array_unique(array_filter(array_map('intval', $raw_ids), static fn(int $id): bool => $id > 0)));

if ($item_ids === []) {
    echo json_encode(['success' => false, 'error' => __('Missing item_ids', 'checklist')]);
    exit;
}

// Contrôle d'accès natif : la chaîne CommonDBChild remonte de chaque tâche à sa
// checklist puis à l'élément GLPI parent (droits + entité).
// Un seul élément inaccessible ⇒ tout le lot est refusé (pas de validation partielle).
foreach ($item_ids as $item_id) {
    $item = new PluginChecklistItem();
    if (!$item->getFromDB($item_id)) {
        echo json_encode(['success' => false, 'error' => __('Item not found', 'checklist')]);
        exit;
    }
    if (!$item->can($item_id, UPDATE)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => __('Access denied', 'checklist')]);
        exit;
    }
}

echo json_encode(PluginChecklistItem::toggleMany($item_ids));
