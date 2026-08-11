<?php
/**
 * Plugin Checklist — setup.php
 * Déclaration du plugin auprès de GLPI 11
 */

declare(strict_types=1);

define('PLUGIN_CHECKLIST_VERSION', '2.1.0');
define('PLUGIN_CHECKLIST_MIN_GLPI', '11.0.0');
define('PLUGIN_CHECKLIST_MAX_GLPI', '11.0.99');
define('PLUGIN_CHECKLIST_DIR', __DIR__);

function plugin_version_checklist(): array
{
    return [
        'name'         => 'Checklist',
        'version'      => PLUGIN_CHECKLIST_VERSION,
        'author'       => 'Aprolex',
        'license'      => 'GPL v3+',
        'homepage'     => '',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_CHECKLIST_MIN_GLPI,
                'max' => PLUGIN_CHECKLIST_MAX_GLPI,
            ],
            'php'  => ['min' => '8.2'],
        ],
    ];
}

function plugin_checklist_check(): bool
{
    return true;
}

function plugin_init_checklist(): void
{
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS[\Glpi\Plugin\Hooks::CSRF_COMPLIANT]['checklist']  = true;
    $PLUGIN_HOOKS['use_language']['checklist']    = true;
    $PLUGIN_HOOKS[\Glpi\Plugin\Hooks::TIMELINE_ACTIONS]['checklist'] = 'plugin_checklist_timeline_actions';

    // Progress of each checklist, shown as a native entry in the ITIL timeline.
    //
    // Registered FLAT (a plain callable), not keyed by itemtype: core fires this
    // one with an ARRAY as $param (src/CommonITILObject.php:8031), and
    // Plugin::doHook only takes the itemtype-keyed branch when is_object($param)
    // holds (src/Plugin.php:1793). An ['Ticket' => …] shape would simply never
    // be reached.
    //
    // Deliberately NOT Hooks::SHOW_IN_TIMELINE: that one is @deprecated 11.0.0
    // and Plugin::doHook logs a deprecation notice for it on EVERY timeline
    // render (src/Plugin.php:1802-1804). TIMELINE_ITEMS is its replacement and
    // is invoked on the very next line of core.
    $PLUGIN_HOOKS[\Glpi\Plugin\Hooks::TIMELINE_ITEMS]['checklist'] = 'plugin_checklist_timeline_items';

    if (!Plugin::isPluginActive('checklist')) {
        return;
    }

    // Switches on the native massive-action pipeline for this plugin. Without
    // it core never calls plugin_checklist_MassiveActions(): the collector at
    // src/MassiveAction.php:731-742 iterates the keys of THIS hook and skips
    // every plugin that is absent from it. The flag is only a gate — the action
    // list itself lives in hook.php.
    $PLUGIN_HOOKS[\Glpi\Plugin\Hooks::USE_MASSIVE_ACTION]['checklist'] = true;

    // SortableJS MUST stay first — checklist.js depends on it.
    $PLUGIN_HOOKS[\Glpi\Plugin\Hooks::ADD_JAVASCRIPT]['checklist'][] = 'js/Sortable.min.js';
    $PLUGIN_HOOKS[\Glpi\Plugin\Hooks::ADD_JAVASCRIPT]['checklist'][] = 'js/checklist.js';
    $PLUGIN_HOOKS[\Glpi\Plugin\Hooks::ADD_JAVASCRIPT]['checklist'][] = 'js/checklist-validate.js';
    $PLUGIN_HOOKS[\Glpi\Plugin\Hooks::ADD_CSS]['checklist'][] = 'css/checklist.css';

    // Itemtypes sur lesquels on affiche l'onglet Checklist.
    // Lus dans les réglages globaux (page « Configurer » du plugin). Cette
    // lecture touche la base, elle DOIT donc rester après le garde
    // isPluginActive() ci-dessus : sur une installation neuve la table de
    // config n'existe pas encore et la liste des plugins casserait.
    $itemtypes = PluginChecklistConfig::get()['itemtypes'];
    if (!is_array($itemtypes) || $itemtypes === []) {
        $itemtypes = PluginChecklistConfig::defaults()['itemtypes'];
    }

    // `notificationtemplates_types` puts PluginChecklistChecklist into
    // $CFG_GLPI['notificationtemplates_types'] (src/Plugin.php:1702-1714), which
    // is the itemtype list Setup > Notifications offers when creating a
    // notification or a template (src/Notification.php:310). Without it the
    // seeded `checklist_completed` notification exists in the tables but the
    // administrator can neither see nor edit it.
    Plugin::registerClass('PluginChecklistChecklist', [
        'addtabon'                    => $itemtypes,
        'notificationtemplates_types' => true,
    ]);

    // Onglet « Checklists » sur les profils (matrice des droits)
    Plugin::registerClass('PluginChecklistProfile', [
        'addtabon' => 'Profile',
    ]);

    // ── Phase 7 : moteur de règles ──────────────────────────────────────────
    // Enregistre la collection de règles → apparaît dans Administration > Règles
    Plugin::registerClass('PluginChecklistRuleChecklistCollection', [
        'rulecollections_types' => true,
    ]);

    // Déclenche l'évaluation des règles à la création / modification d'un élément
    foreach ($itemtypes as $type) {
        $PLUGIN_HOOKS[\Glpi\Plugin\Hooks::ITEM_ADD]['checklist'][$type]    = 'plugin_checklist_item_add';
        $PLUGIN_HOOKS[\Glpi\Plugin\Hooks::ITEM_UPDATE]['checklist'][$type] = 'plugin_checklist_item_update';
    }

    // ── Veto « résolution / clôture » quand une checklist bloquante reste ouverte ─
    //
    // DEUX points d'accroche sont nécessaires : clôturer par une solution ne passe
    // PAS par Ticket::update(), mais par ITILSolution::add(). Le premier hook
    // couvre ce chemin, le second couvre la liste déroulante de statut, le suivi
    // « _close », la validation et le CRON de clôture automatique.
    //
    // L'enregistrement est GLOBAL et volontairement indépendant de la liste
    // `itemtypes` : la clôture doit être arbitrée pour tout objet ITIL, même si
    // l'onglet Checklist n'est pas affiché ailleurs. Le garde-fou n'est donc pas
    // ici mais dans les fonctions elles-mêmes : elles lisent d'abord le réglage
    // global `blocking_enabled`, à 0 par défaut, avant toute requête.
    $PLUGIN_HOOKS[\Glpi\Plugin\Hooks::PRE_ITEM_ADD]['checklist']['ITILSolution']
        = 'plugin_checklist_pre_solution_add';

    foreach (PluginChecklistFollowup::SUPPORTED_ITEMTYPES as $itil_type) {
        $PLUGIN_HOOKS[\Glpi\Plugin\Hooks::PRE_ITEM_UPDATE]['checklist'][$itil_type]
            = 'plugin_checklist_pre_itil_update';
    }

    // Entrée de menu sous Configuration
    $PLUGIN_HOOKS[\Glpi\Plugin\Hooks::MENU_TOADD]['checklist'] = ['config' => 'PluginChecklistTemplate'];

    // Roue « Configurer » dans la liste des plugins → réglages globaux
    if (Session::haveRight('config', READ)) {
        $PLUGIN_HOOKS[\Glpi\Plugin\Hooks::CONFIG_PAGE]['checklist'] = 'front/config.form.php';
    }
}

/**
 * Web path to the plugin (assets under /public are served without the prefix).
 * Replaces Plugin::getWebDir('checklist'), which is @deprecated in GLPI 11 and
 * logs a deprecation notice on EVERY call (once per ticket render here).
 * GLPI 11 serves all plugins from the /plugins/ path.
 */
function plugin_checklist_web_dir(): string
{
    global $CFG_GLPI;
    return $CFG_GLPI['root_doc'] . '/plugins/checklist';
}
