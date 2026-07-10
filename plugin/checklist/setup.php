<?php
/**
 * Plugin Checklist — setup.php
 * Déclaration du plugin auprès de GLPI 11
 */

declare(strict_types=1);

define('PLUGIN_CHECKLIST_VERSION', '1.0.2');
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

    if (!Plugin::isPluginActive('checklist')) {
        return;
    }

    $PLUGIN_HOOKS[\Glpi\Plugin\Hooks::ADD_JAVASCRIPT]['checklist'][] = 'js/Sortable.min.js';

    // Itemtypes sur lesquels on affiche l'onglet Checklist
    $itemtypes = [
        'Ticket', 'Computer', 'Phone', 'Monitor',
        'NetworkEquipment', 'Printer', 'Software',
        'Peripheral', 'Rack', 'Enclosure',
    ];

    Plugin::registerClass('PluginChecklistChecklist', [
        'addtabon' => $itemtypes,
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

    // Entrée de menu sous Configuration
    $PLUGIN_HOOKS[\Glpi\Plugin\Hooks::MENU_TOADD]['checklist'] = ['config' => 'PluginChecklistTemplate'];

    // Le JS métier reste injecté inline pour les onglets dynamiques GLPI 11.
    // SortableJS est embarqué localement pour éviter toute dépendance CDN.
}
