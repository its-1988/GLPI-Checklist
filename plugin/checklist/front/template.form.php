<?php
/**
 * front/template.form.php — Formulaire CRUD des templates + tâches
 */

declare(strict_types=1);

// GLPI 11 : inclus par LegacyFileLoadController (Symfony). Ne pas re-inclure.

// Coarse itemtype gate only — "you have some access to templates". The specific
// right (CREATE/UPDATE/PURGE) AND per-record entity access are enforced per
// branch below with the native check(), the way core front controllers do
// (e.g. front/cartridge.form.php: a broad checkRightsOr, then a check() per
// action). A single global UPDATE gate here would (a) let the add path run
// without CREATE and (b) never bind the mutation to the target row's entity.
Session::checkRightsOr('plugin_checklist_template', [READ, CREATE, UPDATE, PURGE]);

$tpl  = new PluginChecklistTemplate();
$item = new PluginChecklistTemplateItem();

// Normalise l'unité de délai sur une valeur autorisée (évite les données corrompues)
if (isset($_POST['notification_delay_unit'])
    && !in_array($_POST['notification_delay_unit'], ['hours', 'days', 'weeks'], true)) {
    $_POST['notification_delay_unit'] = 'hours';
}

// ─── Surcharges par template : liste blanche + validation ─────────────────────
//
// Les valeurs sont stockées EN CHAÎNE, sentinelle 'inherit' comprise : c'est
// elle qui fait retomber PluginChecklistConfig::resolve() sur le réglage
// global. Surtout ne rien caster en entier et ne pas éliminer 'inherit', sinon
// toute surcharge « hériter » deviendrait un « non » explicite.
//
// Seules les clés de OVERRIDABLE sont retenues, et chaque valeur est validée
// contre l'ensemble autorisé de sa colonne ; les clés POST inattendues et les
// valeurs hors ensemble n'atteignent jamais la requête.
$override_allowed = [
    'followup_on_item_done' => [PluginChecklistConfig::INHERIT, '1', '0'],
    'followup_privacy'      => [PluginChecklistConfig::INHERIT, 'glpi', 'public', 'private'],
    'notify_on_item_done'   => [PluginChecklistConfig::INHERIT, '1', '0'],
    'followup_on_overdue'   => [PluginChecklistConfig::INHERIT, '1', '0'],
    'overdue_privacy'       => [PluginChecklistConfig::INHERIT, 'glpi', 'public', 'private'],
    'notify_on_overdue'     => [PluginChecklistConfig::INHERIT, '1', '0'],
];

$override_input = [];
foreach (PluginChecklistConfig::OVERRIDABLE as $col) {
    if (!array_key_exists($col, $_POST)) {
        continue; // absent : la valeur en base (ou son DEFAULT 'inherit') reste
    }
    $allowed = $override_allowed[$col] ?? [PluginChecklistConfig::INHERIT];
    $value   = is_scalar($_POST[$col]) ? (string) $_POST[$col] : '';

    $override_input[$col] = in_array($value, $allowed, true)
        ? $value
        : PluginChecklistConfig::INHERIT;

    unset($_POST[$col]); // la valeur brute ne doit pas survivre
}
$_POST += $override_input;

// ─── Blocage résolution/clôture : liste blanche + normalisation ───────────────
//
// Contrairement aux surcharges ci-dessus, `is_blocking` est un booléen franc
// (pas de sentinelle 'inherit') : la colonne est un TINYINT(1) recopié tel quel
// sur les checklists issues du template. Même traitement que les surcharges :
// la valeur brute est retirée de $_POST, normalisée en 0/1, puis réinjectée —
// aucune chaîne arbitraire n'atteint add()/update().
//
// Clé absente = formulaire qui ne pilote pas ce champ (ajout/suppression de
// tâche) : on laisse alors la valeur en base intacte.
$flag_input = [];
if (array_key_exists('is_blocking', $_POST)) {
    $raw = is_scalar($_POST['is_blocking']) ? (string) $_POST['is_blocking'] : '0';
    $flag_input['is_blocking'] = $raw === '1' ? 1 : 0;

    unset($_POST['is_blocking']); // la valeur brute ne doit pas survivre
}
$_POST += $flag_input;

