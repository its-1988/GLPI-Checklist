<?php
/**
 * PluginChecklistTemplate — Modèle de checklist réutilisable
 */

declare(strict_types=1);

class PluginChecklistTemplate extends CommonDBTM
{
    public static $rightname = 'plugin_checklist_template';

    public static function getTypeName($nb = 0): string
    {
        return _n('Checklist template', 'Checklist templates', $nb, 'checklist');
    }

    public static function getIcon(): string
    {
        return 'fas fa-tasks';
    }

    public static function getTable($classname = null): string
    {
        return 'glpi_plugin_checklist_templates';
    }

    /**
     * Options de recherche natives — voir PluginChecklistChecklist::rawSearchOptions()
     * pour le détail du contrat (`searchOptions()` est `final`, liste plate, `id`
     * et `name` obligatoires, objet instancié vide).
     *
     * Les pages maison (`showList()` / `showForm()`) restent en place : elles ne
     * sont PAS migrées vers `Search::show()`. Ces options sont un ajout pur, qui
     * sert la recherche, l'export et l'API REST.
     */
    public function rawSearchOptions()
    {
        // La table porte `is_recursive` : la base contribue l'option 86 « Entités
        // filles », qu'on laisse passer. Seul l'`id => 1` par défaut est retiré
        // pour être redéfini sans doublon (E_USER_WARNING sinon).
        $tab = array_values(array_filter(
            parent::rawSearchOptions(),
            static fn(array $opt): bool => (string) ($opt['id'] ?? '') !== '1'
        ));

        $tab[] = [
            'id'            => '1',
            'table'         => self::getTable(),
            'field'         => 'name',
            'name'          => __('Name'),
            'datatype'      => 'itemlink',
            'massiveaction' => false,
        ];

        $tab[] = [
            'id'            => '2',
            'table'         => self::getTable(),
            'field'         => 'is_active',
            'name'          => __('Active', 'checklist'),
            'datatype'      => 'bool',
            'massiveaction' => false,
        ];

        $tab[] = [
            'id'            => '3',
            'table'         => self::getTable(),
            'field'         => 'is_blocking',
            'name'          => __('Blocking', 'checklist'),
            'datatype'      => 'bool',
            'massiveaction' => false,
        ];

        // La colonne s'appelle `notification_delay_hours` pour raisons
        // historiques, mais son unité vit dans `notification_delay_unit` : le
        // libellé reste donc « Notification delay », sans unité codée en dur.
        $tab[] = [
            'id'            => '4',
            'table'         => self::getTable(),
            'field'         => 'notification_delay_hours',
            'name'          => __('Notification delay', 'checklist'),
            'datatype'      => 'number',
            'massiveaction' => false,
        ];

        $tab[] = [
            'id'            => '19',
            'table'         => self::getTable(),
            'field'         => 'date_creation',
            'name'          => __('Creation date', 'checklist'),
            'datatype'      => 'datetime',
            'massiveaction' => false,
        ];

        // Voir le commentaire de l'option 80 côté checklist : `dropdown` joint la
        // table étrangère, donc `field` nomme une colonne de la table des
        // entités. On passe par `Entity::getTable()` plutôt que par le littéral :
        // c'est l'accesseur natif, et le nom de table en dur reste ainsi réservé
        // aux requêtes maison — que ce fichier n'a plus le droit d'écrire depuis
        // le correctif N+1 (voir smoke_checklist_entity).
        $tab[] = [
            'id'            => '80',
            'table'         => Entity::getTable(),
            'field'         => 'completename',
            'name'          => Entity::getTypeName(1),
            'datatype'      => 'dropdown',
            'massiveaction' => false,
        ];

        return $tab;
    }

    public static function getMenuName(): string
    {
        return __('Checklist templates', 'checklist');
    }

    public static function canView(): bool
    {
        return (bool) Session::haveRight(self::$rightname, READ);
    }

    public static function canCreate(): bool
    {
        return (bool) Session::haveRight(self::$rightname, CREATE);
    }

    public static function canUpdate(): bool
    {
        return (bool) Session::haveRight(self::$rightname, UPDATE);
    }

