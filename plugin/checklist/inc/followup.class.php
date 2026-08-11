<?php
/**
 * PluginChecklistFollowup — the single place that writes a message into an ITIL
 * object and decides whether GLPI notifies about it.
 *
 * Privacy modes: 'public' (0), 'private' (1), 'glpi' (follow the technician's own
 * `followup_private` preference, exactly like a manually added followup; under
 * CRON there is no session, so it resolves to public).
 *
 * Notification: GLPI raises `add_followup` from ITILFollowup::add(). The native
 * kill-switch is `_disablenotif`, but core checks it with isset() — passing
 * `false` ALSO mutes. Therefore the key is added ONLY when muting.
 *
 * Modified 2026 — i18n, settings and native-CRUD rework.
 */

declare(strict_types=1);

class PluginChecklistFollowup
{
    /**
     * ITIL objects GLPI's ITILFollowup can be attached to. A checklist may live
     * on an asset (Computer, Printer, …) too — those simply get no message.
     */
    public const SUPPORTED_ITEMTYPES = ['Ticket', 'Change', 'Problem'];

    /** Map a privacy mode to the is_private column value. */
    public static function resolvePrivacy(string $mode): int
    {
        return match ($mode) {
            'public'  => 0,
            'private' => 1,
            default   => (int) ($_SESSION['glpifollowup_private'] ?? 0),
        };
    }

    /**
     * Write a followup on an ITIL object. Best-effort: never throws, so a failure
     * here cannot break ticking a checklist item.
     */
    public static function post(string $itemtype, int $items_id, string $content, string $privacy, bool $notify): bool
    {
        if (!in_array($itemtype, self::SUPPORTED_ITEMTYPES, true)) {
            return false;
        }

        if ($items_id <= 0 || $content === '' || !class_exists('ITILFollowup')) {
            return false;
        }

        $input = [
            'itemtype'        => $itemtype,
            'items_id'        => $items_id,
            'content'         => $content,
            'is_private'      => self::resolvePrivacy($privacy),
            'requesttypes_id' => RequestType::getDefault('followup'),
        ];

        // Only set the key when muting — core checks it with isset().
        if (!$notify) {
            $input['_disablenotif'] = true;
        }

        try {
            $followup = new ITILFollowup();
            return (bool) $followup->add($input);
        } catch (\Throwable $e) {
            Toolbox::logWarning('Checklist: followup failed - ' . $e->getMessage());
            return false;
        }
    }
}