// ─── Actions POST ─────────────────────────────────────────────────────────────

if (isset($_POST['add'])) {
    // Création d'un nouveau template. check(-1, CREATE, $_POST) valide le droit
    // CREATE ET l'accès à l'entité cible (canCreateItem → checkEntity), ce qui
    // ferme la variante « créer directement dans l'entité 0 récursive ». On
    // valide donc bien l'entité choisie ici — surtout ne PAS la retirer du POST.
    $tpl->check(-1, CREATE, $_POST);
    $new_id = $tpl->add($_POST);
    Html::redirect(plugin_checklist_web_dir() . '/front/template.form.php?id=' . $new_id);
}

if (isset($_POST['update'])) {
    // Charge l'enregistrement et impose canUpdateItem sur SON entité : ferme la
    // modification cross-entité d'un template d'une autre entité.
    $tpl->check((int) $_POST['id'], UPDATE);

    // check($id, UPDATE) prouve l'accès à l'entité ACTUELLE, pas le droit de
    // DÉPLACER le template. Ce unset EST le garde-fou anti-évasion d'entité — il
    // n'est PAS qu'une précaution : l'entité d'un template n'est pas un champ
    // éditable de ce formulaire, et CommonDBTM::update() écrit `entities_id` tel
    // quel, sans revalider l'entité cible (forwardEntityInformations() propage
    // l'entité aux enfants, il n'AUTORISE rien). Sans ce retrait, un détenteur du
    // droit UPDATE dans une entité pourrait déplacer le template vers l'entité 0
    // récursive, où getAll() le proposerait alors à toutes les entités (et, avec
    // is_blocking, figerait la clôture des tickets de toute l'installation). Le
    // formulaire ne rend d'ailleurs ces champs qu'à la création. À ne PAS faire
    // côté add(), où check(-1, CREATE) valide légitimement l'entité choisie.
    unset($_POST['entities_id'], $_POST['is_recursive']);

    $tpl->update($_POST);
    Html::back();
}

if (isset($_POST['purge'])) {
    $tpl->check((int) $_POST['id'], PURGE);
    $tpl->delete($_POST, true);
    Html::redirect(plugin_checklist_web_dir() . '/front/template.php');
}

if (isset($_POST['add_item'])) {
    // La tâche n'a pas d'entité propre (glpi_plugin_checklist_templateitems n'a
    // pas de colonne entities_id) : la frontière d'autorisation est le template
    // parent. On exige donc UPDATE sur ce parent avant d'ajouter.
    $parent = new PluginChecklistTemplate();
    $parent->check((int) $_POST['template_id'], UPDATE);

    $item->add([
        'plugin_checklist_templates_id' => (int) $_POST['template_id'],
        'name'                          => $_POST['name'] ?? '',
        'description'                   => $_POST['description'] ?? '',
        'rank'                          => (int) ($_POST['rank'] ?? 0),
        'is_exceptional'                => isset($_POST['is_exceptional']) ? 1 : 0,
    ]);
    Html::back();
}

if (isset($_POST['delete_item'])) {
    // PluginChecklistTemplateItem est un CommonDBTM (pas un CommonDBChild) et sa
    // table n'a pas d'entities_id : $item->check($id, PURGE) ne vérifierait que
    // le droit GLOBAL, sans borne d'entité, donc n'empêcherait pas de purger la
    // tâche d'un template d'une autre entité. On résout donc le template parent
    // depuis la tâche chargée et on exige UPDATE dessus, comme pour add_item.
    $item_id = (int) $_POST['id'];
    if (!$item->getFromDB($item_id)) {
        Html::back();
    }
    $parent = new PluginChecklistTemplate();
    $parent->check((int) $item->fields['plugin_checklist_templates_id'], UPDATE);

    $item->delete(['id' => $item_id], true);
    Html::back();
}

// ─── Affichage ────────────────────────────────────────────────────────────────

$ID = (int) ($_GET['id'] ?? 0);

Html::header(
    $ID > 0 ? __('Edit checklist template', 'checklist') : __('New checklist template', 'checklist'),
    $_SERVER['PHP_SELF'],
    'config',
    'PluginChecklistTemplate'
);

$tpl->display(['id' => $ID]);

Html::footer();
