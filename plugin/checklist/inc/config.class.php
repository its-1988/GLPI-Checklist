<?php
/**
 * PluginChecklistConfig — global plugin settings (context `plugin:checklist`
 * in glpi_configs) plus the resolution rules for per-template overrides.
 *
 * Modified 2026 — i18n, settings and native-CRUD rework.
 */

declare(strict_types=1);

class PluginChecklistConfig extends CommonDBTM
{
    public static $rightname = 'config';

    public const CONTEXT = 'plugin:checklist';

    /** Privacy modes for messages written into the ticket. */
    public const PRIVACY = ['glpi', 'public', 'private'];

    /** Sentinel used by per-template overrides to defer to the global value. */
    public const INHERIT = 'inherit';

    /** Keys a template may override (the rest are global-only). */
    public const OVERRIDABLE = [
        'followup_on_item_done', 'followup_privacy', 'notify_on_item_done',
        'followup_on_overdue', 'overdue_privacy', 'notify_on_overdue',
    ];

    /**
     * Keys that live in the config table but are NOT user settings: they mirror
     * database state and are written only by the installer/migrations.
     *
     * showConfigForm() never renders them and buildValues() drops them, so a
     * hand-crafted POST cannot rewrite them. That matters: forging
     * `schema_version` backwards (or forwards) would make the next install
     * replay — or silently skip — schema migrations.
     */
    public const NON_FORM_KEYS = ['schema_version'];

    public static function getTypeName($nb = 0): string
    {
        return __('Checklist setup', 'checklist');
    }

    /** @return array<string, mixed> Single source of truth for keys + defaults. */
    public static function defaults(): array
    {
        return [
            'followup_on_item_done'     => 1,
            'followup_privacy'          => 'glpi',
            'notify_on_item_done'       => 1,
            'followup_on_overdue'       => 1,
            'overdue_privacy'           => 'private',
            'notify_on_overdue'         => 1,
            'aggregate_bulk_validation' => 1,
            // Raise the native `checklist_completed` notification (Setup >
            // Notifications) when a checklist reaches 100 %.
            //
            // A SEPARATE key, and OFF by default. It must never be folded into
            // `notify_on_item_done`: that one only decides whether the followup
            // written into the timeline is muted, and reusing it would hand
            // every existing installation a duplicate e-mail the moment they
            // upgrade — exactly the noise the v1.1.0 bulk aggregation removed.
            'native_notify_on_completed' => 0,
            // Refuse to solve/close an ITIL object that still has open BLOCKING
            // checklist tasks. Ships OFF: the hooks behind it are registered
            // globally and run on every ITIL update, so an installation that
            // merely updates the plugin must not suddenly start rejecting
            // closures. Turning it on is an explicit, informed decision.
            'blocking_enabled'          => 0,
            'itemtypes'                 => [
                'Ticket', 'Change', 'Problem',
                'Computer', 'Phone', 'Monitor', 'NetworkEquipment',
                'Printer', 'Software', 'Peripheral', 'Rack', 'Enclosure',
            ],
            // DB state, not a user setting — see NON_FORM_KEYS. Written by
            // plugin_checklist_install() once the migrations have run.
            'schema_version'            => '2.1.0',
        ];
    }

    /** Current global values, merged over the defaults. */
    public static function get(): array
    {
        $stored = Config::getConfigurationValues(self::CONTEXT);
        $values = self::defaults();

        foreach ($values as $key => $default) {
            if (!array_key_exists($key, $stored)) {
                continue;
            }
            $values[$key] = $key === 'itemtypes'
                ? array_values(array_filter(array_map('trim', explode(',', (string) $stored[$key]))))
                : (is_int($default) ? (int) $stored[$key] : (string) $stored[$key]);
        }

        return $values;
    }

