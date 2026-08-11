<?php
/**
 * front/config.form.php — Plugin Checklist, global settings page.
 * Reached from the plugin list's «Configure» gear (Hooks::CONFIG_PAGE).
 *
 * Modified 2026 — i18n, settings and native-CRUD rework.
 */

declare(strict_types=1);

// GLPI 11 : inclus par LegacyFileLoadController (Symfony). Ne pas re-inclure includes.php.

// Viewing the settings only needs READ — this MUST match the right that gates
// the «Configure» gear in setup.php (Hooks::CONFIG_PAGE), otherwise a
// read-only-config profile is shown a gear that opens on a rights error.
Session::checkRight('config', READ);

if (isset($_POST['update'])) {
    // Writing is the privileged half.
    Session::checkRight('config', UPDATE);
    // Single persistence path: buildValues() whitelists/casts and encodes
    // `itemtypes` back into the CSV form the config table expects.
    PluginChecklistConfig::saveFromPost($_POST);
    Session::addMessageAfterRedirect(__('Setup updated', 'checklist'), false, INFO);
    Html::back();
}

Html::header(
    PluginChecklistConfig::getTypeName(),
    $_SERVER['PHP_SELF'],
    'config',
    'PluginChecklistTemplate'
);

PluginChecklistConfig::showConfigForm();

Html::footer();