    public static function canPurge(): bool
    {
        return (bool) Session::haveRight(self::$rightname, PURGE);
    }

    public static function getMenuContent(): array|false
    {
        if (!static::canView()) {
            return false;
        }

        $web_dir = plugin_checklist_web_dir();
        $menu = [
            'title' => static::getMenuName(),
            'page'  => $web_dir . '/front/template.php',
            'icon'  => static::getIcon(),
            'links' => [
                'search' => $web_dir . '/front/template.php',
            ],
        ];

        if (static::canCreate()) {
            $menu['links']['add'] = $web_dir . '/front/template.form.php';
        }

        return $menu;
    }

    // ─── Récupération ─────────────────────────────────────────────────────────

    /**
     * Templates visible from an entity, in one query.
     *
     * The entity restriction is GLPI's own: getEntitiesRestrictCriteria() builds
     * "entities_id = <entity> OR (is_recursive = 1 AND entities_id IN <ancestors>)"
     * as criteria the query builder folds into this SELECT. Ancestors come from
     * glpi_entities.ancestors_cache (cached), so the whole visibility question
     * costs one query for the whole list instead of one tree walk per template.
     */
    public static function getAll(bool $active_only = true, ?int $entity_id = null): array
    {
        global $DB;

        $entity_id ??= (int) Session::getActiveEntity();

        // Keys are disjoint: the helper's alias wraps its criteria under a crc32
        // key precisely so it can be merged without clobbering anything.
        $where = getEntitiesRestrictCriteria(static::getTable(), 'entities_id', $entity_id, true);
        if ($active_only) {
            $where['is_active'] = 1;
        }

        $items    = [];
        $iterator = $DB->request(["FROM" => static::getTable(), "WHERE" => $where, "ORDER" => ["name ASC"]]);

        foreach ($iterator as $row) {
            $items[] = $row;
        }

        return $items;
    }

    public static function getVisibleForEntity(int $entity_id, bool $active_only = true): array
    {
        return self::getAll($active_only, $entity_id);
    }

    public static function canUseTemplateForEntity(int $templates_id, int $entity_id): bool
    {
        if ($templates_id <= 0) {
            return true;
        }

        global $DB;

        $row = $DB->request([
            "FROM"  => static::getTable(),
            "WHERE" => ["id" => $templates_id],
        ])->current();

        if (!$row || !(int) ($row["is_active"] ?? 0)) {
            return false;
        }

        return self::isVisibleInEntity($row, $entity_id);
    }

    /**
     * Same restriction as getAll(), narrowed to a single row: the template is
     * visible iff it still matches once the entity criteria are applied.
     */
    private static function isVisibleInEntity(array $template, int $entity_id): bool
    {
        return countElementsInTable(
            static::getTable(),
            ['id' => (int) ($template['id'] ?? 0)]
            + getEntitiesRestrictCriteria(static::getTable(), 'entities_id', $entity_id, true)
        ) > 0;
    }

    // ─── Affichage liste ──────────────────────────────────────────────────────