    /**
     * Whitelist posted values against defaults(), cast to each default's type
     * and validate enums. Unknown keys are dropped.
     *
     * @return array<string, mixed>
     */
    public static function buildValues(array $post): array
    {
        $values = [];

        foreach (self::defaults() as $key => $default) {
            // NON_FORM_KEYS mirror database state (schema_version), not user
            // preferences. They share the config context but are owned by the
            // installer, so they are dropped here unconditionally: the settings
            // page never posts them, and a forged POST must not be able to
            // rewrite the recorded schema version behind the migrations' back.
            if (in_array($key, self::NON_FORM_KEYS, true)) {
                continue;
            }

            if (!array_key_exists($key, $post)) {
                continue;
            }

            if ($key === 'itemtypes') {
                $list         = is_array($post[$key]) ? $post[$key] : explode(',', (string) $post[$key]);
                $values[$key] = implode(',', array_values(array_filter(array_map('trim', array_map('strval', $list)))));
                continue;
            }

            if (str_ends_with($key, '_privacy')) {
                $mode         = (string) $post[$key];
                $values[$key] = in_array($mode, self::PRIVACY, true) ? $mode : 'glpi';
                continue;
            }

            $values[$key] = is_int($default) ? (int) $post[$key] : (string) $post[$key];
        }

        return $values;
    }

    /** Persist posted settings. */
    public static function saveFromPost(array $post): void
    {
        $values = self::buildValues($post);
        if ($values !== []) {
            Config::setConfigurationValues(self::CONTEXT, $values);
        }
    }

    /**
     * Global settings form — reached from the plugin list's «Configure» gear
     * (Hooks::CONFIG_PAGE → front/config.form.php).
     *
     * Persistence goes exclusively through saveFromPost(): get() hands back
     * `itemtypes` as an ARRAY while the config table stores a CSV string, so
     * feeding a get() result straight to the Config API would persist "Array".
     */
    public static function showConfigForm(): void
    {
        $cfg     = self::get();
        $web_dir = plugin_checklist_web_dir();

        $privacy_choices = [
            'glpi'    => __('As in GLPI', 'checklist'),
            'public'  => __('Public', 'checklist'),
            'private' => __('Private', 'checklist'),
        ];
        $privacy_hint = __('«As in GLPI» follows each technician\'s own followup privacy preference.', 'checklist');

        $lbl_followup = __('Add a followup to the ticket', 'checklist');
        $lbl_privacy  = __('Followup visibility', 'checklist');
        $lbl_notify   = __('Send a notification', 'checklist');

        echo '<form method="POST" action="' . htmlspecialchars($web_dir) . '/front/config.form.php">';
        // Page token — never a standalone one: standalone tokens churn GLPI's
        // capped CSRF pool and evict the tokens of other open forms.
        echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);

        // ── Quand une tâche de checklist est terminée ──────────────────────────
        echo '<div class="card mb-3">';
        echo '<div class="card-header"><strong>' . __('When a checklist item is completed', 'checklist') . '</strong></div>';
        echo '<div class="card-body row g-3">';

        echo '<div class="col-md-4">';
        echo '<label class="form-label">' . $lbl_followup . '</label>';
        Dropdown::showYesNo('followup_on_item_done', $cfg['followup_on_item_done']);
        echo '</div>';

        echo '<div class="col-md-4">';
        echo '<label class="form-label">' . $lbl_privacy . '</label>';
        Dropdown::showFromArray('followup_privacy', $privacy_choices, ['value' => $cfg['followup_privacy']]);
        echo '<small class="text-muted d-block mt-1">' . $privacy_hint . '</small>';
        echo '</div>';

        echo '<div class="col-md-4">';
        echo '<label class="form-label">' . $lbl_notify . '</label>';
        Dropdown::showYesNo('notify_on_item_done', $cfg['notify_on_item_done']);
        echo '</div>';

        echo '</div></div>'; // card-body / card

        // ── Quand une tâche de checklist est en retard ─────────────────────────
        echo '<div class="card mb-3">';
        echo '<div class="card-header"><strong>' . __('When a checklist task is overdue', 'checklist') . '</strong></div>';
        echo '<div class="card-body row g-3">';

        echo '<div class="col-md-4">';
        echo '<label class="form-label">' . $lbl_followup . '</label>';
        Dropdown::showYesNo('followup_on_overdue', $cfg['followup_on_overdue']);
        echo '</div>';

