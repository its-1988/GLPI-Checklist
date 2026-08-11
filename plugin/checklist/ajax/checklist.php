<?php
/**
 * ajax/checklist.php — Créer / supprimer une checklist
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

$action      = $_POST['action']      ?? '';
$itemtype    = $_POST['itemtype']    ?? '';
$items_id    = (int) ($_POST['items_id']    ?? 0);
$name        = trim($_POST['name']   ?? '');
$tpl_id      = (int) ($_POST['templates_id'] ?? 0);
$cl_id       = (int) ($_POST['cl_id']       ?? 0);

if ($action === 'create') {
    if (empty($name) || empty($itemtype) || $items_id <= 0) {
        echo json_encode(['success' => false, 'error' => 'Missing parameters']);
        exit;
    }
    // Contrôle d'accès natif : sur un nouvel enregistrement, can(-1, CREATE)
    // résout le parent depuis l'input (itemtype/items_id) et vérifie le droit
    // de modification sur cet élément GLPI ainsi que son entité.
    $checklist   = new PluginChecklistChecklist();
    $parent_link = ['itemtype' => $itemtype, 'items_id' => $items_id];
    if (!$checklist->can(-1, CREATE, $parent_link)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Access denied']);
        exit;
    }
    $parent_entity = PluginChecklistChecklist::getParentEntity($itemtype, $items_id);
    if (!PluginChecklistTemplate::canUseTemplateForEntity($tpl_id, $parent_entity)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Template not allowed for this entity']);
        exit;
    }
    $id = PluginChecklistChecklist::createForItem($itemtype, $items_id, $name, $tpl_id);
    $total = 0;
    $html  = '';
    if ($id) {
        $total = (int) countElementsInTable(PluginChecklistItem::getTable(), ['plugin_checklist_checklists_id' => $id]);

        /*
         * ADDED key (v2.1.0 Task 5): the card, rendered by the server.
         *
         * The client used to build it from `id`, `name` and `total` with
         * clBuildCardHtml() — a hand-kept copy of renderCard() that, among
         * other things, always emitted EMPTY kanban columns. Creating from a
         * 5-task template therefore drew "0/5" and a column badge of 5 over a
         * column with nothing in it, and stayed wrong until the user reloaded.
         *
         * getCardHtmlById() re-reads the row it just wrote and renders the card
         * the same way showForItem() does, tasks included. `id`, `name` and
         * `total` are kept untouched for any consumer still reading them.
         */
        $html = PluginChecklistChecklist::getCardHtmlById(
            (int) $id,
            plugin_checklist_web_dir() . '/ajax'
        );
    }
    echo json_encode([
        'success' => $id !== false,
        'id'      => $id,
        'name'    => $name,
        'total'   => $total,
        'html'    => $html,
    ]);

} elseif ($action === 'delete') {
    if ($cl_id <= 0) {
        echo json_encode(['success' => false, 'error' => 'Missing cl_id']);
        exit;
    }
    // Contrôle d'accès natif : can() charge la checklist et valide l'accès à
    // son élément GLPI parent (et à l'entité de celui-ci).
    $checklist = new PluginChecklistChecklist();
    if (!$checklist->can($cl_id, UPDATE)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Access denied']);
        exit;
    }
    /*
     * ADDED key (v2.1.0 Task 5): the "no checklist yet" placeholder.
     *
     * Deleting the last checklist has to put that block back on screen. The
     * client used to assemble it with innerHTML — four lines of markup, but a
     * fifth copy of markup all the same, and one more thing to keep in step
     * with showForItem(). It ships pre-rendered instead; the client inserts it
     * only if this really was the last card.
     */
    echo json_encode([
        'success'    => PluginChecklistChecklist::deleteChecklist($cl_id),
        'empty_html' => PluginChecklistChecklist::getEmptyStateHtml(),
    ]);

} else {
    echo json_encode(['success' => false, 'error' => 'Unknown action']);
}