    public static function showList(): void
    {
        $templates = self::getAll(false);
        $web_dir   = plugin_checklist_web_dir();

        echo '<div class="container-fluid">';
        echo '<div class="d-flex justify-content-between align-items-center mb-3">';
        echo '<h2>' . __('Checklist templates', 'checklist') . '</h2>';
        echo '<a class="btn btn-primary" href="' . htmlspecialchars($web_dir) . '/front/template.form.php">';
        echo '<i class="fas fa-plus me-1"></i>' . __('Add a template', 'checklist') . '</a>';
        echo '</div>';

        if (empty($templates)) {
            echo '<div class="alert alert-info">' . __('No template yet. Create one!', 'checklist') . '</div>';
            echo '</div>';
            return;
        }

        echo '<table class="table table-striped table-hover">';
        echo '<thead class="table-dark"><tr>';
        echo '<th>' . __('Name') . '</th>';
        echo '<th>' . __('Active') . '</th>';
        echo '<th>' . __('Notification delay', 'checklist') . '</th>';
        echo '<th>' . __('Tasks', 'checklist') . '</th>';
        echo '<th>' . __('Actions') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($templates as $t) {
            $nb  = countElementsInTable(PluginChecklistTemplateItem::getTable(), ['plugin_checklist_templates_id' => $t['id']]);
            $url = $web_dir . '/front/template.form.php?id=' . $t['id'];

            echo '<tr>';
            echo '<td><a href="' . htmlspecialchars($url) . '">' . htmlspecialchars($t['name']) . '</a></td>';
            echo '<td>' . ($t['is_active'] ? '<span class="badge bg-success">' . __('Yes') . '</span>' : '<span class="badge bg-secondary">' . __('No') . '</span>') . '</td>';
            if ($t['notification_delay_hours'] > 0) {
                $unit_labels = PluginChecklistCronTask::getUnitLabels();
                $unit_lbl    = $unit_labels[$t['notification_delay_unit'] ?? 'hours'] ?? $t['notification_delay_unit'];
                echo '<td>' . (int) $t['notification_delay_hours'] . ' ' . htmlspecialchars(strtolower($unit_lbl)) . '</td>';
            } else {
                echo '<td>—</td>';
            }
            echo '<td>' . $nb . '</td>';
            echo '<td><a class="btn btn-sm btn-outline-primary" href="' . htmlspecialchars($url) . '"><i class="fas fa-edit"></i></a></td>';
            echo '</tr>';
        }

        echo '</tbody></table></div>';
    }

    // ─── Formulaire CRUD ──────────────────────────────────────────────────────

    public function showForm($ID, array $options = []): bool
    {
        $this->initForm($ID, $options);
        $web_dir = plugin_checklist_web_dir();
        $is_new  = $ID <= 0;

        echo '<form method="POST" action="' . htmlspecialchars($web_dir) . '/front/template.form.php">';
        echo Html::hidden('id', ['value' => $ID]);
        echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);

        // ── Champs principaux ──────────────────────────────────────────────────
        echo '<div class="card mb-3">';
        echo '<div class="card-header"><strong>' . ($is_new ? __('New template', 'checklist') : htmlspecialchars($this->fields['name'])) . '</strong></div>';
        echo '<div class="card-body row g-3">';

        echo '<div class="col-md-5">';
        echo '<label class="form-label">' . __('Name') . ' <span class="text-danger">*</span></label>';
        echo '<input type="text" class="form-control" name="name" required value="' . htmlspecialchars($this->fields['name'] ?? '') . '">';
        echo '</div>';

        echo '<div class="col-md-3">';
        echo '<label class="form-label">' . __('Active') . '</label>';
        echo '<select class="form-select" name="is_active">';
        echo '<option value="1"' . (($this->fields['is_active'] ?? 1) == 1 ? ' selected' : '') . '>' . __('Yes') . '</option>';
        echo '<option value="0"' . (($this->fields['is_active'] ?? 1) == 0 ? ' selected' : '') . '>' . __('No') . '</option>';
        echo '</select>';
        echo '</div>';

        // ── Délai de notification : valeur + unité (heures / jours / semaines) ──
        echo '<div class="col-md-4">';
        echo '<label class="form-label">' . __('Notification delay', 'checklist') . '</label>';
        echo '<div class="input-group">';
        echo '<input type="number" class="form-control" name="notification_delay_hours" min="0" value="' . (int) ($this->fields['notification_delay_hours'] ?? 0) . '">';
        echo '<select class="form-select" name="notification_delay_unit" style="max-width:130px">';
        $cur_unit = $this->fields['notification_delay_unit'] ?? 'hours';
        foreach (PluginChecklistCronTask::getUnitLabels() as $uval => $ulabel) {
            echo '<option value="' . $uval . '"' . ($cur_unit === $uval ? ' selected' : '') . '>' . htmlspecialchars($ulabel) . '</option>';
        }
        echo '</select>';
        echo '</div>';
        echo '<small class="text-muted">' . __('0 = disabled', 'checklist') . '</small>';
        echo '</div>';