        echo '<div class="col-md-4">';
        echo '<label class="form-label">' . $lbl_privacy . '</label>';
        Dropdown::showFromArray('overdue_privacy', $privacy_choices, ['value' => $cfg['overdue_privacy']]);
        echo '<small class="text-muted d-block mt-1">' . $privacy_hint . '</small>';
        echo '</div>';

        echo '<div class="col-md-4">';
        echo '<label class="form-label">' . $lbl_notify . '</label>';
        Dropdown::showYesNo('notify_on_overdue', $cfg['notify_on_overdue']);
        echo '</div>';

        echo '</div></div>'; // card-body / card

        // ── Général ───────────────────────────────────────────────────────────
        echo '<div class="card mb-3">';
        echo '<div class="card-header"><strong>' . __('General', 'checklist') . '</strong></div>';
        echo '<div class="card-body row g-3">';

        echo '<div class="col-md-4">';
        echo '<label class="form-label">' . __('Group bulk validations into a single followup', 'checklist') . '</label>';
        Dropdown::showYesNo('aggregate_bulk_validation', $cfg['aggregate_bulk_validation']);
        echo '</div>';

        echo '<div class="col-md-8">';
        echo '<label class="form-label">'
            . __('Send a GLPI notification when a checklist is completed', 'checklist')
            . '</label>';
        Dropdown::showYesNo('native_notify_on_completed', $cfg['native_notify_on_completed']);
        echo '<small class="text-muted d-block mt-1">'
            . __(
                'A second, independent channel: the followup written into the timeline is unaffected. Wording, recipients and per-language text are editable in Setup > Notifications («Checklist completed»). Disabled by default so that upgrading never starts sending mail on its own.',
                'checklist'
            )
            . '</small>';
        echo '</div>';

        echo '<div class="col-md-8">';
        echo '<label class="form-label">'
            . __('Block solving/closing when a blocking checklist has open tasks', 'checklist')
            . '</label>';
        Dropdown::showYesNo('blocking_enabled', $cfg['blocking_enabled']);
        echo '<small class="text-muted d-block mt-1">'
            . __(
                'Applies to every ticket, change and problem in this GLPI, not only to those using a checklist template. Disabled by default; the automatic closing CRON is refused too and records a private followup instead.',
                'checklist'
            )
            . '</small>';
        echo '</div>';

        // Human labels for the itemtypes the plugin can attach checklists to.
        $choices = [];
        foreach (self::defaults()['itemtypes'] as $type) {
            $choices[$type] = class_exists($type) ? $type::getTypeName(1) : $type;
        }

        echo '<div class="col-md-8">';
        echo '<label class="form-label">' . __('Item types', 'checklist') . '</label>';
        // multiple => true makes GLPI post `itemtypes` as an array;
        // buildValues() accepts both an array and a CSV string.
        Dropdown::showFromArray('itemtypes', $choices, [
            'values'   => $cfg['itemtypes'],
            'multiple' => true,
        ]);
        echo '</div>';

        echo '</div></div>'; // card-body / card

        // The page itself opens on READ (so the «Configure» gear never leads to a
        // rights error); only offer the Save button to profiles that may write.
        if (Session::haveRight('config', UPDATE)) {
            echo '<div class="d-flex gap-2 mb-4">';
            echo Html::submit(_x('button', 'Save'), ['name' => 'update', 'class' => 'btn btn-primary']);
            echo '</div>';
        }

        echo '</form>';
    }

    /**
     * Resolve one setting: the template's value wins unless it is INHERIT
     * (or absent/empty); otherwise the global value; otherwise the default.
     *
     * @param array<string,mixed> $template_row row from glpi_plugin_checklist_templates
     * @param array<string,mixed> $global       result of self::get()
     */
    public static function resolve(string $key, array $template_row, array $global): mixed
    {
        $override = $template_row[$key] ?? self::INHERIT;

        if ($override !== self::INHERIT && $override !== '' && $override !== null) {
            $default = self::defaults()[$key] ?? null;
            return is_int($default) ? (int) $override : (string) $override;
        }

        if (array_key_exists($key, $global)) {
            return $global[$key];
        }

        return self::defaults()[$key] ?? null;
    }
}
