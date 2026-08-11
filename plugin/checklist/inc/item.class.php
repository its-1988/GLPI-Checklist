<?php
/**
 * PluginChecklistItem — a single task of an instantiated checklist.
 *
 * Modified 2026 — i18n, settings and native-CRUD rework.
 */

declare(strict_types=1);

class PluginChecklistItem extends CommonDBChild
{
    // Parent : la checklist. La chaîne des droits remonte jusqu'à l'élément
    // GLPI porteur (Ticket, Computer…) — PluginChecklistChecklist est lui-même
    // un CommonDBChild —, ce qui rend tout contrôle d'accès maison inutile.
    public static $itemtype = 'PluginChecklistChecklist';
    public static $items_id = 'plugin_checklist_checklists_id';

    public $dohistory = true;

    public static $rightname = 'plugin_checklist_checklist';

    public static function getTypeName($nb = 0): string
    {
        return _n('Checklist item', 'Checklist items', $nb, 'checklist');
    }

    public static function getTable($classname = null): string
    {
        return 'glpi_plugin_checklist_items';
    }

    /**
     * Options de recherche natives — voir PluginChecklistChecklist::rawSearchOptions()
     * pour le détail du contrat (`searchOptions()` est `final`, liste plate, `id`
     * et `name` obligatoires, objet instancié vide).
     */
    public function rawSearchOptions()
    {
        // Retrait de l'`id => 1` par défaut de la base avant de le redéfinir :
        // l'ajouter par-dessus serait un doublon (E_USER_WARNING), pas une
        // surcharge. Le datatype passe d'`itemlink` à `string` car une tâche n'a
        // pas de formulaire autonome — elle ne s'affiche qu'à l'intérieur de sa
        // checklist —, donc le lien généré pointerait dans le vide.
        $tab = array_values(array_filter(
            parent::rawSearchOptions(),
            static fn(array $opt): bool => (string) ($opt['id'] ?? '') !== '1'
        ));

        $tab[] = [
            'id'            => '1',
            'table'         => self::getTable(),
            'field'         => 'name',
            'name'          => __('Name'),
            'datatype'      => 'string',
            'massiveaction' => false,
        ];

        $tab[] = [
            'id'            => '2',
            'table'         => self::getTable(),
            'field'         => 'status',
            'name'          => __('Status', 'checklist'),
            'datatype'      => 'string',
            'massiveaction' => false,
        ];

        $tab[] = [
            'id'            => '3',
            'table'         => self::getTable(),
            'field'         => 'is_exceptional',
            'name'          => __('Exceptional', 'checklist'),
            'datatype'      => 'bool',
            'massiveaction' => false,
        ];

        $tab[] = [
            'id'            => '4',
            'table'         => self::getTable(),
            'field'         => 'date_todo',
            'name'          => __('Due date', 'checklist'),
            'datatype'      => 'datetime',
            'massiveaction' => false,
        ];

        return $tab;
    }

    // ─── Toggle todo ↔ done ────────────────────────────────────────────────────

    /**
     * Flip one item todo ↔ done.
     *
     * @param bool $write_followup Pass false to suppress the per-item message —
     *                             used by toggleMany() when a single aggregated
     *                             followup is written for the whole batch.
     */
    public static function toggleStatus(int $item_id, bool $write_followup = true): array
    {
        $item = new self();
        if (!$item->getFromDB($item_id)) {
            return ['success' => false, 'error' => 'Item not found'];
        }

        $new_status = $item->fields['status'] === 'todo' ? 'done' : 'todo';

        $update = ['id' => $item_id, 'status' => $new_status];
        if ($new_status === 'todo') {
            // Redémarre le compte à rebours du CRON « en retard ».
            $update['date_todo'] = date('Y-m-d H:i:s');
        }

        $cl_id = (int) $item->fields['plugin_checklist_checklists_id'];

        // update() horodate date_mod et journalise le changement de statut dans
        // l'historique natif de l'élément GLPI parent.
        $item->update($update);

        if ($new_status === 'done' && $write_followup) {
            self::addTicketFollowup($cl_id, $item->fields['name']);
        }

        return ['success' => true, 'new_status' => $new_status, 'item_id' => $item_id];
    }

