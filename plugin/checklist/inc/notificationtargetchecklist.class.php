<?php
/**
 * PluginChecklistNotificationTargetChecklist — the native notification raised
 * when a checklist becomes complete.
 *
 * This is a SECOND, opt-in channel. The followup written into the ticket
 * timeline (PluginChecklistFollowup) is unchanged and remains the default; this
 * one exists so an administrator can rewrite the wording, the recipients and
 * the per-language text in Setup > Notifications instead of the plugin
 * hardcoding a message. It ships OFF — see
 * PluginChecklistConfig::defaults()['native_notify_on_completed'].
 *
 * ── The class name and the file name are COMPUTED, not chosen ────────────────
 *
 * NotificationTarget::getInstanceClass() (GLPI 11.0.8,
 * src/NotificationTarget.php:454) builds the target class name itself:
 *
 *     'Plugin' . $plug['plugin'] . 'NotificationTarget' . $plug['class']
 *
 * with isPluginItemType() (src/autoload/misc-functions.php:76) splitting
 * `PluginChecklistChecklist` on /^Plugin([A-Z][a-z0-9]+)([A-Z]\w+)$/ into
 * plugin `Checklist` + class `Checklist`. The only name core will ever look for
 * is therefore PluginChecklistNotificationTargetChecklist. The intuitive
 * mirror image — NotificationTargetPluginChecklistChecklist, which is how core's
 * own non-plugin targets are named — would never be found.
 *
 * The legacy autoloader (src/autoload/legacy-autoloader.php:77) then applies the
 * SAME regex to that computed name, giving plugin `Checklist` + class
 * `NotificationTargetChecklist`, and looks for
 *
 *     plugins/checklist/inc/notificationtargetchecklist.class.php
 *
 * which is this file. Neither miss raises an error: a wrong name or a wrong path
 * makes getInstance() return false and the notification simply never fires.
 */

declare(strict_types=1);

class PluginChecklistNotificationTargetChecklist extends NotificationTarget
{
    /**
     * The one event this plugin raises. Deliberately singular: a per-task event
     * would reproduce, over the notification engine, exactly the flood that the
     * v1.1.0 bulk aggregation removed from the timeline.
     */
    public function getEvents()
    {
        return ['checklist_completed' => __('Checklist completed', 'checklist')];
    }

    /**
     * EXTRA recipients, on top of core's.
     *
     * addNotificationTargets() — the base method — is what contributes the
     * global administrator, the entity administrator, the profiles and the
     * groups. It is NOT overridden here: overriding it would silently delete all
     * four from the administrator's recipient dropdown, leaving a notification
     * nobody can address.
     *
     * Only ITEM_USER is offered. Core resolves it through
     * addItemOwner() -> addUserByField('users_id', true)
     * (src/NotificationTarget.php:1313), and `users_id` is a real column of
     * glpi_plugin_checklist_checklists — it holds whoever created the checklist.
     *
     * ITEM_TECH_IN_CHARGE / ITEM_TECH_GROUP_IN_CHARGE are deliberately absent:
     * they read `users_id_tech` / `groups_id_tech`, columns this table does not
     * have, and addUserByField() dereferences $target->fields[$field] with no
     * isset() guard (:1266) — offering them would spray undefined-key warnings
     * into the logs for every notification sent.
     */
    public function addAdditionalTargets($event = '')
    {
        $this->addTarget(
            Notification::ITEM_USER,
            __('Author of the checklist', 'checklist')
        );
    }

