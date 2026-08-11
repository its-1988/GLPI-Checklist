<?php
/**
 * Plugin Checklist — hook.php
 * Fonctions d'installation, désinstallation et hooks GLPI
 */

declare(strict_types=1);

// ─── Install ──────────────────────────────────────────────────────────────────

function plugin_checklist_install(): bool
{
    global $DB;

    $tables = [

        // Les six colonnes de surcharge (PluginChecklistConfig::OVERRIDABLE)
        // sont des VARCHAR portant la sentinelle 'inherit', JAMAIS des tinyint :
        // resolve() considère toute valeur non-'inherit' comme une surcharge
        // explicite, donc un DEFAULT 0 désactiverait silencieusement les suivis
        // pour tous les templates existants.
        'glpi_plugin_checklist_templates' => "
            CREATE TABLE IF NOT EXISTS `glpi_plugin_checklist_templates` (
                `id`                         INT UNSIGNED  NOT NULL AUTO_INCREMENT,
                `name`                       VARCHAR(255)  NOT NULL DEFAULT '',
                `comment`                    TEXT,
                `is_active`                  TINYINT(1)    NOT NULL DEFAULT 1,
                `notification_delay_hours`   INT           NOT NULL DEFAULT 0,
                `notification_delay_unit`    VARCHAR(10)   NOT NULL DEFAULT 'hours',
                `date_creation`              TIMESTAMP     NULL DEFAULT NULL,
                `date_mod`                   TIMESTAMP     NULL DEFAULT NULL,
                `users_id`                   INT UNSIGNED  NOT NULL DEFAULT 0,
                `entities_id`                INT UNSIGNED  NOT NULL DEFAULT 0,
                `is_recursive`               TINYINT(1)    NOT NULL DEFAULT 0,
                `followup_on_item_done`      VARCHAR(10)   NOT NULL DEFAULT 'inherit',
                `followup_privacy`           VARCHAR(10)   NOT NULL DEFAULT 'inherit',
                `notify_on_item_done`        VARCHAR(10)   NOT NULL DEFAULT 'inherit',
                `followup_on_overdue`        VARCHAR(10)   NOT NULL DEFAULT 'inherit',
                `overdue_privacy`            VARCHAR(10)   NOT NULL DEFAULT 'inherit',
                `notify_on_overdue`          VARCHAR(10)   NOT NULL DEFAULT 'inherit',
                `is_blocking`                TINYINT(1)    NOT NULL DEFAULT 0,
                PRIMARY KEY (`id`),
                KEY `name`        (`name`),
                KEY `entities_id` (`entities_id`),
                KEY `is_active`   (`is_active`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC",

        'glpi_plugin_checklist_templateitems' => "
            CREATE TABLE IF NOT EXISTS `glpi_plugin_checklist_templateitems` (
                `id`                              INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `plugin_checklist_templates_id`   INT UNSIGNED NOT NULL DEFAULT 0,
                `name`                            VARCHAR(500) NOT NULL DEFAULT '',
                `description`                     TEXT,
                `rank`                            INT          NOT NULL DEFAULT 0,
                `is_exceptional`                  TINYINT(1)   NOT NULL DEFAULT 0,
                `date_creation`                   TIMESTAMP    NULL DEFAULT NULL,
                `date_mod`                        TIMESTAMP    NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `plugin_checklist_templates_id` (`plugin_checklist_templates_id`),
                KEY `rank` (`rank`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC",

        'glpi_plugin_checklist_checklists' => "
            CREATE TABLE IF NOT EXISTS `glpi_plugin_checklist_checklists` (
                `id`                              INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `name`                            VARCHAR(255) NOT NULL DEFAULT '',
                `itemtype`                        VARCHAR(100) NOT NULL DEFAULT '',
                `items_id`                        INT UNSIGNED NOT NULL DEFAULT 0,
                `plugin_checklist_templates_id`   INT UNSIGNED NOT NULL DEFAULT 0,
                `status`                          VARCHAR(50)  NOT NULL DEFAULT 'open',
                `date_creation`                   TIMESTAMP    NULL DEFAULT NULL,
                `date_mod`                        TIMESTAMP    NULL DEFAULT NULL,
                `users_id`                        INT UNSIGNED NOT NULL DEFAULT 0,
                `entities_id`                     INT UNSIGNED NOT NULL DEFAULT 0,
                `items_total`                     INT UNSIGNED NOT NULL DEFAULT 0,
                `items_done`                      INT UNSIGNED NOT NULL DEFAULT 0,
                `percent_done`                    INT UNSIGNED NOT NULL DEFAULT 0,
                `is_blocking`                     TINYINT(1)   NOT NULL DEFAULT 0,
                `date_completed`                  TIMESTAMP    NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `itemtype`    (`itemtype`),
                KEY `items_id`    (`items_id`),
                KEY `entities_id` (`entities_id`),
                KEY `status`      (`status`),
                KEY `is_blocking` (`is_blocking`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC",

        'glpi_plugin_checklist_items' => "
            CREATE TABLE IF NOT EXISTS `glpi_plugin_checklist_items` (
                `id`                              INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `plugin_checklist_checklists_id`  INT UNSIGNED NOT NULL DEFAULT 0,
                `name`                            VARCHAR(500) NOT NULL DEFAULT '',
                `description`                     TEXT,
                `status`                          ENUM('todo','done') NOT NULL DEFAULT 'todo',
                `rank_todo`                       INT          NOT NULL DEFAULT 0,
                `rank_done`                       INT          NOT NULL DEFAULT 0,
                `is_exceptional`                  TINYINT(1)   NOT NULL DEFAULT 0,
                `date_creation`                   TIMESTAMP    NULL DEFAULT NULL,
                `date_mod`                        TIMESTAMP    NULL DEFAULT NULL,
                `date_todo`                       TIMESTAMP    NULL DEFAULT NULL,
                `date_notified`                   TIMESTAMP    NULL DEFAULT NULL,
                `users_id_creator`                INT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (`id`),
                KEY `plugin_checklist_checklists_id` (`plugin_checklist_checklists_id`),
                KEY `status` (`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC",
    ];

    foreach ($tables as $table => $sql) {
        if (!$DB->tableExists($table)) {
            // doQuery() : la variante « OrDie » est @deprecated depuis GLPI 11.0.0.
            // Elle ne coupe donc plus l'exécution en cas d'échec : la vérification
            // finale ci-dessous est ce qui empêche une installation ratée de se
            // déclarer réussie.
            $DB->doQuery($sql);
        }
    }

    // Un CREATE TABLE échoué ne lève plus rien : on revérifie l'existence réelle
    // de chaque table avant d'annoncer le succès à GLPI.
    $tables_ok = true;
    foreach (array_keys($tables) as $table) {
        if (!$DB->tableExists($table)) {
            $tables_ok = false;
            trigger_error(
                "Plugin checklist: table `{$table}` is missing after install",
                E_USER_WARNING
            );
        }
    }
    if (!$tables_ok) {
        return false;
    }

    // ── Migration : l'historique maison disparaît ────────────────────────────
    // Checklists et tâches sont des CommonDBChild : GLPI journalise nativement
    // dans l'onglet « Historique » de l'élément parent. La table maison est donc
    // morte — on la supprime chez les installs existantes.
    if ($DB->tableExists('glpi_plugin_checklist_logs')) {
        $DB->doQuery("DROP TABLE IF EXISTS `glpi_plugin_checklist_logs`");
    }

    // ── Migration : ajout de la colonne unité de délai (installs existantes) ──
    if (
        $DB->tableExists('glpi_plugin_checklist_templates')
        && !$DB->fieldExists('glpi_plugin_checklist_templates', 'notification_delay_unit')
    ) {
        $DB->doQuery(
            "ALTER TABLE `glpi_plugin_checklist_templates`
             ADD COLUMN `notification_delay_unit` VARCHAR(10) NOT NULL DEFAULT 'hours'
             AFTER `notification_delay_hours`"
        );
    }

    // ── Migration : surcharges par template (installs existantes) ────────────
    // Idempotent : chaque colonne n'est ajoutée que si elle manque.
    // La valeur par défaut DOIT rester la sentinelle 'inherit' — voir le
    // commentaire du CREATE TABLE ci-dessus.
    if ($DB->tableExists('glpi_plugin_checklist_templates')) {
        foreach (PluginChecklistConfig::OVERRIDABLE as $col) {
            if (!$DB->fieldExists('glpi_plugin_checklist_templates', $col)) {
                $DB->doQuery(
                    "ALTER TABLE `glpi_plugin_checklist_templates`
                     ADD COLUMN `{$col}` VARCHAR(10) NOT NULL DEFAULT 'inherit'"
                );
            }
        }
    }

    // ── Migrations versionnées ───────────────────────────────────────────────
    // Les sondes d'existence ci-dessus restent le filet de sécurité des installs
    // antérieures à l'enregistrement de version ; à partir d'ici, c'est la
    // version stockée qui pilote. On arrive ici seulement si la vérification des
    // tables a réussi (sinon on a déjà `return false`), donc aucune migration ne
    // tourne sur un schéma incomplet et aucun échec n'est masqué.
    $stored = Config::getConfigurationValues('plugin:checklist');
    $from   = (string) ($stored['schema_version'] ?? '1.0.0');
    plugin_checklist_migrate($from);
    // On enregistre la version depuis defaults() plutôt qu'en dur : un littéral
    // oublié ici laisserait la version stockée en arrière et ferait rejouer la
    // dernière migration à chaque installation. Une seule source de vérité.
    Config::setConfigurationValues(
        'plugin:checklist',
        ['schema_version' => PluginChecklistConfig::defaults()['schema_version']]
    );

    // ── Phase 8 : enregistrement de la tâche CRON ────────────────────────────
    CronTask::register(
        'PluginChecklistCronTask',
        'checklistOverdue',
        HOUR_TIMESTAMP, // fréquence : toutes les heures
        [
            'state'     => CronTask::STATE_WAITING,
            'mode'      => CronTask::MODE_INTERNAL,
            'comment'   => __('Notifications for overdue checklist tasks', 'checklist'),
        ]
    );

    // ── Notification native « checklist terminée » ───────────────────────────
    plugin_checklist_install_notification();

    // ── Droits de profil (matrice d'accès) ───────────────────────────────────
    PluginChecklistProfile::install();

    // ── Assert : le bloc d'ids réservé est-il toujours libre ? ───────────────
    plugin_checklist_assert_search_option_ids();

    return true;
}

/**
 * Warns if the reserved search-option id block is already taken on Ticket.
 *
 * The id namespace of a core itemtype is SHARED: an option injected by a plugin
 * lands in the same table as the core ones. When two options claim the same id,
 * core only raises a trigger_error (src/Plugin.php:2192) — invisible with
 * `display_errors=off` — and the losing column simply never appears. Silent,
 * and near-impossible to diagnose from a bug report.
 *
 * The old "plugins take ids above 1000" convention no longer protects anything:
 * core already occupies 1400-1451, and src/State.php allocates `4000 + N` for
 * every custom asset definition, without an upper bound. Hence the reserved
 * 9000-9099 block, plus this explicit probe.
 *
 * Purely diagnostic: any failure here is swallowed, an install must never die
 * over a warning it only meant to print.
 */
function plugin_checklist_assert_search_option_ids(): void
{
    try {
        if (!class_exists('\Glpi\Search\SearchOption')) {
            return;
        }

        // Signature verified in GLPI 11.0.8:
        // \Glpi\Search\SearchOption::getOptionsForItemtype($itemtype, $withplugins = true)
        // (src/Glpi/Search/SearchOption.php:154). Returns options keyed BY id,
        // group headers (plain strings) included — hence the is_array() filter.
        $taken = [];
        foreach (\Glpi\Search\SearchOption::getOptionsForItemtype('Ticket') as $id => $opt) {
            if (!is_array($opt)) {
                continue; // group header ('common', 'plugins', …)
            }
            // Our own options are not a collision with ourselves: on an upgrade
            // of an already-active plugin they are part of the returned set.
            if (($opt['table'] ?? '') === 'glpi_plugin_checklist_checklists') {
                continue;
            }
            $taken[] = (string) $id;
        }

        // Read back from the provider itself — a second hard-coded list here
        // would rot the moment a column is added.
        $ours  = array_map('strval', array_column(plugin_checklist_itil_search_options(), 'id'));
        $clash = array_intersect($ours, $taken);

        if ($clash !== []) {
            trigger_error(
                'Checklist: search option id collision on Ticket: ' . implode(', ', $clash)
                . ' — those checklist columns will not appear.'
                . ' Pick another reserved block in hook.php.',
                E_USER_WARNING
            );
        }
    } catch (\Throwable $e) {
        // Diagnostics never abort an install.
    }
}

// ─── Schema migrations ────────────────────────────────────────────────────────

/**
 * Ordered, idempotent schema migrations. Each step re-checks the database
 * before acting, so re-running an upgrade is always safe.
 */
function plugin_checklist_migrate(string $from_version): void
{
    global $DB;

    if (version_compare($from_version, '2.0.0', '<')) {
        plugin_checklist_migrate_to_200($DB);
    }

    if (version_compare($from_version, '2.0.1', '<')) {
        plugin_checklist_migrate_to_201($DB);
    }
}

/**
 * Stage v2.0.0 schema changes.
 *
 * Adds the denormalised progress counters (so search columns and the timeline
 * read a checklist's state without a JOIN + GROUP BY per row), mirrors the
 * template's `is_blocking` switch onto each checklist (the solve/close veto
 * hooks then cost one indexed lookup), and backfills both from the existing
 * task rows.
 *
 * Every statement re-checks the database first, so re-running is safe.
 */
function plugin_checklist_migrate_to_200($DB): void
{
    $cl  = 'glpi_plugin_checklist_checklists';
    $tpl = 'glpi_plugin_checklist_templates';
    $it  = 'glpi_plugin_checklist_items';

    $add = [
        $cl => [
            'items_total'    => 'INT UNSIGNED NOT NULL DEFAULT 0',
            'items_done'     => 'INT UNSIGNED NOT NULL DEFAULT 0',
            'percent_done'   => 'INT UNSIGNED NOT NULL DEFAULT 0',
            'is_blocking'    => 'TINYINT(1) NOT NULL DEFAULT 0',
            'date_completed' => 'TIMESTAMP NULL DEFAULT NULL',
        ],
        $tpl => [
            'is_blocking'    => 'TINYINT(1) NOT NULL DEFAULT 0',
        ],
    ];

    foreach ($add as $table => $cols) {
        if (!$DB->tableExists($table)) {
            continue;
        }
        foreach ($cols as $col => $ddl) {
            if (!$DB->fieldExists($table, $col)) {
                $DB->doQuery("ALTER TABLE `$table` ADD COLUMN `$col` $ddl");
            }
        }
    }

    // The CREATE TABLE above also declares KEY `status` and KEY `is_blocking`.
    // Adding a column never adds its index, so an upgraded install would end up
    // with a schema that differs from a fresh one — exactly the kind of drift
    // that makes later migrations and schema checks unreliable. Cheap to keep
    // aligned, so we do.
    if ($DB->tableExists($cl)) {
        foreach (['status', 'is_blocking'] as $index) {
            if (!$DB->fieldExists($cl, $index)) {
                continue;
            }
            // SHOW INDEX is the portable probe: no information_schema grants
            // needed, and it is scoped to the current database already.
            $res = $DB->doQuery("SHOW INDEX FROM `$cl` WHERE `Key_name` = '$index'");
            if ($res && $DB->numrows($res) === 0) {
                $DB->doQuery("ALTER TABLE `$cl` ADD INDEX `$index` (`$index`)");
            }
        }
    }

    if (!$DB->tableExists($cl) || !$DB->tableExists($it)) {
        return;
    }

    // Backfill every existing checklist in one statement.
    $DB->doQuery(
        "UPDATE `$cl` c
         LEFT JOIN (
             SELECT `plugin_checklist_checklists_id` AS cid,
                    COUNT(*) AS total,
                    SUM(CASE WHEN `status` = 'done' THEN 1 ELSE 0 END) AS done
             FROM `$it`
             GROUP BY `plugin_checklist_checklists_id`
         ) s ON s.cid = c.`id`
         SET c.`items_total`  = IFNULL(s.total, 0),
             c.`items_done`   = IFNULL(s.done, 0),
             c.`percent_done` = IF(IFNULL(s.total, 0) = 0, 0, FLOOR(IFNULL(s.done, 0) * 100 / s.total)),
             c.`status`       = IF(IFNULL(s.total, 0) > 0 AND IFNULL(s.done, 0) = s.total, 'done', 'open')"
    );
}

/**
 * Stage v2.0.1 schema changes: DATETIME -> TIMESTAMP on every date column.
 *
 * GLPI 11 deprecates DATETIME. DBmysql::checkForDeprecatedTableOptions()
 * (src/DBmysql.php) inspects every query and warns on `/ datetime /i`, and core
 * itself uses none at all — install/mysql/glpi-empty.sql is
 * `timestamp NULL DEFAULT NULL` throughout. Our CREATE TABLEs used DATETIME
 * since 1.x; most escaped the warning only because they were written
 * `DATETIME,` with no trailing space, which is luck rather than correctness.
 * `date_completed` (added in 2.0.0) was written `DATETIME     NULL,` and so
 * tripped it, which is how the customer found this.
 *
 * The explicit `NULL DEFAULT NULL` is not decoration. MySQL gives the FIRST
 * TIMESTAMP column in a table an implicit
 * `NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` unless the
 * nullability is spelled out — which would make `date_mod` silently rewrite
 * itself on every UPDATE. Always use the explicit form, exactly as core does.
 *
 * NOTE ON CONVERSION: MySQL reads existing DATETIME values as wall-clock time in
 * the session time zone when converting them to TIMESTAMP (which stores UTC).
 * Harmless here — these columns only ever hold recent "now"-ish values written
 * by this same server, and the affected installs are the fresh 2.0.0 ones. The
 * TIMESTAMP range (1970-01-01..2038-01-19) covers them for the same reason.
 *
 * Idempotent: each column's current type is probed first and only actually
 * still-DATETIME columns are rewritten, so re-running converges and never pays
 * for a needless table rebuild.
 */
function plugin_checklist_migrate_to_201($DB): void
{
    $columns = [
        'glpi_plugin_checklist_templates'     => ['date_creation', 'date_mod'],
        'glpi_plugin_checklist_templateitems' => ['date_creation', 'date_mod'],
        'glpi_plugin_checklist_checklists'    => ['date_creation', 'date_mod', 'date_completed'],
        'glpi_plugin_checklist_items'         => ['date_creation', 'date_mod', 'date_todo', 'date_notified'],
    ];

    foreach ($columns as $table => $cols) {
        if (!$DB->tableExists($table)) {
            continue;
        }
        foreach ($cols as $col) {
            if (!$DB->fieldExists($table, $col)) {
                continue;
            }
            // Per-column isolation: each conversion is independent and already
            // idempotent (via the SHOW COLUMNS probe below), so a single column
            // that fails to ALTER must NOT discard the conversions still queued
            // behind it. A try/catch around the whole loop did exactly that —
            // one failing column aborted every remaining one.
            try {
                // SHOW COLUMNS is the portable probe, consistent with the
                // SHOW INDEX used by the 2.0.0 step: no information_schema
                // grant needed and it is already scoped to the current schema.
                $res = $DB->doQuery("SHOW COLUMNS FROM `$table` LIKE '$col'");
                if (!$res || $DB->numrows($res) === 0) {
                    continue;
                }
                $row  = $DB->fetchAssoc($res);
                $type = strtolower((string) ($row['Type'] ?? ''));
                if (strpos($type, 'datetime') === false) {
                    continue;   // already TIMESTAMP — never blindly re-MODIFY
                }
                $DB->doQuery("ALTER TABLE `$table` MODIFY COLUMN `$col` TIMESTAMP NULL DEFAULT NULL");
            } catch (\Throwable $e) {
                // A failed type migration must never abort the install, nor take
                // its siblings with it: this column stays as-is and the others
                // still convert. Note a residual DATETIME column is SILENT, not
                // warned about — GLPI's deprecation check is a query-text linter
                // that fires only on ALTER/CREATE TABLE text it inspects, never
                // on a column merely sitting in the schema — so the cause is
                // logged here or it is lost entirely.
                Toolbox::logError('[checklist] 2.0.1 migration: could not convert '
                    . "`$table`.`$col` to TIMESTAMP: " . $e->getMessage());
            }
        }
    }
}

// ─── Uninstall ────────────────────────────────────────────────────────────────

// ─── Notification native « checklist terminée » ──────────────────────────────

/**
 * Itemtype carrying the notification. Both glpi_notifications and
 * glpi_notificationtemplates key on it, and it is what
 * NotificationTarget::getInstanceClass() derives our target class from.
 */
const PLUGIN_CHECKLIST_NOTIF_ITEMTYPE = 'PluginChecklistChecklist';
const PLUGIN_CHECKLIST_NOTIF_EVENT    = 'checklist_completed';

/**
 * Seed the admin-editable `checklist_completed` notification.
 *
 * FOUR tables have to line up or nothing is ever sent — the shape is copied from
 * core's own 10.0.x -> 11.0.0 migration
 * (install/migrations/update_10.0.x_to_11.0.0/notifications.php:43-109):
 *
 *   glpi_notificationtemplates            the template shell (keyed on itemtype)
 *   glpi_notificationtemplatetranslations the actual subject/body
 *   glpi_notifications                    the event -> template binding
 *   glpi_notifications_notificationtemplates  which mode (mail) uses which template
 *
 * Three details are load-bearing:
 *
 *   - `language => ''` is the FALLBACK translation core uses for every locale.
 *     A row for, say, `fr_FR` only would leave every other language with an
 *     empty body;
 *   - `entities_id => 0` + `is_recursive => 1` + `is_active => 1`, or
 *     Notification::getNotificationsByEventAndType() (src/Notification.php
 *     :622-667) filters the row out — it restricts on is_active AND on the
 *     entity tree — and the notification silently never fires;
 *   - the body is written with ##lang.checklist.xxx## captions next to the
 *     ##checklist.xxx## values. Those captions come from the target's getTags()
 *     labels and are translated AT RENDER TIME, so this single fallback
 *     translation reads correctly in every locale without one row per language.
 *
 * The content is seeded as RAW HTML. Core's own migration stores content_html
 * HTML-entity-encoded (`&lt;p&gt;…`), but there is no decoding step on the way
 * out — NotificationTemplate::getByLanguage() (:300-330) only substitutes tags —
 * so an encoded seed would deliver visible `<p>` markup as text.
 *
 * Idempotent AND self-repairing: each of the four rows is looked up before being
 * written, so re-running the installer (or having been interrupted halfway
 * through a previous run) converges instead of duplicating.
 */
function plugin_checklist_install_notification(): void
{
    global $DB;

    $now = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');

    // Traduit à l'installation, puis librement modifiable dans Configuration >
    // Notifications : ce n'est qu'une valeur de départ.
    $label = __('Checklist completed', 'checklist');

    // ── 1. Le gabarit ────────────────────────────────────────────────────────
    $templates_id = 0;
    if (
        countElementsInTable('glpi_notificationtemplates', [
            'itemtype' => PLUGIN_CHECKLIST_NOTIF_ITEMTYPE,
        ]) === 0
    ) {
        $DB->insert('glpi_notificationtemplates', [
            'name'          => $label,
            'itemtype'      => PLUGIN_CHECKLIST_NOTIF_ITEMTYPE,
            'date_mod'      => $now,
            'date_creation' => $now,
        ]);
        $templates_id = (int) $DB->insertId();
    } else {
        $row          = $DB->request([
            'SELECT' => ['id'],
            'FROM'   => 'glpi_notificationtemplates',
            'WHERE'  => ['itemtype' => PLUGIN_CHECKLIST_NOTIF_ITEMTYPE],
            'LIMIT'  => 1,
        ])->current();
        $templates_id = (int) ($row['id'] ?? 0);
    }

    if ($templates_id <= 0) {
        return;
    }

    // ── 2. La traduction de repli (language = '') ────────────────────────────
    if (
        countElementsInTable('glpi_notificationtemplatetranslations', [
            'notificationtemplates_id' => $templates_id,
            'language'                 => '',
        ]) === 0
    ) {
        $text = <<<'PLAINTEXT'
##lang.checklist.name## : ##checklist.name##
##lang.checklist.itemtype## : ##checklist.itemtype## ##checklist.itemname##
##lang.checklist.items_done## : ##checklist.items_done## / ##checklist.items_total## (##checklist.percent## %)
##lang.checklist.date_completed## : ##checklist.date_completed##
##lang.checklist.entity## : ##checklist.entity##

##lang.checklist.url## : ##checklist.url##
PLAINTEXT;

        $html = <<<'HTML'
<p><strong>##checklist.action## : ##checklist.name##</strong></p>
<ul>
<li>##lang.checklist.itemtype## : ##checklist.itemtype## ##checklist.itemname##</li>
<li>##lang.checklist.items_done## : ##checklist.items_done## / ##checklist.items_total## (##checklist.percent## %)</li>
<li>##lang.checklist.date_completed## : ##checklist.date_completed##</li>
<li>##lang.checklist.entity## : ##checklist.entity##</li>
</ul>
<p><a href="##checklist.url##">##lang.checklist.url##</a></p>
HTML;

        $DB->insert('glpi_notificationtemplatetranslations', [
            'notificationtemplates_id' => $templates_id,
            // Repli utilisé par TOUTES les langues — ne jamais mettre un code
            // de langue ici.
            'language'                 => '',
            // Uniquement des balises : le sujet se traduit de lui-même via
            // ##checklist.action##, libellé de l'évènement.
            'subject'                  => '##checklist.action## : ##checklist.name##',
            'content_text'             => $text,
            'content_html'             => $html,
        ]);
    }

    // ── 3. La notification ───────────────────────────────────────────────────
    $notifications_id = 0;
    if (
        countElementsInTable('glpi_notifications', [
            'itemtype' => PLUGIN_CHECKLIST_NOTIF_ITEMTYPE,
            'event'    => PLUGIN_CHECKLIST_NOTIF_EVENT,
        ]) === 0
    ) {
        $DB->insert('glpi_notifications', [
            'name'          => $label,
            'entities_id'   => 0,
            'itemtype'      => PLUGIN_CHECKLIST_NOTIF_ITEMTYPE,
            'event'         => PLUGIN_CHECKLIST_NOTIF_EVENT,
            'comment'       => '',
            // Racine + récursif : sinon la notification n'existe que pour
            // l'entité 0 et getNotificationsByEventAndType() ne la trouve jamais
            // pour une checklist portée par une sous-entité.
            'is_recursive'  => 1,
            'is_active'     => 1,
            'date_mod'      => $now,
            'date_creation' => $now,
        ]);
        $notifications_id = (int) $DB->insertId();
    } else {
        $row              = $DB->request([
            'SELECT' => ['id'],
            'FROM'   => 'glpi_notifications',
            'WHERE'  => [
                'itemtype' => PLUGIN_CHECKLIST_NOTIF_ITEMTYPE,
                'event'    => PLUGIN_CHECKLIST_NOTIF_EVENT,
            ],
            'LIMIT'  => 1,
        ])->current();
        $notifications_id = (int) ($row['id'] ?? 0);
    }

    if ($notifications_id <= 0) {
        return;
    }

    // ── 4. La liaison notification <-> gabarit, pour le mode « mail » ────────
    if (
        countElementsInTable('glpi_notifications_notificationtemplates', [
            'notifications_id' => $notifications_id,
            'mode'             => Notification_NotificationTemplate::MODE_MAIL,
        ]) === 0
    ) {
        $DB->insert('glpi_notifications_notificationtemplates', [
            'notifications_id'         => $notifications_id,
            'mode'                     => Notification_NotificationTemplate::MODE_MAIL,
            'notificationtemplates_id' => $templates_id,
        ]);
    }

    // Aucun destinataire n'est ajouté d'office (glpi_notificationtargets reste
    // vide) : c'est l'administrateur qui choisit qui prévenir. Une notification
    // sans cible n'envoie rien — cohérent avec un canal désactivé par défaut.
}

function plugin_checklist_uninstall(): bool
{
    global $DB;

    // ── Droits de profil : suppression avant les tables ──────────────────────
    PluginChecklistProfile::uninstall();

    // ── Notification native : les quatre tables, dans l'ordre des dépendances ─
    // Sans ce nettoyage, Configuration > Notifications garderait une entrée
    // pointant vers un itemtype qui n'existe plus.
    foreach (
        $DB->request([
            'SELECT' => ['id'],
            'FROM'   => 'glpi_notifications',
            'WHERE'  => ['itemtype' => PLUGIN_CHECKLIST_NOTIF_ITEMTYPE],
        ]) as $notif
    ) {
        $DB->delete('glpi_notifications_notificationtemplates', ['notifications_id' => $notif['id']]);
        $DB->delete('glpi_notificationtargets', ['notifications_id' => $notif['id']]);
        $DB->delete('glpi_notifications', ['id' => $notif['id']]);
    }

    foreach (
        $DB->request([
            'SELECT' => ['id'],
            'FROM'   => 'glpi_notificationtemplates',
            'WHERE'  => ['itemtype' => PLUGIN_CHECKLIST_NOTIF_ITEMTYPE],
        ]) as $tpl
    ) {
        $DB->delete('glpi_notificationtemplatetranslations', ['notificationtemplates_id' => $tpl['id']]);
        $DB->delete('glpi_notificationtemplates', ['id' => $tpl['id']]);
    }

    $tables = [
        'glpi_plugin_checklist_logs',
        'glpi_plugin_checklist_items',
        'glpi_plugin_checklist_checklists',
        'glpi_plugin_checklist_templateitems',
        'glpi_plugin_checklist_templates',
    ];

    foreach ($tables as $table) {
        if ($DB->tableExists($table)) {
            $DB->doQuery("DROP TABLE IF EXISTS `{$table}`");
        }
    }

    return true;
}

// ─── Colonnes de recherche sur Ticket / Change / Problem ─────────────────────

/**
 * Itemtypes carrying the injected checklist columns.
 *
 * Deliberately NOT read from PluginChecklistConfig::get()['itemtypes']: that
 * list drives the *tab*, and search options are cached per itemtype by
 * \Glpi\Search\SearchOption (static $search_options_cache), so making them
 * depend on a database-backed setting would give different columns to
 * different requests. The three ITIL types are the ones the feature is about.
 */
const PLUGIN_CHECKLIST_SEARCH_ITEMTYPES = ['Ticket', 'Change', 'Problem'];

/**
 * The checklist columns, in the reserved 9000-9099 id block.
 *
 * Single source of truth: the install-time collision assert reads its ids back
 * from here rather than keeping a second copy.
 *
 * Notes on the shape, all verified against GLPI 11.0.8:
 *
 *   - `jointype => itemtype_item` is the standard join for a child table keyed
 *     by `itemtype` + `items_id` (SQLProvider.php:3085), which is exactly the
 *     shape of glpi_plugin_checklist_checklists;
 *   - `forcegroupby` matters: ONE ITIL object can carry SEVERAL checklists.
 *     Without it the search engine emits one row per checklist and multiplies
 *     the ticket rows, so "tickets whose blocking checklist is unfinished"
 *     would come back duplicated and uncountable;
 *   - `itemtype` is set explicitly on the itemlink column; core reads
 *     $searchoptions['itemtype'] first and only then falls back to
 *     getItemTypeForTable() (src/CommonDBTM.php:5184, :5229);
 *   - `massiveaction => false` throughout: these are read-only projections of a
 *     child table, there is nothing to mass-edit on the ITIL row.
 *
 * @return array<int, array<string, mixed>>
 */
function plugin_checklist_itil_search_options(): array
{
    $table = 'glpi_plugin_checklist_checklists';
    $join  = ['jointype' => 'itemtype_item'];

    // Every msgid below already ships in the four catalogues — this only
    // composes them. '%1$s - %2$s' is a core (glpi-domain) format string.
    $label = static fn(string $sub): string => sprintf(
        __('%1$s - %2$s'),
        _n('Checklist', 'Checklists', 1, 'checklist'),
        $sub
    );

    return [
        [
            'id'            => '9001',
            'table'         => $table,
            'field'         => 'name',
            'name'          => $label(__('Name')),
            'datatype'      => 'itemlink',
            'itemtype'      => 'PluginChecklistChecklist',
            'forcegroupby'  => true,
            'splititems'    => true,
            'massiveaction' => false,
            'joinparams'    => $join,
        ],
        [
            'id'            => '9002',
            'table'         => $table,
            'field'         => 'percent_done',
            'name'          => $label(__('Percent done', 'checklist')),
            'datatype'      => 'number',
            'unit'          => '%',
            'forcegroupby'  => true,
            'massiveaction' => false,
            'joinparams'    => $join,
        ],
        [
            'id'            => '9004',
            'table'         => $table,
            'field'         => 'is_blocking',
            'name'          => $label(__('Blocking', 'checklist')),
            'datatype'      => 'bool',
            'massiveaction' => false,
            'joinparams'    => $join,
        ],
        [
            'id'            => '9005',
            'table'         => $table,
            'field'         => 'date_creation',
            'name'          => $label(__('Creation date', 'checklist')),
            'datatype'      => 'datetime',
            'forcegroupby'  => true,
            'massiveaction' => false,
            'joinparams'    => $join,
        ],
    ];
}

/**
 * Adds the checklist columns to the Ticket / Change / Problem search.
 *
 * This is the function core really calls: Plugin::getAddSearchOptionsNew()
 * looks up `plugin_<key>_getAddSearchOptionsNew` (src/Plugin.php:2185) from
 * SearchOption::getOptionsForItemtype() (src/Glpi/Search/SearchOption.php:389).
 * The 9.1-era `plugin_<key>_getAddSearchOptions` is merged one line earlier
 * (:388) with `+=`, so it would WIN on any shared id — we implement only this
 * one, and the return is a FLAT list with `id` as a member (:2189-2196).
 *
 * Two things core does NOT do for us:
 *
 *   1. no rights filter is applied to plugin-injected options (:2175-2200 makes
 *      no Session call at all) — without the gate below, a technician with no
 *      checklist right would still get the columns, and could read checklist
 *      names straight out of a search export;
 *   2. it would let `plugin_checklist_addLeftJoin` / `_addSelect` / `_giveItem`
 *      override the join for ANY glpi_plugin_checklist_* table, ahead of the
 *      declared jointype (SQLProvider.php:2880 / :537 / :6566). Those three are
 *      deliberately NOT defined anywhere in this plugin — do not add them.
 *
 * @param string $itemtype
 * @return array<int, array<string, mixed>>
 */
function plugin_checklist_getAddSearchOptionsNew($itemtype): array
{
    // The itemtype gate comes first: this runs on every search page render, and
    // a rights lookup for an unrelated itemtype would be pure waste.
    if (!in_array((string) $itemtype, PLUGIN_CHECKLIST_SEARCH_ITEMTYPES, true)) {
        return [];
    }

    if (!Session::haveRight('plugin_checklist_checklist', READ)) {
        return [];
    }

    return plugin_checklist_itil_search_options();
}

// ─── Massive action — appliquer un template à une sélection ──────────────────

/**
 * The plugin's massive actions, as offered on a search result.
 *
 * Core reaches this through Plugin::doOneHook(<plugin>, AUTO_MASSIVE_ACTIONS,
 * $itemtype) (src/MassiveAction.php:736), but ONLY for plugins that also set
 * $PLUGIN_HOOKS[Hooks::USE_MASSIVE_ACTION] — see setup.php.
 *
 * The key shape is '<ProcessorClass>' . CLASS_ACTION_SEPARATOR . '<action>',
 * the separator being ':' (MassiveAction.php:54). Core splits it at :312-315:
 * the part BEFORE the separator becomes `processor` — the class whose static
 * methods are called, so it must be autoloadable — and only the part AFTER it
 * survives as the action name. Hence the processor compares getAction()
 * against the BARE 'apply_template'.
 *
 * Three things this deliberately does NOT do:
 *
 *   1. no icon markup in the label: core strips `<i class="ti …">` in the list
 *      scenario and in the API, so anything encoded there is simply lost;
 *   2. no is_deleted handling: plugin actions are gathered inside the `else`
 *      branch of `if ($is_deleted)` (:645 vs :658/:731), so this is never
 *      offered in the trash bin and the processor never sees a deleted item;
 *   3. no per-item rights check here — that is the processor's job, since this
 *      list is built once for the whole selection.
 *
 * The itemtype gate reuses PLUGIN_CHECKLIST_SEARCH_ITEMTYPES: the massive
 * action belongs on exactly the itemtypes that carry the checklist columns, and
 * like them it must not vary per request.
 *
 * @param string $itemtype
 * @return array<string, string>
 */
function plugin_checklist_MassiveActions($itemtype): array
{
    // Itemtype first: this is evaluated on every search render, and a rights
    // lookup for an unrelated itemtype would be pure waste.
    if (!in_array((string) $itemtype, PLUGIN_CHECKLIST_SEARCH_ITEMTYPES, true)) {
        return [];
    }

    // Applying a template CREATES checklists — the create right is the one that
    // matters, not READ.
    if (!Session::haveRight('plugin_checklist_checklist', CREATE)) {
        return [];
    }

    return [
        'PluginChecklistChecklist' . MassiveAction::CLASS_ACTION_SEPARATOR . 'apply_template'
            => __('Apply a checklist template', 'checklist'),
    ];
}

// ─── Solve/close veto — blocking checklists ──────────────────────────────────
//
// These two functions run on EVERY ITIL update and on EVERY solution added, on
// every installation that has the plugin enabled. Three rules govern them:
//
//   1. The global kill-switch `blocking_enabled` (default 0) is read FIRST,
//      before any query. Registration of PRE_ITEM_ADD/PRE_ITEM_UPDATE is global,
//      so "off per template" would not be enough — the hooks fire the moment the
//      plugin is updated.
//   2. Fail OPEN. Every path is wrapped in try/catch (\Throwable) and simply
//      returns on error. A bug in this plugin must never freeze ticket closing.
//   3. Cheap first. countBlockingOpen() is a COUNT and is the gate;
//      getBlockingOpenItems() only runs once we already know we will refuse.
//
// Two seams are required, because closing through a solution does NOT go through
// Ticket::update() — ITILSolution::add() drives it:
//   * PRE_ITEM_ADD    on ITILSolution                  (the solution path)
//   * PRE_ITEM_UPDATE on Ticket / Change / Problem     (status dropdown, the
//     followup `_close` path, validation, and the autoclose CRON)
//
// The abort mechanism is native: setting $item->input = false inside the hook
// cancels the operation (src/CommonDBTM.php:1338 for add / :1678 for update,
// each followed by `if ($this->input && is_array($this->input))`). It is the
// mechanism the hook docblocks sanction (src/Glpi/Plugin/Hooks.php:453-457 and
// :481-485). No `&` is needed: Plugin::doHook() hands the OBJECT to the callback
// (src/Plugin.php:1810), so the property mutation is visible to core.

/**
 * Marker embedded in the CRON refusal followup so a later pass can recognise its
 * own note. ITILFollowup stores `content` verbatim (no purification on write —
 * RichText only sanitises at display time), so the comment survives in the
 * column and stays invisible in the UI.
 */
const PLUGIN_CHECKLIST_BLOCKED_MARKER = '<!-- plugin-checklist-blocked -->';

/**
 * Is the current request a CRON run — i.e. is there nobody who could read a
 * session message?
 *
 * Session::isCron() (src/Session.php:1029) is the authoritative signal and the
 * very one addMessageAfterRedirect() itself uses to divert messages to the cron
 * log. It is preferred over the naive "no logged-in user" test because INTERNAL
 * cron mode runs inside a normal, authenticated web request — there, a plain
 * `!isset($_SESSION['glpiID'])` would be false and the refusal message would be
 * shown to whichever user happened to trigger the cron.
 *
 * The "no logged-in user" test is kept as a fallback for contexts that are not
 * cron either (CLI scripts, the API), where a session message is equally
 * pointless.
 */
function plugin_checklist_is_unattended_context(): bool
{
    try {
        if (class_exists('Session') && method_exists('Session', 'isCron') && Session::isCron()) {
            return true;
        }
    } catch (\Throwable $e) {
        // Session::isCron() builds a Request from globals; if that ever throws,
        // fall through to the cheap test rather than propagating.
    }

    return (int) ($_SESSION['glpiID'] ?? 0) <= 0;
}

/**
 * Build the refusal text. Uses _n() so the count reads correctly in every
 * locale, then appends the task names (already fetched, since we only get here
 * when we are refusing).
 *
 * @param array<int, array<string,mixed>> $open_items
 */
function plugin_checklist_blocking_message(int $count, array $open_items = []): string
{
    $msg = sprintf(
        _n(
            'This item cannot be solved or closed: %d blocking checklist task is still open.',
            'This item cannot be solved or closed: %d blocking checklist tasks are still open.',
            $count,
            'checklist'
        ),
        $count
    );

    $names = [];
    foreach ($open_items as $row) {
        $name = trim((string) ($row['item_name'] ?? ''));
        if ($name !== '') {
            $names[] = $name;
        }
    }

    if ($names !== []) {
        $msg .= ' ' . sprintf(__('Remaining: %s', 'checklist'), implode(', ', $names));
    }

    return $msg;
}

/**
 * Has a refusal note already been written on this item?
 *
 * The autoclose CRON re-selects the same SOLVED ticket on every pass, forever —
 * without this guard a permanently blocked ticket would collect one followup per
 * run. The note is therefore posted ONCE per item: the condition it reports is
 * persistent, so repeating it adds noise and no information. Should the tasks be
 * ticked, the ticket closes and the matter ends; should they not, the single
 * note stands and the checklist tab shows the live state anyway.
 *
 * On a lookup failure we answer "already logged" (i.e. skip the note). The veto
 * itself has already been applied at that point, so the worst case here is a
 * missing trace — strictly better than a followup on every single CRON pass.
 */
function plugin_checklist_refusal_already_logged(string $itemtype, int $items_id): bool
{
    try {
        return countElementsInTable('glpi_itilfollowups', [
            'itemtype' => $itemtype,
            'items_id' => $items_id,
            'content'  => ['LIKE', '%' . PLUGIN_CHECKLIST_BLOCKED_MARKER . '%'],
        ]) > 0;
    } catch (\Throwable $e) {
        Toolbox::logWarning('Checklist: refusal-note lookup failed - ' . $e->getMessage());
        return true;
    }
}

/**
 * Report a refusal to whoever can actually receive it.
 *
 * Interactive: a session message at ERROR level (src/Session.php:1589; the ERROR
 * constant is src/autoload/constants.php:107). front/ticket.form.php ignores
 * update()'s return value, so this is the ONLY feedback channel a technician has.
 *
 * Unattended (CRON): a session message would only reach the cron log, so leave
 * the trace on the ticket instead — private, notifications suppressed.
 *
 * @param array<int, array<string,mixed>> $open_items
 */
function plugin_checklist_report_refusal(string $itemtype, int $items_id, int $count, array $open_items): void
{
    $message = plugin_checklist_blocking_message($count, $open_items);

    if (!plugin_checklist_is_unattended_context()) {
        Session::addMessageAfterRedirect($message, false, ERROR);
        return;
    }

    if ($items_id <= 0 || plugin_checklist_refusal_already_logged($itemtype, $items_id)) {
        return;
    }

    // `false` maps to _disablenotif; per the v1.1.0 invariant PluginChecklistFollowup
    // sets that key only when muting, because core tests it with isset().
    PluginChecklistFollowup::post(
        $itemtype,
        $items_id,
        $message . ' ' . __('Automatic closing was cancelled.', 'checklist') . PLUGIN_CHECKLIST_BLOCKED_MARKER,
        'private',
        false
    );
}

/** Is the veto switched on at all? Read before anything touches the database. */
function plugin_checklist_blocking_enabled(): bool
{
    return (int) (PluginChecklistConfig::get()['blocking_enabled'] ?? 0) === 1;
}

/**
 * Shared decision: may $itemtype/$items_id move into a terminal status?
 * Returns the refusal count (0 = allowed). Never throws.
 */
function plugin_checklist_blocking_open_count(string $itemtype, int $items_id): int
{
    if ($items_id <= 0 || $itemtype === '' || !class_exists('PluginChecklistChecklist')) {
        return 0;
    }

    return PluginChecklistChecklist::countBlockingOpen($itemtype, $items_id);
}

/**
 * PRE_ITEM_UPDATE on Ticket / Change / Problem.
 *
 * Fires on EVERY update, so it must be extremely selective:
 *   - no `status` in the input                → not a solve/close, ignore;
 *   - the new status is not SOLVED/CLOSED     → ignore;
 *   - the new status equals the current one   → NOT a transition. GLPI posts the
 *     whole form, so an already-solved ticket carries status=SOLVED on every
 *     ordinary edit; vetoing that would freeze editing of every solved ticket.
 *     Comparing against $item->fields is sound here because update() calls
 *     getFromDB() before firing the hook (src/CommonDBTM.php:1655).
 *
 * SOLVED → CLOSED IS a transition and is refused: that is exactly the path
 * Ticket::cronCloseTicket() takes.
 */
function plugin_checklist_pre_itil_update($item): void
{
    try {
        if (!plugin_checklist_blocking_enabled()) {
            return;
        }

        if (!is_object($item) || !is_array($item->input ?? null)) {
            return;
        }

        if (!isset($item->input['status'])) {
            return;
        }

        $new     = (int) $item->input['status'];
        $current = (int) ($item->fields['status'] ?? 0);

        if (!in_array($new, [CommonITILObject::SOLVED, CommonITILObject::CLOSED], true)) {
            return;
        }

        if ($new === $current) {
            return; // a re-save, not a transition
        }

        $itemtype = (string) $item->getType();
        $items_id = (int) $item->getID();

        $count = plugin_checklist_blocking_open_count($itemtype, $items_id);
        if ($count <= 0) {
            return;
        }

        // De-duplication: when the close was triggered by ITILSolution::post_addItem()
        // core puts `_trigger` in the input (src/ITILSolution.php:334-338). The
        // solution seam has already reported the refusal, so stay silent here —
        // the veto still stands, the technician just is not told twice.
        // Read BEFORE the veto: $item->input is about to stop being an array.
        $solution_driven = isset($item->input['_trigger']);

        // Report BEFORE applying the veto, deliberately. If explaining the refusal
        // throws, the outer catch fires while $item->input is still the original
        // array — the operation proceeds. A block the technician cannot see a
        // reason for is worse than no block at all: it looks like GLPI is broken.
        if (!$solution_driven) {
            plugin_checklist_report_refusal(
                $itemtype,
                $items_id,
                $count,
                PluginChecklistChecklist::getBlockingOpenItems($itemtype, $items_id)
            );
        }

        $item->input = false;
    } catch (\Throwable $e) {
        // FAIL OPEN — never let this plugin block a ticket.
        Toolbox::logWarning('Checklist: solve/close veto skipped - ' . $e->getMessage());
    }
}

/**
 * PRE_ITEM_ADD on ITILSolution — the path a technician actually uses to close a
 * ticket. The parent is named by the solution's own input.
 */
function plugin_checklist_pre_solution_add($item): void
{
    try {
        if (!plugin_checklist_blocking_enabled()) {
            return;
        }

        if (!is_object($item) || !is_array($item->input ?? null)) {
            return;
        }

        $itemtype = (string) ($item->input['itemtype'] ?? '');
        $items_id = (int) ($item->input['items_id'] ?? 0);

        $count = plugin_checklist_blocking_open_count($itemtype, $items_id);
        if ($count <= 0) {
            return;
        }

        // Report first, veto second — see plugin_checklist_pre_itil_update().
        plugin_checklist_report_refusal(
            $itemtype,
            $items_id,
            $count,
            PluginChecklistChecklist::getBlockingOpenItems($itemtype, $items_id)
        );

        $item->input = false;
    } catch (\Throwable $e) {
        // FAIL OPEN — never let this plugin block a solution.
        Toolbox::logWarning('Checklist: solution veto skipped - ' . $e->getMessage());
    }
}

// ─── Hooks item_add / item_update — moteur de règles (Phase 7) ───────────────

function plugin_checklist_item_add($item): void
{
    if ($item instanceof CommonDBTM) {
        PluginChecklistRuleChecklistCollection::processForItem(
            $item,
            PluginChecklistRuleChecklist::ONADD
        );
    }
}

function plugin_checklist_item_update($item): void
{
    if ($item instanceof CommonDBTM) {
        PluginChecklistRuleChecklistCollection::processForItem(
            $item,
            PluginChecklistRuleChecklist::ONUPDATE
        );
    }
}

// ─── Hook timeline_actions — bouton dans le menu d'action du ticket ───────────

/**
 * Adds one timeline entry per checklist, showing its stored progress.
 *
 * Fired by core from CommonITILObject::getTimelineItems()
 * (src/CommonITILObject.php:8031) as:
 *
 *     Plugin::doHook(Hooks::TIMELINE_ITEMS, ['item' => $this, 'timeline' => &$timeline]);
 *
 * ── Why we write into $params['timeline'][…] and never touch $params ──────────
 *
 * Plugin::doHook dispatches with `call_user_func($function, $data)`
 * (src/Plugin.php:1826) — BY VALUE. Our $params is therefore a private copy and
 * reassigning it (`$params['timeline'] = …`, or `$params = …`) is discarded the
 * moment we return. What survives is that the CALLER built the array with
 * `'timeline' => &$timeline`, so the copy's 'timeline' slot still points at
 * core's array. Writing THROUGH it — $params['timeline'][$key] = … — is the one
 * mutation core sees. This is load-bearing and easy to "tidy" into a no-op.
 *
 * ── The entry shape ──────────────────────────────────────────────────────────
 *
 * Consumed by templates/components/itilobject/timeline/timeline.html.twig:49-64
 * and by the usort at CommonITILObject.php:8035. Modelled on core's own
 * ITILReminder entry (CommonITILObject.php:8015-8027), which is the reference
 * implementation for a plugin-ish, template-less timeline item:
 *
 *   - 'date'              the sort key. Its absence is a fatal INSIDE core's
 *                         usort, i.e. it breaks the whole timeline, not just us.
 *   - 'id'                int — the same usort subtracts ids to break date ties.
 *   - 'is_content_safe'   true selects |raw over |safe_html (twig:200-204).
 *                         Our markup is generated here, so it is safe — but that
 *                         makes escaping OUR job: the checklist name is
 *                         user-supplied and is htmlspecialchars()'d below.
 *   - 'object'            without it Twig falls back to
 *                         get_item(entry['type'], id) (twig:50) and also feeds
 *                         it to the PRE/POST_SHOW_ITEM hooks (twig:116, 222).
 *                         We already hold the row, so we hydrate the object
 *                         in-memory rather than pay a second SELECT.
 *
 * No Twig template is registered on purpose: a 'type' that is absent from
 * `timeline_itemtypes` falls through to the plain-content branch (twig:186-206),
 * which renders item.content directly. That is exactly what we want.
 *
 * @param array{item?: mixed, timeline?: array<string, mixed>} $params
 */
function plugin_checklist_timeline_items(array $params): void
{
    global $DB;

    // This runs on EVERY ITIL timeline render. Nothing in here is worth taking
    // a ticket page down for, so the whole body is fenced: on any failure the
    // timeline simply renders without our entries.
    try {
        $item = $params['item'] ?? null;
        if (!($item instanceof CommonITILObject)) {
            return;
        }

        if (!Session::haveRight('plugin_checklist_checklist', READ)) {
            return;
        }

        $itemtype = $item->getType();
        $items_id = (int) $item->getID();

        foreach ($DB->request([
            'FROM'  => PluginChecklistChecklist::getTable(),
            'WHERE' => ['itemtype' => $itemtype, 'items_id' => $items_id],
            'ORDER' => ['date_creation ASC'],
        ]) as $cl) {
            $cl_id = (int) $cl['id'];

            // Read the DENORMALISED columns (CL2-T2), maintained on every task
            // move since CL2-T3. Recounting here would be both a query per
            // checklist per render AND a different number: the stored value is
            // written with FLOOR (see plugin_checklist_backfill_progress), so a
            // round() here would report 999/1000 as 100%.
            $done  = (int) ($cl['items_done'] ?? 0);
            $total = (int) ($cl['items_total'] ?? 0);
            $pct   = (int) ($cl['percent_done'] ?? 0);
            // The tone (bg-success vs bg-primary) is derived from $full inside
            // getProgressHtml() now — it was a local here and a local in
            // renderCard, deriving the same fact twice.
            $full  = $total > 0 && $done >= $total;

            // ── THE THIRD COPY, DELETED (v2.1.0 Task 5) ──────────────────────
            //
            // This block used to hand-repeat renderCard's markup: the icon, the
            // title span, the counter, the progress bar and the badge. Same
            // classes, so it inherits public/css/checklist.css — but "same
            // classes" was maintained by hand, and it did not hold: the progress
            // bar here was 110px wide while renderCard's was 100px and the JS
            // card builder's was 120px, so one checklist drew three different
            // bars depending on where you looked at it.
            //
            // It now calls the same two renderers renderCard does, so the
            // question "do these agree?" cannot be asked any more.
            //
            // ⚠ ESCAPING IS OURS. 'is_content_safe' => true below selects |raw
            // over |safe_html in timeline.html.twig:200-204, so Twig will not
            // escape any of this. Both fragments htmlspecialchars() everything
            // user-supplied — the checklist name above all — exactly as this
            // block did; smoke_checklist_render.php runs this very function over
            // a fixture whose name contains <b>XPS</b> and reads the bytes.
            //
            // The type name is spelled out rather than left to the icon, the way
            // core labels its own template-less entry (the ITILReminder block at
            // CommonITILObject.php:8010 does the same): in a timeline mixing
            // followups, tasks, solutions and validations, an unlabelled row is
            // guesswork. It reuses the existing 'Checklist'/'Checklists' msgid,
            // so no new string enters the catalogues. It is the ONE thing the
            // tab's card does not show, and it is why getIdentityHtml() takes a
            // label at all.
            $label   = _n('Checklist', 'Checklists', 1, 'checklist');
            $content = '<div class="cl-card-hdr-left"'
                . ' title="' . htmlspecialchars($label) . '">'
                . PluginChecklistChecklist::getIdentityHtml((string) $cl['name'], $done, $total, $label)
                . PluginChecklistChecklist::getProgressHtml($pct, $full)
                . '</div>';

            // Key convention documented at Glpi/Plugin/Hooks.php:739
            // ("${itemtype}_${items_id}"). It must be unique per checklist or
            // the second entry silently overwrites the first.
            $params['timeline']['PluginChecklistChecklist_' . $cl_id] = [
                'type' => 'PluginChecklistChecklist',
                'item' => [
                    'id'                => $cl_id,
                    'content'           => $content,
                    'is_content_safe'   => true,
                    'date'              => $cl['date_creation'],
                    'users_id'          => (int) ($cl['users_id'] ?? 0),
                    'can_edit'          => false,
                    'timeline_position' => CommonITILObject::TIMELINE_LEFT,
                ],
                'object' => plugin_checklist_hydrate_checklist($cl),
            ];
        }
    } catch (\Throwable $e) {
        Toolbox::logError('[checklist] timeline_items: ' . $e->getMessage());
        return;
    }
}

/**
 * Builds a PluginChecklistChecklist carrying an already-fetched row, without
 * going back to the database.
 *
 * Only needed because Twig hands `entry['object']` to the PRE/POST_SHOW_ITEM
 * hooks (timeline.html.twig:116, 222), which expect a real object. getFromDB()
 * would re-SELECT a row we are already holding, once per checklist per render.
 *
 * @param array<string, mixed> $row
 */
function plugin_checklist_hydrate_checklist(array $row): PluginChecklistChecklist
{
    $obj         = new PluginChecklistChecklist();
    $obj->fields = $row;
    return $obj;
}

function plugin_checklist_timeline_actions(array $params): void
{
    $item = $params['item'] ?? null;
    if (!($item instanceof CommonITILObject)) {
        return;
    }

    // Same READ gate as plugin_checklist_timeline_items(): without the checklist
    // READ right this must not run the countElementsInTable probe below, whose
    // result (and the action it would offer) leaks that the item has a checklist.
    if (!Session::haveRight('plugin_checklist_checklist', READ)) {
        return;
    }

    $items_id = (int) $item->getID();
    $itemtype = $item->getType();

    // Only offer the action when this item actually has at least one checklist.
    if (countElementsInTable(PluginChecklistChecklist::getTable(), [
        'itemtype' => $itemtype,
        'items_id' => $items_id,
    ]) === 0) {
        return;
    }

    $ajax_url = plugin_checklist_web_dir() . '/ajax';

    static $modal_injected = false;
    if (!$modal_injected) {
        $modal_injected = true;
        plugin_checklist_inject_validate_assets($ajax_url);
    }

    // Rendered small; the injected JS relocates it into the ticket's "Answer"
    // (▾) actions menu (or restyles it as a small button when that menu is
    // absent). The global [data-clv-id] click handler opens the modal.
    echo '<li class="cl-tl-validate">';
    echo '<a href="#" role="button" class="dropdown-item"';
    echo ' data-clv-id="' . $items_id . '" data-clv-type="' . htmlspecialchars($itemtype) . '">';
    echo '<i class="ti ti-checks me-2"></i>';
    echo htmlspecialchars(__('Validate a checklist task', 'checklist'));
    echo '</a>';
    echo '</li>';
}

function plugin_checklist_inject_validate_assets(string $ajax_url): void
{
    // Reuse the PAGE CSRF token (sent via the X-Glpi-Csrf-Token header, which
    // the kernel validates with preserve_token). Do NOT mint a standalone
    // token per render — that churns GLPI's limited CSRF pool and can evict
    // other tokens (e.g. the date-range search widget's).
    //
    // Behaviour lives in public/js/checklist-validate.js; this only hands the
    // server values over as JSON. The script is loaded in <head>, so it reads
    // this object lazily, at call time.
    //
    // The three *_html fragments joined them in v2.1.0 Task 5. They used to be
    // string literals in the .js, built with a clvEsc() that did not escape
    // apostrophes — a second escaper and a second place markup lived. The
    // client now appends the shell to <body> and drops the placeholders into
    // the modal body, but it does not write any of them.
    //
    // JSON_HEX_TAG is what makes embedding markup in a script block safe: every
    // `<` leaves here as <, so nothing in the payload can close the
    // enclosing tag. The browser turns it back into `<` when it parses the
    // object, before the client inserts it.
    echo '<script>window.PLUGIN_CHECKLIST_VALIDATE = '
        . json_encode(
            [
                'ajax'         => $ajax_url,
                'csrf'         => Session::getNewCSRFToken(),
                'modal_html'   => PluginChecklistChecklist::getValidateModalHtml(),
                'loading_html' => PluginChecklistChecklist::getValidateLoadingHtml(),
                'error_html'   => PluginChecklistChecklist::getValidateErrorHtml(),
            ],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP
        )
        . ';</script>';
}