    /**
     * Mark several items done in one go. With aggregation enabled (the default)
     * ONE summary followup is written per checklist involved, instead of one per
     * item — 20 ticked items must not mean 20 notification e-mails.
     *
     * A batch may legitimately span several checklists of the same ticket (the
     * validation modal lists them all), so the validated names are grouped BY
     * CHECKLIST: each summary then names the checklist it actually belongs to.
     * A batch confined to a single checklist still produces exactly one message,
     * worded identically.
     *
     * @param array<int,int|string> $item_ids
     * @return array{success:bool, done:int}
     */
    public static function toggleMany(array $item_ids): array
    {
        $global    = PluginChecklistConfig::get();
        $aggregate = (int) ($global['aggregate_bulk_validation'] ?? 1) === 1;

        $done = 0;
        /** @var array<int, array<int, string>> $names_by_checklist checklist id => validated names */
        $names_by_checklist = [];

        foreach ($item_ids as $raw_id) {
            $id   = (int) $raw_id;
            $item = new self();
            if ($id <= 0 || !$item->getFromDB($id) || $item->fields['status'] !== 'todo') {
                continue;
            }

            $cl_id = (int) $item->fields['plugin_checklist_checklists_id'];
            if ($cl_id > 0) {
                $names_by_checklist[$cl_id][] = (string) $item->fields['name'];
            }

            self::toggleStatus($id, !$aggregate);
            $done++;
        }

        if ($aggregate) {
            foreach ($names_by_checklist as $cl_id => $names) {
                self::addBatchFollowup((int) $cl_id, $names);
            }
        }

        return ['success' => true, 'done' => $done];
    }

    /**
     * Resolve what the two followup writers below need in common: the parent
     * ITIL object, the checklist name and the effective message settings (the
     * per-template override wins over the global one). Returns null when
     * nothing should be written at all — unknown checklist, or the
     * `followup_on_item_done` gate turned off.
     *
     * @return array{itemtype:string, items_id:int, cl_name:string, privacy:string, notify:bool}|null
     */
    private static function resolveFollowupTarget(int $cl_id): ?array
    {
        global $DB;

        $cl_row = $DB->request([
            'FROM'  => PluginChecklistChecklist::getTable(),
            'WHERE' => ['id' => $cl_id],
        ])->current();

        if (!$cl_row) {
            return null;
        }

        $global   = PluginChecklistConfig::get();
        $template = [];
        if ((int) ($cl_row['plugin_checklist_templates_id'] ?? 0) > 0) {
            $template = $DB->request([
                'FROM'  => PluginChecklistTemplate::getTable(),
                'WHERE' => ['id' => (int) $cl_row['plugin_checklist_templates_id']],
            ])->current() ?: [];
        }

        if ((int) PluginChecklistConfig::resolve('followup_on_item_done', $template, $global) !== 1) {
            return null;
        }

        return [
            'itemtype' => (string) $cl_row['itemtype'],
            'items_id' => (int) $cl_row['items_id'],
            'cl_name'  => (string) $cl_row['name'],
            'privacy'  => (string) PluginChecklistConfig::resolve('followup_privacy', $template, $global),
            'notify'   => (int) PluginChecklistConfig::resolve('notify_on_item_done', $template, $global) === 1,
        ];
    }

    /**
     * Announce a completed item on the parent ITIL object, through the single
     * settings-aware writer. A checklist carried by an asset simply produces no
     * message.
     */
    private static function addTicketFollowup(int $cl_id, string $item_name): void
    {
        $target = self::resolveFollowupTarget($cl_id);
        if ($target === null) {
            return;
        }

        $content = sprintf(
            __('✅ Task «%1$s» validated via checklist «%2$s».', 'checklist'),
            htmlspecialchars($item_name),
            htmlspecialchars($target['cl_name'])
        );

        PluginChecklistFollowup::post(
            $target['itemtype'],
            $target['items_id'],
            $content,
            $target['privacy'],
            $target['notify']
        );
    }

    /**
     * Announce a whole batch of completed items in ONE message — the aggregated
     * counterpart of addTicketFollowup(), honouring the very same settings.
     *
     * @param array<int,string> $names
     */
    private static function addBatchFollowup(int $cl_id, array $names): void
    {
        if ($names === []) {
            return;
        }

        $target = self::resolveFollowupTarget($cl_id);
        if ($target === null) {
            return;
        }

        $content = sprintf(
            _n('✅ %d checklist task validated:', '✅ %d checklist tasks validated:', count($names), 'checklist'),
            count($names)
        ) . '<ul><li>' . implode('</li><li>', array_map('htmlspecialchars', $names)) . '</li></ul>';

        PluginChecklistFollowup::post(
            $target['itemtype'],
            $target['items_id'],
            $content,
            $target['privacy'],
            $target['notify']
        );
    }

