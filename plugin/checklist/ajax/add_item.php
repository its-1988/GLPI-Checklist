<?php
/**
 * ajax/add_item.php — Ajouter une tâche exceptionnelle à une checklist
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
$name   = trim($_POST['name']        ?? '');
$desc   = trim($_POST['description'] ?? '');

if ($cl_id <= 0 || empty($name)) {
    echo json_encode(['success' => false, 'error' => 'Missing parameters']);
    exit;
}

// Contrôle d'accès natif : CommonDBChild::can() valide la checklist, son
// élément GLPI parent (Ticket, Computer…) et l'entité de celui-ci.
$checklist = new PluginChecklistChecklist();
if (!$checklist->can($cl_id, UPDATE)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Access denied']);
    exit;
}

$id = PluginChecklistItem::addExceptional($cl_id, $name, $desc);

$response = [
    'success'     => $id !== false,
    'id'          => $id,
    'name'        => $name,
    'description' => $desc,
    'cl_id'       => $cl_id,
];

/*
 * Same server-authoritative progress as move_item.php / reorder_items.php: the
 * client applies it, it never derives it. Adding a task LOWERS the percentage
 * (and can take a completed checklist back to incomplete), which the browser
 * used to work out by re-counting DOM nodes — the very duplication v2.1.0
 * removes. addExceptional() goes through the model, so refreshProgress() has
 * already run off post_addItem by the time we get here.
 *
 * ADDED key: the five existing ones are untouched.
 */
if ($id !== false) {
    $response['progress'] = PluginChecklistChecklist::computeProgress($cl_id);

    /*
     * ADDED key (v2.1.0 Task 5): the <li>, rendered by the server.
     *
     * clBuildItemHtml() was a hand-kept copy of renderItem(). It escaped with
     * clEsc(), which handled &, < and > but NOT the apostrophe — so the same
     * task name reached the DOM as `l'élément` when it arrived by ajax and as
     * `l&#039;élément` after a reload. Same bytes on screen, different bytes in
     * the document, and only one of the two implementations could be fixed at
     * a time.
     *
     * The row is read BACK from the database rather than assembled from $name /
     * $desc: what the client inserts is then literally what a page reload would
     * draw, including anything the model did to the input on the way in.
     */
    $item = new PluginChecklistItem();
    if ($item->getFromDB((int) $id)) {
        $response['html'] = PluginChecklistChecklist::getItemHtml(
            $item->fields,
            plugin_checklist_web_dir() . '/ajax'
        );
    }
}

echo json_encode($response);
