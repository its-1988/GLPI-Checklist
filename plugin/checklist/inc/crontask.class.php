<?php
/**
 * PluginChecklistCronTask — Tâche planifiée : notifications des tâches en retard
 *
 * Parcourt toutes les tâches en statut "todo" dont le template définit un délai
 * de notification (> 0). Si la tâche dépasse son délai (date_todo + délai converti
 * en heures), une notification est émise et date_notified est mis à jour pour
 * éviter le spam (re-notification uniquement si un nouveau retard est constaté).
 */

declare(strict_types=1);

class PluginChecklistCronTask extends CommonDBTM
{
    public static $rightname = 'config';

    /** Multiplicateurs pour convertir le délai vers des heures */
    public const UNIT_MULTIPLIERS = [
        'hours' => 1,
        'days'  => 24,
        'weeks' => 168, // 24 * 7
    ];

    /**
     * Libellés des unités (pour le formulaire et l'affichage).
     */
    public static function getUnitLabels(): array
    {
        return [
            'hours' => __('Hours', 'checklist'),
            'days'  => __('Days', 'checklist'),
            'weeks' => __('Weeks', 'checklist'),
        ];
    }

    /**
     * Convertit une valeur de délai + unité en nombre d'heures.
     */
    public static function delayToHours(int $value, string $unit): int
    {
        $mult = self::UNIT_MULTIPLIERS[$unit] ?? 1;
        return $value * $mult;
    }

    /**
     * Description affichée dans Configuration > Actions automatiques.
     */
    public static function cronInfo(string $name): array
    {
        return match ($name) {
            'checklistOverdue' => ['description' => __('Send notifications for overdue checklist tasks', 'checklist')],
            default            => [],
        };
    }

    /**
     * Point d'entrée du CRON (appelé par GLPI : cron + nom de tâche).
     *
     * @param CronTask|null $task
     * @return int 0 = rien à faire, 1 = effectué
     */
    public static function cronChecklistOverdue($task = null): int
    {
        global $DB;

        $it_table  = PluginChecklistItem::getTable();
        $cl_table  = PluginChecklistChecklist::getTable();
        $tpl_table = PluginChecklistTemplate::getTable();

        $iterator = $DB->request([
            'SELECT'     => [
                "$it_table.id AS item_id",
                "$it_table.name AS item_name",
                "$it_table.date_todo",
                "$it_table.date_notified",
                "$cl_table.id AS cl_id",
                "$cl_table.name AS cl_name",
                "$cl_table.itemtype",
                "$cl_table.items_id",
                "$tpl_table.notification_delay_hours AS delay_value",
                "$tpl_table.notification_delay_unit AS delay_unit",
                "$cl_table.plugin_checklist_templates_id AS templates_id",
            ],
            'FROM'       => $it_table,
            'INNER JOIN' => [
                $cl_table => [
                    'ON' => [$it_table => 'plugin_checklist_checklists_id', $cl_table => 'id'],
                ],
                $tpl_table => [
                    'ON' => [$cl_table => 'plugin_checklist_templates_id', $tpl_table => 'id'],
                ],
            ],
            'WHERE'      => [
                "$it_table.status"                    => 'todo',
                "$tpl_table.notification_delay_hours" => ['>', 0],
                'NOT'                                 => ["$it_table.date_todo" => null],
            ],
        ]);

        $now   = new DateTime();
        $total = 0;

        foreach ($iterator as $row) {
            $delay_hours = self::delayToHours((int) $row['delay_value'], $row['delay_unit'] ?: 'hours');
            if ($delay_hours <= 0) {
                continue;
            }

            $deadline = (new DateTime($row['date_todo']))->modify("+{$delay_hours} hours");

            // Pas encore en retard
            if ($now < $deadline) {
                continue;
            }

            // Déjà notifié après cette échéance → on ne re-notifie pas
            if (!empty($row['date_notified'])) {
                $notified = new DateTime($row['date_notified']);
                if ($notified >= $deadline) {
                    continue;
                }
            }

            self::notifyOverdue($row, $delay_hours);

            $DB->update($it_table, [
                'date_notified' => $now->format('Y-m-d H:i:s'),
            ], ['id' => (int) $row['item_id']]);

            if ($task instanceof CronTask) {
                $task->addVolume(1);
            }
            $total++;
        }

        return $total > 0 ? 1 : 0;
    }

    /**
     * Emit the notification for an overdue task: on an ITIL object, a message
     * written by the settings-aware service (the per-template override wins
     * over the global setting). A checklist carried by an asset produces none.
     */
    private static function notifyOverdue(array $row, int $delay_hours): void
    {
        $global   = PluginChecklistConfig::get();
        $template = [];
        if ((int) ($row['templates_id'] ?? 0) > 0) {
            global $DB;
            $template = $DB->request([
                'FROM'  => PluginChecklistTemplate::getTable(),
                'WHERE' => ['id' => (int) $row['templates_id']],
            ])->current() ?: [];
        }

        if ((int) PluginChecklistConfig::resolve('followup_on_overdue', $template, $global) === 1) {
            $unit_labels = self::getUnitLabels();
            $delay_value = (int) $row['delay_value'];
            $delay_unit  = $row['delay_unit'] ?: 'hours';

            $content = sprintf(
                __('⏰ Overdue checklist task: «%1$s» (checklist «%2$s») has been pending for more than %3$s.', 'checklist'),
                $row['item_name'],
                $row['cl_name'],
                $delay_value . ' ' . mb_strtolower($unit_labels[$delay_unit] ?? $delay_unit, 'UTF-8')
            );

            PluginChecklistFollowup::post(
                (string) $row['itemtype'],
                (int) $row['items_id'],
                $content,
                (string) PluginChecklistConfig::resolve('overdue_privacy', $template, $global),
                (int) PluginChecklistConfig::resolve('notify_on_overdue', $template, $global) === 1
            );
        }
    }
}