    /**
     * Fill the ##checklist.xxx## tags the template may reference.
     *
     * Everything is read from $this->obj (the checklist row NotificationEvent
     * was handed), never from $options: options travel through the notification
     * queue and may only carry scalars, so relying on them would make the mail
     * body depend on how the event happened to be raised.
     */
    public function addDataForTemplate($event, $options = [])
    {
        $events    = $this->getAllEvents();
        $checklist = $this->obj;

        $itemtype = (string) ($checklist->fields['itemtype'] ?? '');
        $items_id = (int) ($checklist->fields['items_id'] ?? 0);

        // Human label + name of the object the checklist hangs on. A checklist
        // can live on a Ticket, a Change, a Computer… so both are resolved
        // dynamically rather than assuming an ITIL object.
        $item_label = '';
        $item_name  = '';
        if ($itemtype !== '' && class_exists($itemtype) && is_subclass_of($itemtype, 'CommonDBTM')) {
            $item_label = $itemtype::getTypeName(1);
            $parent     = new $itemtype();
            if ($items_id > 0 && $parent->getFromDB($items_id)) {
                $item_name = (string) ($parent->fields['name'] ?? '');
            }
        }

        $this->data['##checklist.action##']       = $events[$event] ?? $event;
        $this->data['##checklist.id##']           = (int) $checklist->fields['id'];
        $this->data['##checklist.name##']         = (string) ($checklist->fields['name'] ?? '');
        $this->data['##checklist.itemtype##']     = $item_label;
        $this->data['##checklist.itemname##']     = $item_name;
        $this->data['##checklist.items_total##']  = (int) ($checklist->fields['items_total'] ?? 0);
        $this->data['##checklist.items_done##']   = (int) ($checklist->fields['items_done'] ?? 0);
        $this->data['##checklist.percent##']      = (int) ($checklist->fields['percent_done'] ?? 0);
        $this->data['##checklist.is_blocking##']  = Dropdown::getYesNo($checklist->fields['is_blocking'] ?? 0);
        // convDateTime() returns NULL for a NULL date (src/Html.php:190); the tag
        // must be a string or the substitution prints nothing useful.
        $this->data['##checklist.date_completed##'] = (string) Html::convDateTime(
            $checklist->fields['date_completed'] ?? null
        );
        $this->data['##checklist.entity##']       = Dropdown::getDropdownName(
            'glpi_entities',
            $checklist->fields['entities_id'] ?? 0
        );

        // The link points at the PARENT object, not at the checklist: a
        // checklist has no standalone form, it is a tab on the ticket/asset.
        $this->data['##checklist.url##'] = ($itemtype !== '' && $items_id > 0)
            ? $this->formatURL(
                $options['additionnaloption']['usertype'] ?? null,
                $itemtype . '_' . $items_id
            )
            : '';

        // Same closing move as core's own targets (NotificationTargetCertificate
        // :113-118): expose the ##lang.*## labels so a template can print a
        // translated caption next to each value.
        $this->getTags();
        foreach ($this->tag_descriptions[NotificationTarget::TAG_LANGUAGE] as $tag => $values) {
            if (!isset($this->data[$tag])) {
                $this->data[$tag] = $values['label'];
            }
        }
    }

    /**
     * Publish the tag list shown to the administrator in the template editor.
     * Every tag declared here is filled in addDataForTemplate() above — a tag
     * that is declared but never filled renders as literal ##checklist.x##
     * text in the delivered e-mail.
     */
    public function getTags()
    {
        // «Total tasks», «Completed tasks», «Percent done» and «Blocking» are
        // reused verbatim from the search-option labels (rawSearchOptions): the
        // same value should not be called two different things depending on
        // whether the operator is reading a column header or an e-mail.
        $tags = [
            'checklist.id'             => __('Checklist ID', 'checklist'),
            'checklist.name'           => __('Checklist name', 'checklist'),
            'checklist.action'         => __('Event', 'checklist'),
            'checklist.itemtype'       => __('Item type', 'checklist'),
            'checklist.itemname'       => __('Related item', 'checklist'),
            'checklist.items_total'    => __('Total tasks', 'checklist'),
            'checklist.items_done'     => __('Completed tasks', 'checklist'),
            'checklist.percent'        => __('Percent done', 'checklist'),
            'checklist.is_blocking'    => __('Blocking', 'checklist'),
            'checklist.date_completed' => __('Completion date', 'checklist'),
            'checklist.entity'         => __('Entity', 'checklist'),
            'checklist.url'            => __('Link to the related item', 'checklist'),
        ];

        foreach ($tags as $tag => $label) {
            $this->addTagToList([
                'tag'   => $tag,
                'label' => $label,
                'value' => true,
            ]);
        }

        asort($this->tag_descriptions);
    }
}