        // ── Entité + récursivité ───────────────────────────────────────────────
        // Éditables UNIQUEMENT à la création : c'est là que
        // `check(-1, CREATE, $_POST)` (front/template.form.php) valide l'entité
        // choisie. Sur le formulaire d'édition, ces deux champs sont retirés du
        // POST avant update() — l'entité d'un template n'est pas déplaçable par
        // ce formulaire (voir le unset() dans template.form.php) : les rendre
        // éditables en édition serait un contrôle mensonger (l'utilisateur les
        // change et rien ne bouge). On ne les affiche donc que si $is_new.
        if ($is_new) {
            echo '<div class="col-md-6">';
            echo '<label class="form-label">' . Entity::getTypeName(1) . '</label>';
            Entity::dropdown([
                'name'   => 'entities_id',
                'value'  => $this->fields['entities_id'] ?? Session::getActiveEntity(),
                'entity' => $_SESSION['glpiactiveentities'] ?? [],
            ]);
            echo '</div>';

            echo '<div class="col-md-6 d-flex align-items-center">';
            echo '<div class="form-check mt-4">';
            echo '<input class="form-check-input" type="checkbox" name="is_recursive" value="1" id="tpl_recursive"' . (($this->fields['is_recursive'] ?? 0) ? ' checked' : '') . '>';
            echo '<label class="form-check-label" for="tpl_recursive">' . __('Child entities') . '</label>';
            echo '</div>';
            echo '</div>';
        }

        // ── Blocage de la résolution / clôture ─────────────────────────────────
        // Recopié sur chaque checklist créée depuis ce template : les hooks de
        // veto interrogent alors la checklist, jamais le template.
        echo '<div class="col-md-6">';
        echo '<label class="form-label">' . __('Block solving/closing while tasks remain', 'checklist') . '</label>';
        Dropdown::showYesNo('is_blocking', $this->fields['is_blocking'] ?? 0);
        echo '<small class="text-muted d-block">' . __('Applies to checklists created from this template. Ad-hoc checklists never block.', 'checklist') . '</small>';
        echo '</div>';

        echo '<div class="col-12">';
        echo '<label class="form-label">' . __('Comment') . '</label>';
        echo '<textarea class="form-control" name="comment" rows="2">' . htmlspecialchars($this->fields['comment'] ?? '') . '</textarea>';
        echo '</div>';

        echo '</div></div>'; // card-body / card

        // ── Surcharges de notification propres au template ─────────────────────
        // Chaque liste propose la sentinelle « inherit » (valeur par défaut en
        // base) : tant qu'elle est sélectionnée, PluginChecklistConfig::resolve()
        // retombe sur le réglage global.
        $inherit = PluginChecklistConfig::INHERIT;

        $yesno_choices = [
            $inherit => __('Inherit global setting', 'checklist'),
            '1'      => __('Yes'),
            '0'      => __('No'),
        ];
        $privacy_choices = [
            $inherit  => __('Inherit global setting', 'checklist'),
            'glpi'    => __('As in GLPI', 'checklist'),
            'public'  => __('Public', 'checklist'),
            'private' => __('Private', 'checklist'),
        ];

        $lbl_followup = __('Add a followup to the ticket', 'checklist');
        $lbl_privacy  = __('Followup visibility', 'checklist');
        $lbl_notify   = __('Send a notification', 'checklist');

        echo '<div class="card mb-3">';
        echo '<div class="card-header"><strong>' . __('Notifications', 'checklist') . '</strong></div>';
        echo '<div class="card-body row g-3">';

        echo '<div class="col-12"><h4 class="mb-0">' . __('When a checklist item is completed', 'checklist') . '</h4></div>';

        echo '<div class="col-md-4">';
        echo '<label class="form-label">' . $lbl_followup . '</label>';
        Dropdown::showFromArray('followup_on_item_done', $yesno_choices, [
            'value' => $this->fields['followup_on_item_done'] ?? $inherit,
        ]);
        echo '</div>';

        echo '<div class="col-md-4">';
        echo '<label class="form-label">' . $lbl_privacy . '</label>';
        Dropdown::showFromArray('followup_privacy', $privacy_choices, [
            'value' => $this->fields['followup_privacy'] ?? $inherit,
        ]);
        echo '</div>';

        echo '<div class="col-md-4">';
        echo '<label class="form-label">' . $lbl_notify . '</label>';
        Dropdown::showFromArray('notify_on_item_done', $yesno_choices, [
            'value' => $this->fields['notify_on_item_done'] ?? $inherit,
        ]);
        echo '</div>';