    // ─── Ordonnancement ────────────────────────────────────────────────────────

    /**
     * Deliberate exception to the "everything through the model" rule: rank is a
     * pure ordering field, and routing it through update() would write a history
     * entry on the parent ITIL object for every single drag-and-drop. Reordering
     * is not a business change, so it stays a raw write.
     */
    public static function updateRanks(array $item_ids, string $column): bool
    {
        global $DB;

        $rank_field = $column === 'done' ? 'rank_done' : 'rank_todo';
        foreach ($item_ids as $rank => $id) {
            $DB->update(static::getTable(), [$rank_field => $rank], ['id' => (int) $id]);
        }
        return true;
    }

    // ─── Ajout d'une tâche exceptionnelle ──────────────────────────────────────

    public static function addExceptional(int $checklists_id, string $name, string $description = ''): int|false
    {
        global $DB;

        $max = (int) ($DB->request([
            'SELECT' => ['MAX' => 'rank_todo AS max_rank'],
            'FROM'   => static::getTable(),
            'WHERE'  => ['plugin_checklist_checklists_id' => $checklists_id],
        ])->current()['max_rank'] ?? -1);

        // add() horodate date_creation/date_mod et journalise l'ajout dans
        // l'historique natif de l'élément GLPI parent.
        $new_id = (new self())->add([
            'plugin_checklist_checklists_id' => $checklists_id,
            'name'                           => $name,
            'description'                    => $description,
            'status'                         => 'todo',
            'rank_todo'                      => $max + 1,
            'rank_done'                      => 0,
            'is_exceptional'                 => 1,
            // Point de départ du délai de retard (lu par le CRON).
            'date_todo'                      => date('Y-m-d H:i:s'),
            'users_id_creator'               => Session::getLoginUserID() ?: 0,
        ]);

        return $new_id === false ? false : (int) $new_id;
    }

    // ─── Hooks natifs post-écriture ────────────────────────────────────────────
    //
    // Toute la maintenance des colonnes de progression tient ici. GLPI appelle
    // ces trois méthodes après chaque add()/update()/purge() passé par le
    // modèle, donc toggleStatus(), toggleMany(), addExceptional(),
    // l'instanciation depuis un template, les MassiveActions et l'API REST sont
    // couverts d'un coup — aucun appel manuel à semer sur les sites d'appel.
    //
    // Signatures reprises telles quelles de GLPI 11.0.8 (src/CommonDBTM.php
    // 1626 / 2074 / 2243) : $history reste non typé, le typer `bool` serait un
    // rétrécissement de contrat et PHP refuserait de charger la classe.

    public function post_addItem()
    {
        parent::post_addItem();
        PluginChecklistChecklist::refreshProgress((int) $this->fields['plugin_checklist_checklists_id']);
    }

    public function post_updateItem($history = true)
    {
        parent::post_updateItem($history);
        PluginChecklistChecklist::refreshProgress((int) $this->fields['plugin_checklist_checklists_id']);
    }

    public function post_purgeItem()
    {
        parent::post_purgeItem();
        // fields[] est encore renseigné à ce stade : GLPI purge la ligne puis
        // appelle le hook, l'objet garde ses valeurs en mémoire. Si c'est la
        // checklist elle-même qui est purgée (cleanDBonPurge), refreshProgress()
        // ne trouvera plus la ligne parente et sortira sans rien faire.
        PluginChecklistChecklist::refreshProgress((int) $this->fields['plugin_checklist_checklists_id']);
    }

    // ─── Récupération groupée ──────────────────────────────────────────────────

    public static function getForChecklist(int $checklists_id): array
    {
        global $DB;

        $result = ['todo' => [], 'done' => []];

        foreach (['todo', 'done'] as $status) {
            $rank_field = $status === 'done' ? 'rank_done' : 'rank_todo';
            $iterator   = $DB->request([
                'FROM'  => static::getTable(),
                'WHERE' => [
                    'plugin_checklist_checklists_id' => $checklists_id,
                    'status'                         => $status,
                ],
                'ORDER' => [$rank_field . ' ASC'],
            ]);
            foreach ($iterator as $row) {
                $result[$status][] = $row;
            }
        }

        return $result;
    }
}
