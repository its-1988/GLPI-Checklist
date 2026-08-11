<?php
/**
 * ajax/get_todo_items.php — Retourne les tâches todo d'un élément GLPI
 */

declare(strict_types=1);

header('Content-Type: application/json');

Session::checkLoginUser();

// Droit de profil : lecture des checklists (défense en profondeur)
if (!Session::haveRight('plugin_checklist_checklist', READ)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Access denied']);
    exit;
}

$itemtype = $_POST['itemtype'] ?? '';
$items_id = (int) ($_POST['items_id'] ?? 0);

if (empty($itemtype) || $items_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Missing parameters']);
    exit;
}

global $DB;

$cl_table = PluginChecklistChecklist::getTable();

// Contrôle d'accès natif : CommonDBChild::can() valide, pour chaque checklist
// portée par cet élément, l'accès en lecture à l'élément GLPI parent et à son
// entité. Une seule inaccessible ⇒ refus, pas de réponse partielle.
$checklist = new PluginChecklistChecklist();
foreach ($DB->request([
    'SELECT' => ['id'],
    'FROM'   => $cl_table,
    'WHERE'  => ['itemtype' => $itemtype, 'items_id' => $items_id],
]) as $cl) {
    if (!$checklist->can((int) $cl['id'], READ)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Access denied']);
        exit;
    }
}

// La jointure checklist⇄tâche vit dans le modèle (openItemsQuery), plus ici :
// le veto de résolution/clôture en a besoin lui aussi et deux copies auraient
// dérivé. La modale valide N'IMPORTE quelle tâche, bloquante ou non — d'où
// getOpenItemsFor() et non getBlockingOpenItems().
$items = [];
foreach (PluginChecklistChecklist::getOpenItemsFor($itemtype, $items_id) as $row) {
    $items[] = [
        'id'      => $row['item_id'],
        'name'    => $row['item_name'],
        'cl_name' => $row['checklist_name'],
    ];
}

/*
 * ADDED key (v2.1.0 Task 5): the modal body, rendered by the server.
 *
 * The client used to loop over `items` and build the list-group itself, with a
 * clvEsc() that did not escape apostrophes. `items` is kept exactly as it was —
 * it is the machine-readable half of this response and nothing about it moved.
 *
 * The EMPTY case is rendered here too, deliberately: "no open tasks" is not an
 * error and not an absence, it is a state with a message ("All tasks are
 * already done!"), and having the server answer it means the client needs no
 * branch of its own — it inserts whatever came back, whichever state it is.
 */
echo json_encode([
    'success' => true,
    'items'   => $items,
    'html'    => PluginChecklistChecklist::getValidateListHtml($items),
]);