        echo '<div class="col-12"><h4 class="mb-0">' . __('When a checklist task is overdue', 'checklist') . '</h4></div>';

        echo '<div class="col-md-4">';
        echo '<label class="form-label">' . $lbl_followup . '</label>';
        Dropdown::showFromArray('followup_on_overdue', $yesno_choices, [
            'value' => $this->fields['followup_on_overdue'] ?? $inherit,
        ]);
        echo '</div>';

        echo '<div class="col-md-4">';
        echo '<label class="form-label">' . $lbl_privacy . '</label>';
        Dropdown::showFromArray('overdue_privacy', $privacy_choices, [
            'value' => $this->fields['overdue_privacy'] ?? $inherit,
        ]);
        echo '</div>';

        echo '<div class="col-md-4">';
        echo '<label class="form-label">' . $lbl_notify . '</label>';
        Dropdown::showFromArray('notify_on_overdue', $yesno_choices, [
            'value' => $this->fields['notify_on_overdue'] ?? $inherit,
        ]);
        echo '</div>';

        echo '</div></div>'; // card-body / card

        // ── Boutons principaux ─────────────────────────────────────────────────
        echo '<div class="d-flex gap-2 mb-4">';
        if ($is_new) {
            echo '<button type="submit" name="add" class="btn btn-primary"><i class="fas fa-save me-1"></i>' . __('Add') . '</button>';
        } else {
            echo '<button type="submit" name="update" class="btn btn-primary"><i class="fas fa-save me-1"></i>' . __('Save') . '</button>';
            // The confirmation text goes in an attribute, HTML-escaped, and is
            // read by the delegated [data-cl-confirm] handler in checklist.js.
            // Inline confirm('…') broke outright under any locale whose
            // translation contains an apostrophe (fr_FR: "l'élément").
            echo '<button type="submit" name="purge" class="btn btn-danger ms-auto"';
            echo ' data-cl-confirm="' . htmlspecialchars(__('Delete this template?', 'checklist')) . '">';
            echo '<i class="fas fa-trash me-1"></i>' . __('Delete') . '</button>';
        }
        echo '</div>';
        echo '</form>';

        // ── Tâches du template ─────────────────────────────────────────────────
        if (!$is_new) {
            $items = PluginChecklistTemplateItem::getForTemplate($ID);
            echo '<div class="card">';
            echo '<div class="card-header d-flex justify-content-between"><strong>' . __('Tasks', 'checklist') . '</strong>';
            echo '<span class="badge bg-secondary">' . count($items) . '</span></div>';
            echo '<div class="card-body p-0">';

            if (!empty($items)) {
                $ajax_reorder = $web_dir . '/ajax/reorder_template_items.php';
                echo '<table class="table table-sm table-hover mb-0">';
                echo '<thead><tr>';
                echo '<th style="width:30px"></th><th style="width:40px">#</th>';
                echo '<th>' . __('Name') . '</th><th>' . __('Description') . '</th>';
                echo '<th>' . __('Exceptional', 'checklist') . '</th><th style="width:50px"></th>';
                echo '</tr></thead>';
                echo '<tbody id="cl-tpl-sortable" data-template-id="' . $ID . '" data-ajax-url="' . htmlspecialchars($ajax_reorder) . '">';

                foreach ($items as $it) {
                    echo '<tr data-id="' . (int) $it['id'] . '">';
                    echo '<td class="text-center text-muted cl-tpl-handle" style="cursor:grab"><i class="fas fa-grip-vertical"></i></td>';
                    echo '<td class="cl-tpl-rank text-muted">' . (int) $it['rank'] . '</td>';
                    echo '<td>' . htmlspecialchars($it['name']) . '</td>';
                    echo '<td>' . htmlspecialchars($it['description'] ?? '') . '</td>';
                    echo '<td>' . ($it['is_exceptional'] ? '<span class="badge bg-warning text-dark">⚠ EXC</span>' : '') . '</td>';
                    echo '<td>';
                    echo '<form method="POST" action="' . htmlspecialchars($web_dir) . '/front/template.form.php" class="d-inline">';
                    echo Html::hidden('id', ['value' => $it['id']]);
                    echo Html::hidden('template_id', ['value' => $ID]);
                    echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
                    echo '<button type="submit" name="delete_item" class="btn btn-sm btn-outline-danger"';
                    echo ' data-cl-confirm="' . htmlspecialchars(__('Delete?', 'checklist')) . '">';
                    echo '<i class="fas fa-times"></i></button>';
                    echo '</form>';
                    echo '</td>';
                    echo '</tr>';
                }

                echo '</tbody></table>';

                // SortableJS + sauvegarde AJAX de l'ordre
                self::renderReorderScript();
            } else {
                echo '<p class="text-muted text-center py-3 mb-0">' . __('No task yet.', 'checklist') . '</p>';
            }

            echo '</div>'; // card-body

            // Formulaire ajout de tâche
            echo '<div class="card-footer">';
            echo '<form method="POST" action="' . htmlspecialchars($web_dir) . '/front/template.form.php" class="row g-2 align-items-end">';
            echo Html::hidden('template_id', ['value' => $ID]);
            echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);

            echo '<div class="col-md-4"><label class="form-label">' . __('Task name', 'checklist') . ' *</label>';
            echo '<input type="text" class="form-control form-control-sm" name="name" required></div>';

            echo '<div class="col-md-4"><label class="form-label">' . __('Description') . '</label>';
            echo '<input type="text" class="form-control form-control-sm" name="description"></div>';

            echo '<div class="col-md-2"><label class="form-label">' . __('Rank', 'checklist') . '</label>';
            echo '<input type="number" class="form-control form-control-sm" name="rank" value="' . (count($items) + 1) . '" min="0"></div>';

            echo '<div class="col-md-1"><div class="form-check mt-4"><input class="form-check-input" type="checkbox" name="is_exceptional" value="1" id="is_exc">';
            echo '<label class="form-check-label" for="is_exc">EXC</label></div></div>';

            echo '<div class="col-md-1"><button type="submit" name="add_item" class="btn btn-success btn-sm w-100"><i class="fas fa-plus"></i></button></div>';

            echo '</form>';
            echo '</div>'; // card-footer
            echo '</div>'; // card
        }

        return true;
    }

    /**
     * SortableJS + sauvegarde AJAX de l'ordre des tâches du template.
     */
    private static function renderReorderScript(): void
    {
        // Page token (header-validated with preserve_token) — not standalone,
        // to avoid churning GLPI's CSRF token pool.
        $csrf = Session::getNewCSRFToken();
        $sortable_url = plugin_checklist_web_dir() . '/js/Sortable.min.js';

        echo '<script>
        (function(){
            var tbody=document.getElementById("cl-tpl-sortable");
            if(!tbody||tbody._init) return; tbody._init=true;
            var sortableUrl=' . json_encode($sortable_url) . ';
            var csrf=' . json_encode($csrf) . ';

            function loadSortable(cb){
                if(typeof Sortable!=="undefined"){cb();return;}
                var s=document.createElement("script");
                s.src=sortableUrl;
                s.onload=cb; document.head.appendChild(s);
            }

            loadSortable(function(){
                Sortable.create(tbody,{
                    handle:".cl-tpl-handle",
                    animation:150,
                    ghostClass:"table-active",
                    onEnd:function(){
                        var ids=[].slice.call(tbody.querySelectorAll("tr")).map(function(r){return r.dataset.id;});
                        // Met à jour l affichage des rangs immédiatement
                        [].slice.call(tbody.querySelectorAll("tr")).forEach(function(r,i){
                            var c=r.querySelector(".cl-tpl-rank"); if(c) c.textContent=i+1;
                        });
                        var fd=new FormData();
                        fd.append("template_id",tbody.dataset.templateId);
                        ids.forEach(function(id){fd.append("ids[]",id);});
                        fetch(tbody.dataset.ajaxUrl,{
                            method:"POST",body:fd,
                            headers:{"X-Glpi-Csrf-Token":csrf,"X-Requested-With":"XMLHttpRequest"}
                        });
                    }
                });
            });
        })();
        </script>';
    }
}
