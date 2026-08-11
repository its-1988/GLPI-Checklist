<?php
/**
 * PluginChecklistChecklist — Checklist instanciée + intégration onglet GLPI
 */

declare(strict_types=1);

class PluginChecklistChecklist extends CommonDBChild
{
    // Parent polymorphe (Ticket, Computer…) : CommonDBChild lit le nom de la
    // classe dans la colonne `itemtype` et l'id dans `items_id`. C'est ce qui
    // donne nativement le contrôle de droits/entité sur l'élément porteur —
    // aucun garde-fou maison n'est nécessaire.
    public static $itemtype = 'itemtype';
    public static $items_id = 'items_id';

    // Les créations/suppressions de checklists atterrissent dans l'onglet
    // « Historique » de l'élément parent.
    public $dohistory = true;

    public static $rightname = 'plugin_checklist_checklist';

    public static function getIcon(): string
    {
        return 'fas fa-clipboard-check';
    }

    public static function getTypeName($nb = 0): string
    {
        return _n('Checklist', 'Checklists', $nb, 'checklist');
    }

    public static function getTable($classname = null): string
    {
        return 'glpi_plugin_checklist_checklists';
    }

    // ═══════════════════════════════════════════════════════════════════════════
    //  OPTIONS DE RECHERCHE NATIVES
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * Rend les checklists cherchables, triables, exportables et accessibles via
     * l'API REST.
     *
     * `searchOptions()` est `final` dans CommonDBTM (11.0.8, src/CommonDBTM.php
     * :3816) : seule `rawSearchOptions()` se surcharge. Le retour est une LISTE
     * PLATE — l'`id` est un membre de chaque entrée, pas la clé du tableau —, et
     * `id` comme `name` sont obligatoires : leur absence lève une exception qui
     * emporte toute la page de recherche de l'itemtype (:3831).
     *
     * L'objet est instancié VIDE pour cet appel (:3825) : ne jamais lire
     * `$this->fields` ici.
     */
    public function rawSearchOptions()
    {
        // La base ajoute déjà l'en-tête `common` puis, dès que la table porte une
        // colonne `name`, une entrée `id => 1` (:3892). Redéclarer cet id sans
        // retirer l'entrée par défaut produirait un DOUBLON, signalé par un
        // E_USER_WARNING dans searchOptions() (:3853) — pas une surcharge. On
        // filtre donc l'id 1 pour le redéfinir explicitement, ce qui rend le jeu
        // d'options déterministe au lieu de dépendre de la sonde `isField()`.
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
            'field'         => 'percent_done',
            'name'          => __('Percent done', 'checklist'),
            'datatype'      => 'number',
            'unit'          => '%',
            'massiveaction' => false,
        ];

        $tab[] = [
            'id'            => '3',
            'table'         => self::getTable(),
            'field'         => 'status',
            'name'          => __('Status', 'checklist'),
            'datatype'      => 'string',
            'massiveaction' => false,
        ];

        $tab[] = [
            'id'            => '4',
            'table'         => self::getTable(),
            'field'         => 'is_blocking',
            'name'          => __('Blocking', 'checklist'),
            'datatype'      => 'bool',
            'massiveaction' => false,
        ];

        $tab[] = [
            'id'            => '5',
            'table'         => self::getTable(),
            'field'         => 'items_total',
            'name'          => __('Total tasks', 'checklist'),
            'datatype'      => 'number',
            'massiveaction' => false,
        ];

        $tab[] = [
            'id'            => '6',
            'table'         => self::getTable(),
            'field'         => 'items_done',
            'name'          => __('Completed tasks', 'checklist'),
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

        // `datatype => dropdown` fait JOINDRE la table étrangère par le moteur de
        // recherche : le `field` doit nommer une colonne de la table des entités,
        // pas la clé étrangère locale `entities_id` (qui n'afficherait que des
        // ids bruts et casserait le tri). C'est l'idiome du cœur
        // (src/Computer.php:650), servi ici par l'accesseur natif.
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

    // ═══════════════════════════════════════════════════════════════════════════
    //  INTÉGRATION ONGLET GLPI
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * Nom de l'onglet — NON static en GLPI 11 (instance method dans CommonGLPI)
     */
    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        // Without the checklist READ right the very existence and COUNT of
        // checklists must not leak as a tab badge. An empty return makes GLPI
        // omit the tab entirely. Mirrors the READ gate on
        // plugin_checklist_timeline_items(). Out of the box the right is 0 for
        // every non-config profile, so this hides the count from most users.
        if (!Session::haveRight('plugin_checklist_checklist', READ)) {
            return '';
        }

        $count = countElementsInTable(static::getTable(), [
            'itemtype' => $item->getType(),
            'items_id' => $item->getID(),
        ]);
        return self::createTabEntry(__('Checklists', 'checklist'), $count, null, self::getIcon());
    }

    /**
     * Contenu de l'onglet — static en GLPI 11 (static dans CommonGLPI)
     */
    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0): bool
    {
        self::showForItem($item);
        return true;
    }

    // ═══════════════════════════════════════════════════════════════════════════
    //  BOOTSTRAP CLIENT — valeurs serveur passées en JSON à public/js/checklist.js
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * Emits the small JSON object the client script reads (CSRF token + local
     * SortableJS url). The behaviour itself lives in public/js/checklist.js,
     * registered through the ADD_JAVASCRIPT hook.
     */
    private static function emitClientBootstrap(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        // Page token (sent via X-Glpi-Csrf-Token header, kernel preserves it):
        // NOT standalone — standalone mints a fresh token per ticket render and
        // churns GLPI's CSRF pool (evicts other tokens → CSRF errors elsewhere).
        echo '<script>window.PLUGIN_CHECKLIST = '
            . json_encode(
                [
                    'csrf_token'   => Session::getNewCSRFToken(),
                    'sortable_url' => plugin_checklist_web_dir() . '/js/Sortable.min.js',
                ],
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP
            )
            . ';</script>';
    }


    // ═══════════════════════════════════════════════════════════════════════════
    //  AFFICHAGE PRINCIPAL
    // ═══════════════════════════════════════════════════════════════════════════

    public static function showForItem(CommonGLPI $item): void
    {
        global $DB;

        // Defence in depth: the tab content renderer refuses to emit anything —
        // not even the client bootstrap or the empty container — without the
        // checklist READ right. getTabNameForItem() already hides the tab, but a
        // direct tab-content request must be gated here too.
        if (!Session::haveRight('plugin_checklist_checklist', READ)) {
            return;
        }

        self::emitClientBootstrap(); // CSRF token + SortableJS url for public/js/checklist.js

        $itemtype   = $item->getType();
        $items_id   = $item->getID();
        $plugin_url = plugin_checklist_web_dir();
        $ajax_url   = $plugin_url . '/ajax';
        $parent_entity = self::getParentEntity($itemtype, $items_id);

        // Checklists existantes
        $checklists = [];
        foreach ($DB->request([
            'FROM'  => static::getTable(),
            'WHERE' => ['itemtype' => $itemtype, 'items_id' => $items_id],
            'ORDER' => ['date_creation ASC'],
        ]) as $row) {
            $checklists[] = $row;
        }

        $templates = PluginChecklistTemplate::getVisibleForEntity($parent_entity, true);

        // ── Container principal ────────────────────────────────────────────────
        echo '<div class="cl-wrap" id="cl-container">';
        echo '<div class="cl-topbar">';
        echo '<div class="cl-topbar-left">';
        echo '<span class="cl-topbar-title"><i class="fas fa-tasks"></i> ' . __('Checklists', 'checklist') . '</span>';
        // Same string the client rebuilds after a create/delete (see checklist.js)
        echo '<span class="cl-topbar-sub">'
            . sprintf(_n('%d checklist', '%d checklists', count($checklists), 'checklist'), count($checklists))
            . '</span>';
        echo '</div>';
        self::renderCreateModal($itemtype, $items_id, $templates, $ajax_url);
        echo '</div>'; // cl-topbar

        if (empty($checklists)) {
            self::renderEmptyState();
        }

        // #cl-list is emitted UNCONDITIONALLY, even with nothing in it.
        //
        // It used to appear only in the non-empty branch, which forced the
        // client to CREATE it when the first checklist arrived and REMOVE it
        // when the last one left — a container whose existence was a second
        // piece of state the browser had to keep in step with the server. Now
        // it is simply always there and the client only ever inserts into it.
        // (With checklists present the emitted bytes are unchanged; the only
        // difference is an empty <div id="cl-list"></div> on an empty tab.)
        echo '<div id="cl-list">';
        foreach ($checklists as $cl) {
            self::renderCard($cl, $ajax_url);
        }
        echo '</div>';

        // Lien gestion des templates
        $tpl_url = plugin_checklist_web_dir() . '/front/template.php';
        echo '<a class="cl-footer-link" href="' . htmlspecialchars($tpl_url) . '" target="_blank">';
        echo '<i class="fas fa-cog me-1"></i>' . __('Manage checklist templates', 'checklist') . '</a>';

        echo '</div>'; // .cl-wrap
    }

    // ─── Modal de création ─────────────────────────────────────────────────────

    private static function renderCreateModal(string $itemtype, int $items_id, array $templates, string $ajax_url): void
    {
        echo '<button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#clCreateModal">';
        echo '<i class="fas fa-plus me-1"></i>' . __('New checklist', 'checklist') . '</button>';

        echo '
        <div class="modal fade" id="clCreateModal" tabindex="-1" aria-hidden="true">
          <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
              <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-plus-circle me-2"></i>' . __('New checklist', 'checklist') . '</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
              </div>
              <div class="modal-body">
                <form id="cl-create-form">
                  <input type="hidden" name="itemtype" value="' . htmlspecialchars($itemtype) . '">
                  <input type="hidden" name="items_id" value="' . $items_id . '">
                  <div class="mb-3">
                    <label class="form-label fw-semibold">' . __('Name') . ' <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" name="name" required
                           placeholder="' . htmlspecialchars(__('e.g. Onboarding SIRH', 'checklist')) . '">
                  </div>
                  <div class="mb-2">
                    <label class="form-label fw-semibold">' . __('From template', 'checklist') . ' <span class="text-muted fw-normal">(' . __('optional', 'checklist') . ')</span></label>
                    <div class="cl-tpl-picker" id="cl-tpl-picker">
                      <input type="hidden" name="templates_id" value="0">
                      <button type="button" class="form-select text-start cl-tpl-toggle" id="cl-tpl-toggle">— ' . __('Empty checklist', 'checklist') . ' —</button>
                      <div class="cl-tpl-menu" id="cl-tpl-menu">
                        <div class="cl-tpl-search-wrap">
                          <i class="fas fa-search cl-tpl-search-ico"></i>
                          <input type="text" class="form-control form-control-sm cl-tpl-filter" id="cl-tpl-filter" placeholder="' . htmlspecialchars(__('Search a template…', 'checklist')) . '" autocomplete="off">
                        </div>
                        <ul class="cl-tpl-list" id="cl-tpl-list">
                          <li class="cl-tpl-opt cl-tpl-active" data-id="0" data-name="— ' . htmlspecialchars(__('Empty checklist', 'checklist')) . ' —"><i class="far fa-file me-2 text-muted"></i>— ' . __('Empty checklist', 'checklist') . ' —</li>';

        foreach ($templates as $tpl) {
            $tname = htmlspecialchars($tpl['name']);
            echo '<li class="cl-tpl-opt" data-id="' . (int) $tpl['id'] . '" data-name="' . $tname . '"><i class="fas fa-clipboard-list me-2 text-primary"></i>' . $tname . '</li>';
        }

        echo '            </ul>
                        <div class="cl-tpl-noresult" id="cl-tpl-noresult" style="display:none">' . __('No matching template', 'checklist') . '</div>
                      </div>
                    </div>
                  </div>
                </form>
              </div>
              <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">' . __('Cancel') . '</button>
                <button type="button" class="btn btn-primary" id="cl-create-submit"
                        data-ajax-url="' . htmlspecialchars($ajax_url . '/checklist.php') . '">
                  <i class="fas fa-save me-1"></i>' . __('Create', 'checklist') . '
                </button>
              </div>
            </div>
          </div>
        </div>';
    }

    // ═══════════════════════════════════════════════════════════════════════════
    //  RENDER FACADES — the ONLY place card/item markup exists
    // ═══════════════════════════════════════════════════════════════════════════
    //
    // Until v2.1.0 this markup lived in THREE hand-maintained copies: the echo
    // bodies below, clBuildCardHtml()/clBuildItemHtml() in public/js/checklist.js
    // (used after an ajax create/add) and a fourth-wall repetition inside
    // hook.php's timeline entry. They drifted three times before anyone noticed:
    //
    //   1. floor() here vs Math.round() there — 999/1000 read 100 % on the card
    //      and 99 % in the database, the search column and the timeline;
    //   2. the JS card stamped the template's task count into the column badge
    //      but emitted an EMPTY <ul>, so a 5-task template drew a badge of 5
    //      over an empty column until the user reloaded;
    //   3. the progress bar was 100px here, 120px in the JS and 110px in the
    //      timeline.
    //
    // Every one of those is the same bug: two pieces of code claiming to draw
    // the same thing. So the server draws it, once, and the ajax endpoints hand
    // the result to the client, which does nothing but insert it.
    //
    // WHY ob_start() RATHER THAN A REWRITE. The bodies below echo, and they are
    // the bytes that shipped. Rewriting them into string concatenation to make
    // them return would have changed thousands of characters of markup by hand
    // in the same commit that claims to change none of it — and the golden
    // harness would have had no way to tell an intended change from a typo.
    // A returning facade over an UNCHANGED body keeps the golden diff empty and
    // makes that emptiness meaningful.

    /** The card as a string, for ajax/checklist.php?create. */
    public static function getCardHtml(array $cl, string $ajax_url): string
    {
        ob_start();
        self::renderCard($cl, $ajax_url);
        return (string) ob_get_clean();
    }

    /**
     * The card for a checklist id, read back from the database.
     *
     * The endpoint could assemble a row from the values it just posted, but
     * reading it back is the point: what the client inserts is then literally
     * what a page reload would draw, denormalised counters and all.
     */
    public static function getCardHtmlById(int $cl_id, string $ajax_url): string
    {
        $cl = new self();
        if (!$cl->getFromDB($cl_id)) {
            return '';
        }
        return self::getCardHtml($cl->fields, $ajax_url);
    }

    /** One task <li> as a string, for ajax/add_item.php. */
    public static function getItemHtml(array $item, string $ajax_url): string
    {
        ob_start();
        self::renderItem($item, $ajax_url);
        return (string) ob_get_clean();
    }

    /**
     * The "no checklist yet" placeholder, for ajax/checklist.php?delete.
     *
     * The client used to rebuild this block with innerHTML when the last
     * checklist went away — a fifth copy of markup, for four lines of it.
     */
    public static function getEmptyStateHtml(): string
    {
        ob_start();
        self::renderEmptyState();
        return (string) ob_get_clean();
    }

    /** Shared with hook.php's timeline entry. */
    public static function getIdentityHtml(string $name, int $done, int $total, ?string $label = null): string
    {
        ob_start();
        self::renderIdentity($name, $done, $total, $label);
        return (string) ob_get_clean();
    }

    /** Shared with hook.php's timeline entry. */
    public static function getProgressHtml(int $pct, bool $full): string
    {
        ob_start();
        self::renderProgress($pct, $full);
        return (string) ob_get_clean();
    }

    private static function renderEmptyState(): void
    {
        echo '<div class="cl-empty">';
        echo '<i class="fas fa-clipboard"></i>';
        echo __('No checklist yet. Click «+ New checklist» to start.', 'checklist');
        echo '</div>';
    }

    /**
     * Icon + name + counter — the identity of a checklist, wherever it is shown.
     *
     * $label is the only thing the two call sites disagree about: the timeline
     * spells the type out ("Checklist") because a timeline mixes followups,
     * tasks, solutions and validations and an unlabelled row is guesswork, while
     * the tab is already inside a panel titled "Checklists". Passing null emits
     * exactly the bytes renderCard emitted before this was extracted.
     */
    private static function renderIdentity(string $name, int $done, int $total, ?string $label = null): void
    {
        echo '<i class="fas fa-clipboard-list cl-card-icon"></i>';
        if ($label !== null) {
            echo '<span class="cl-card-count">' . htmlspecialchars($label) . '</span>';
        }
        echo '<span class="cl-card-title">' . htmlspecialchars($name) . '</span>';
        echo '<span class="cl-card-count">' . $done . '/' . $total . '</span>';
    }

    /**
     * The progress bar and its badge.
     *
     * $full rather than `$pct === 100`: an empty checklist is 0 % and is NOT
     * complete, and a checklist at 999/1000 floors to 99 % precisely so it
     * cannot claim to be finished. Both call sites derive $full the same way
     * ($total > 0 && $done >= $total) from the denormalised columns.
     *
     * The width is the ONLY inline style left in here, and it is a value, not a
     * style decision: the bar's size lives in .cl-progress (public/css).
     */
    private static function renderProgress(int $pct, bool $full): void
    {
        $tone = $full ? 'bg-success' : 'bg-primary';
        echo '<div class="progress cl-progress"><div class="progress-bar ' . $tone . '" style="width:' . $pct . '%"></div></div>';
        echo '<span class="badge ' . $tone . '">' . $pct . '%</span>';
    }

    // ─── Carte checklist (résumé + kanban dépliable) ───────────────────────────

    private static function renderCard(array $cl, string $ajax_url): void
    {
        $cl_id = (int) $cl['id'];

        // Read the DENORMALISED counters (added in CL2-T2, kept current by the
        // single recompute point from CL2-T3) instead of recounting here.
        //
        // This used to fire two COUNT queries per card and derive the figure
        // with round(). Both were wrong, in different ways:
        //
        //   - cost: 2 queries × N checklists on every tab render, for numbers
        //     the row already carries (showForItem SELECTs the full row);
        //   - correctness: the stored `percent_done` is computed with FLOOR
        //     (hook.php, plugin_checklist_backfill_progress + the recompute),
        //     so round() here made the card disagree with the value shown in
        //     the search column, the notification and the timeline entry —
        //     999/1000 read 100% on the card and 99% everywhere else.
        //
        // The card, the ITIL search column and the timeline entry now all quote
        // the same stored number.
        $done  = (int) ($cl['items_done'] ?? 0);
        $total = (int) ($cl['items_total'] ?? 0);
        $pct   = (int) ($cl['percent_done'] ?? 0);
        $full  = $total > 0 && $done >= $total;

        echo '<div class="cl-card" id="cl-card-' . $cl_id . '">';

        // En-tête carte
        // Behaviour travels in data-* and is picked up by the delegated
        // dispatcher in checklist.js (see the block comment on renderItem).
        echo '<div class="cl-card-hdr" data-cl-toggle-kanban="' . $cl_id . '" role="button">';
        echo '<div class="cl-card-hdr-left">';
        self::renderIdentity((string) $cl['name'], $done, $total);
        echo '</div>';
        echo '<div class="cl-card-hdr-right">';
        self::renderProgress($pct, $full);
        // .cl-card-del, not an inline style. The inline one was the last thing
        // in the plugin forcing an !important: it outranked every selector, so
        // the :hover fade in checklist.css could only beat it with the flag.
        // Two ordinary class rules now, and no flag.
        echo '<button type="button" class="cl-card-del" title="' . htmlspecialchars(__('Delete')) . '"';
        // The former `event.stopPropagation()` — which stopped a delete click
        // from also toggling the kanban it sits inside — is now implicit: the
        // dispatcher resolves the INNERMOST action element, so this button
        // wins over the .cl-card-hdr wrapping it and the header never sees it.
        echo ' data-cl-delete-checklist="' . $cl_id . '"';
        echo ' data-cl-url="' . htmlspecialchars($ajax_url . '/checklist.php') . '">';
        echo '<i class="fas fa-trash-alt"></i></button>';
        echo '<i class="fas fa-chevron-down cl-chevron" id="cl-chev-' . $cl_id . '"></i>';
        echo '</div>';
        echo '</div>'; // cl-card-hdr

        // Vue Kanban (masquée)
        echo '<div class="cl-kanban-wrap" id="cl-kanban-' . $cl_id . '" style="display:none"';
        echo ' data-cl-id="' . $cl_id . '" data-ajax-url="' . htmlspecialchars($ajax_url) . '">';
        self::renderKanban($cl_id, $ajax_url);
        echo '</div>';

        echo '</div>'; // .cl-card
    }

    // ─── Vue Kanban (2 colonnes) ───────────────────────────────────────────────

    public static function renderKanban(int $cl_id, string $ajax_url): void
    {
        $items = PluginChecklistItem::getForChecklist($cl_id);

        echo '<div class="cl-board">';

        $cols = ['todo' => ['label' => __('To do', 'checklist'), 'icon' => 'far fa-circle'], 'done' => ['label' => __('Done', 'checklist'), 'icon' => 'fas fa-check-circle']];
        foreach ($cols as $status => $cfg) {
            echo '<div class="cl-col cl-col-' . $status . '">';
            echo '<div class="cl-col-hdr">';
            echo '<span><i class="' . $cfg['icon'] . ' me-1"></i>' . $cfg['label'] . '</span>';
            echo '<span class="badge ' . ($status === 'done' ? 'bg-success' : 'bg-primary') . ' rounded-pill">' . count($items[$status]) . '</span>';
            echo '</div>';
            echo '<ul class="cl-sort" id="cl-' . $status . '-' . $cl_id . '" data-status="' . $status . '" data-cl-id="' . $cl_id . '">';
            foreach ($items[$status] as $it) {
                self::renderItem($it, $ajax_url);
            }
            echo '</ul>';
            echo '</div>';
        }

        echo '</div>'; // cl-board

        // Toolbar — plus de panneau d'historique maison : les checklists sont
        // des CommonDBChild, GLPI journalise tout dans l'onglet « Historique »
        // de l'élément parent.
        echo '<div class="cl-toolbar">';
        echo '<button class="btn btn-sm btn-outline-warning"';
        echo ' data-cl-add-exc="' . $cl_id . '"';
        echo ' data-cl-add-url="' . htmlspecialchars($ajax_url . '/add_item.php') . '"';
        echo ' data-cl-move-url="' . htmlspecialchars($ajax_url . '/move_item.php') . '">';
        echo '<i class="fas fa-exclamation-triangle me-1"></i>' . __('Add exceptional task', 'checklist') . '</button>';
        echo '</div>';
    }

    // ─── Rendu d'un item (li) ─────────────────────────────────────────────────

    /*
     * NO INLINE HANDLERS ANYWHERE IN THIS FILE — deliberate, and the reason is
     * not tidiness.
     *
     * An onclick value is JavaScript source living inside an HTML attribute, so
     * every interpolated value has to be HTML-safe AND JS-safe simultaneously.
     * When that fails the browser does not install a broken handler, it
     * installs NOTHING: template.class.php shipped a delete button whose
     * confirm() text was a translation, and under a locale whose translation
     * contains an apostrophe the dialog never appeared and the form submitted
     * unasked. These four sites interpolate an id and an ajax URL — safe today
     * only because those happen to contain no quote — so they were the same
     * bug waiting for a different input.
     *
     * Behaviour is therefore expressed as data-cl-* attributes and dispatched
     * by ONE delegated listener bound on `document` in checklist.js. `document`
     * is not a default, it is the requirement: SortableJS re-parents these <li>
     * nodes between the two <ul> columns, clToggleKanban() hides and shows the
     * wrapper, and a newly created card is injected as a whole subtree — a
     * listener bound to any of those containers would stop firing the moment
     * the node moved out of it, silently. `document` is the one ancestor none
     * of that can detach a node from. It is also what the two existing
     * precedents use ([data-clv-id] in checklist-validate.js, [data-cl-confirm]
     * in checklist.js).
     */
    private static function renderItem(array $item, string $ajax_url): void
    {
        $id  = (int) $item['id'];
        $exc = (bool) $item['is_exceptional'];

        echo '<li class="cl-item' . ($exc ? ' cl-exc' : '') . '"';
        echo ' id="cl-item-' . $id . '" data-id="' . $id . '"';
        echo ' data-cl-toggle-item="' . $id . '"';
        echo ' data-cl-move-url="' . htmlspecialchars($ajax_url . '/move_item.php') . '">';

        echo '<div class="cl-item-row">';
        echo '<span class="cl-drag-hdl"><i class="fas fa-grip-vertical"></i></span>';

        if ($exc) {
            echo '<span class="cl-badge-exc">' . htmlspecialchars(__('⚠ EXC', 'checklist')) . '</span>';
        }

        echo '<span class="cl-item-name">' . htmlspecialchars($item['name']) . '</span>';
        echo '</div>';

        if (!empty($item['description'])) {
            echo '<span class="cl-item-desc">' . htmlspecialchars($item['description']) . '</span>';
        }

        echo '</li>';
    }

    // ─── Fragments de la modale « valider une tâche » ──────────────────────────
    //
    // These four used to be string literals in public/js/checklist-validate.js,
    // assembled with a clvEsc() that — like checklist.js's clEsc() — did not
    // escape apostrophes. They are markup, so they belong where the rest of the
    // markup is. The shell and the two placeholders travel on the JSON bootstrap
    // (hook.php), the list on the ajax/get_todo_items.php response.

    public static function getValidateModalHtml(): string
    {
        return '<div class="modal fade" id="clvModal" tabindex="-1" aria-hidden="true">'
            . '<div class="modal-dialog modal-dialog-centered">'
            . '<div class="modal-content">'
            . '<div class="modal-header">'
            . '<h5 class="modal-title"><i class="ti ti-checks me-2"></i>'
            . htmlspecialchars(__('Validate checklist tasks', 'checklist')) . '</h5>'
            . '<button type="button" class="btn-close" data-bs-dismiss="modal"></button>'
            . '</div>'
            . '<div class="modal-body" id="clvBody"></div>'
            . '<div class="modal-footer">'
            . '<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">'
            . htmlspecialchars(__('Cancel')) . '</button>'
            . '<button type="button" class="btn btn-success" id="clvSubmit"><i class="ti ti-check me-1"></i>'
            . htmlspecialchars(__('Validate selected', 'checklist')) . '</button>'
            . '</div></div></div></div>';
    }

    /**
     * The modal body: one checkbox per open task, or the "nothing to do" state.
     *
     * @param array<int, array{id: mixed, name: mixed, cl_name?: mixed}> $items
     */
    public static function getValidateListHtml(array $items): string
    {
        if ($items === []) {
            return '<div class="text-center py-4 text-muted">'
                . '<i class="ti ti-circle-check fs-1 text-success d-block mb-2"></i>'
                . htmlspecialchars(__('All tasks are already done!', 'checklist'))
                . '</div>';
        }

        $h = '<div class="list-group">';
        foreach ($items as $it) {
            $h .= '<label class="list-group-item list-group-item-action d-flex align-items-start gap-2 py-2" style="cursor:pointer">';
            $h .= '<input type="checkbox" class="clvcb form-check-input mt-1" value="' . (int) $it['id'] . '">';
            $h .= '<div><div class="fw-semibold">' . htmlspecialchars((string) $it['name']) . '</div>';
            if (!empty($it['cl_name'])) {
                $h .= '<small class="text-muted"><i class="ti ti-clipboard-list me-1"></i>'
                    . htmlspecialchars((string) $it['cl_name']) . '</small>';
            }
            $h .= '</div></label>';
        }
        return $h . '</div>';
    }

    public static function getValidateLoadingHtml(): string
    {
        return '<div class="text-center py-3"><i class="fas fa-spinner fa-spin"></i> '
            . htmlspecialchars(__('Loading...')) . '</div>';
    }

    public static function getValidateErrorHtml(): string
    {
        return '<div class="text-danger text-center py-3">'
            . htmlspecialchars(__('Loading error.', 'checklist')) . '</div>';
    }

    // ═══════════════════════════════════════════════════════════════════════════
    //  ACTIONS CRUD
    // ═══════════════════════════════════════════════════════════════════════════

    public static function getParentEntity(string $itemtype, int $items_id): int
    {
        $entities_id = (int) Session::getActiveEntity();

        if ($items_id <= 0 || !class_exists($itemtype) || !is_subclass_of($itemtype, CommonDBTM::class)) {
            return $entities_id;
        }

        $parent = new $itemtype();
        if ($parent->getFromDB($items_id) && isset($parent->fields["entities_id"])) {
            $entities_id = (int) $parent->fields["entities_id"];
        }

        return $entities_id;
    }

    public static function createForItem(string $itemtype, int $items_id, string $name, int $templates_id = 0): int|false
    {
        global $DB;

        // L'entité de la checklist suit celle de son élément parent (pas la session)
        $entities_id = self::getParentEntity($itemtype, $items_id);

        if (!PluginChecklistTemplate::canUseTemplateForEntity($templates_id, $entities_id)) {
            return false;
        }

        // `is_blocking` est recopié depuis le template au moment de la création :
        // le veto de résolution/clôture n'a alors qu'une lecture indexée à faire
        // sur la checklist, sans jointure vers le template. Une checklist
        // ad-hoc (sans template) ne bloque JAMAIS — il n'existe aucun endroit où
        // l'utilisateur pourrait exprimer ce choix, et bloquer par défaut
        // transformerait une liste de pense-bêtes en obstacle à la clôture.
        $is_blocking = 0;
        if ($templates_id > 0) {
            $tpl_row = $DB->request([
                'FROM'  => PluginChecklistTemplate::getTable(),
                'WHERE' => ['id' => $templates_id],
            ])->current();
            $is_blocking = (int) ($tpl_row['is_blocking'] ?? 0);
        }

        // add() : GLPI horodate (date_creation/date_mod) et journalise la
        // création dans l'historique de l'élément parent.
        $cl_id = (new self())->add([
            'name'                          => $name,
            'itemtype'                      => $itemtype,
            'items_id'                      => $items_id,
            'plugin_checklist_templates_id' => $templates_id,
            'status'                        => 'open',
            'is_blocking'                   => $is_blocking,
            'users_id'                      => Session::getLoginUserID() ?: 0,
            'entities_id'                   => $entities_id,
        ]);

        if ($cl_id === false) {
            return false;
        }

        // Instanciation depuis le template — via le modèle, pas en SQL brut.
        if ($templates_id > 0) {
            foreach (PluginChecklistTemplateItem::getForTemplate($templates_id) as $tpi) {
                (new PluginChecklistItem())->add([
                    'plugin_checklist_checklists_id' => (int) $cl_id,
                    'name'                           => $tpi['name'],
                    'description'                    => $tpi['description'] ?? '',
                    'status'                         => 'todo',
                    'rank_todo'                      => (int) $tpi['rank'],
                    'rank_done'                      => 0,
                    'is_exceptional'                 => 0,
                    // Point de départ du délai de retard (lu par le CRON) :
                    // ce n'est pas date_creation, GLPI ne le pose pas.
                    'date_todo'                      => date('Y-m-d H:i:s'),
                    'users_id_creator'               => Session::getLoginUserID() ?: 0,
                ]);
            }
        }

        return (int) $cl_id;
    }

    // ═══════════════════════════════════════════════════════════════════════════
    //  ACTION DE MASSE — appliquer un template à une sélection
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * The form shown between "choose the action" and "run it".
     *
     * Base declaration: src/CommonDBTM.php:4010. The reference implementation is
     * src/Calendar.php:109-127 — render the inputs, echo the submit button named
     * `massiveaction`, return true. Returning false (the base behaviour) tells
     * core there is nothing to ask, and it would run the action straight away
     * with no template at all.
     *
     * getAction() hands back the BARE action name: core stripped the
     * `PluginChecklistChecklist:` prefix when it split the key
     * (src/MassiveAction.php:312-315). Comparing against a prefixed name is dead
     * code — core carries exactly that bug in src/Certificate.php:596.
     */
    public static function showMassiveActionsSubForm(MassiveAction $ma)
    {
        if ($ma->getAction() !== 'apply_template') {
            return parent::showMassiveActionsSubForm($ma);
        }

        // Session entity, not the entity of each selected item: the selection can
        // straddle entities, and this list is built once for the whole batch. A
        // template that turns out to be invisible from a given item's entity is
        // rejected per item later, by createForItem()'s canUseTemplateForEntity()
        // gate — so the worst case is a KO on that item, never a leak.
        $templates = [];
        foreach (PluginChecklistTemplate::getVisibleForEntity((int) Session::getActiveEntity(), true) as $tpl) {
            $templates[(int) $tpl['id']] = $tpl['name'];
        }

        Dropdown::showFromArray('plugin_checklist_templates_id', $templates, [
            'display_emptychoice' => true,
        ]);
        echo '<br><br>';
        echo Html::submit(_x('button', 'Post'), ['name' => 'massiveaction']);

        return true;
    }

    /**
     * Applies the chosen template to every selected item.
     *
     * Base declaration: src/CommonDBTM.php:4027. This is deliberately nothing
     * but a loop around createForItem(), which already owns entity resolution,
     * the canUseTemplateForEntity() gate, template instantiation and the
     * is_blocking mirror.
     *
     * What createForItem() does NOT own is the caller's right on the TARGET
     * item: it validates the template against the item's entity and nothing
     * else. Without the can() gate below, a technician could staple a checklist
     * onto tickets they are not allowed to touch — massive actions are reached
     * from a search result, and a search result is not an authorisation. Hence
     * ACTION_NORIGHT per item, exactly as Calendar.php:157-160 does.
     */
    public static function processMassiveActionsForOneItemtype(
        MassiveAction $ma,
        CommonDBTM $item,
        array $ids
    ) {
        if ($ma->getAction() !== 'apply_template') {
            parent::processMassiveActionsForOneItemtype($ma, $item, $ids);
            return;
        }

        $input   = $ma->getInput();
        $tpl_id  = (int) ($input['plugin_checklist_templates_id'] ?? 0);

        // Nothing chosen: say so and mark NOTHING done. Reporting the items as
        // processed would make the run look successful while creating nothing.
        if ($tpl_id <= 0) {
            $ma->addMessage(__('No template selected', 'checklist'));
            return;
        }

        // Resolved ONCE, outside the loop: a lookup per id would turn a
        // 40-ticket selection into 40 extra queries for a value that cannot
        // change between iterations.
        $template = new PluginChecklistTemplate();
        if (!$template->getFromDB($tpl_id)) {
            $ma->addMessage(__('No template selected', 'checklist'));
            return;
        }
        $name = (string) $template->fields['name'];

        $itemtype = $item->getType();

        foreach ($ids as $id) {
            $id = (int) $id;

            if (!$item->can($id, UPDATE)) {
                $ma->itemDone($itemtype, $id, MassiveAction::ACTION_NORIGHT);
                $ma->addMessage($item->getErrorMessage(ERROR_RIGHT));
                continue;
            }

            if (self::createForItem($itemtype, $id, $name, $tpl_id)) {
                $ma->itemDone($itemtype, $id, MassiveAction::ACTION_OK);
            } else {
                $ma->itemDone($itemtype, $id, MassiveAction::ACTION_KO);
                $ma->addMessage($item->getErrorMessage(ERROR_ON_ACTION));
            }
        }
    }

    // ═══════════════════════════════════════════════════════════════════════════
    //  PROGRESSION — point de recalcul UNIQUE
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * True while cleanDBonPurge() is cascading the task deletions. GLPI runs
     * that cascade BEFORE deleting the checklist row itself
     * (CommonDBTM::deleteFromDB), so without this guard every purged task would
     * recompute a row that is about to disappear — and the moment the last
     * « todo » task is gone the counters read done === total, which would
     * announce a completion for a checklist being deleted.
     */
    private static bool $purging = false;

    /**
     * Pure progress computation for one checklist.
     *
     * countElementsInTable() is the aggregate idiom this plugin already uses
     * everywhere (renderCard, ajax/checklist.php, template.class.php…). Two
     * indexed COUNTs on the `plugin_checklist_checklists_id` key are cheap, and
     * it avoids \QueryExpression, deprecated since GLPI 11.0.0.
     *
     * @return array{done:int,total:int,percent:int,complete:bool}
     */
    public static function computeProgress(int $cl_id): array
    {
        $total = (int) countElementsInTable(
            PluginChecklistItem::getTable(),
            ['plugin_checklist_checklists_id' => $cl_id]
        );
        $done = (int) countElementsInTable(
            PluginChecklistItem::getTable(),
            ['plugin_checklist_checklists_id' => $cl_id, 'status' => 'done']
        );

        return [
            'done'  => $done,
            'total' => $total,
            // floor(), comme le backfill SQL de la migration 2.0.0 : 999/1000
            // doit afficher 99 %, pas un faux 100 % qui ferait croire que tout
            // est validé.
            'percent'  => $total > 0 ? (int) floor($done * 100 / $total) : 0,
            // Une checklist vide n'est pas « terminée » : elle n'a rien à
            // terminer. 0 === 0 serait vrai, d'où le garde-fou sur $total.
            'complete' => $total > 0 && $done === $total,
        ];
    }

    /**
     * Recompute and persist the denormalised progress columns.
     *
     * THE single recompute point. It is called from PluginChecklistItem's native
     * post_addItem/post_updateItem/post_purgeItem hooks, so every write path —
     * toggleStatus, toggleMany, addExceptional, template instantiation, a future
     * MassiveAction or REST call — is covered without a line of extra code.
     *
     * The completion event fires ONLY on the !complete -> complete edge, so a
     * bulk validation of N tasks still produces exactly one notification.
     * Writes with _no_history: progress is a derived value, not a user action —
     * the visible trail is the followup written into the ticket timeline.
     */
    public static function refreshProgress(int $cl_id): void
    {
        if ($cl_id <= 0 || self::$purging) {
            return;
        }

        $checklist = new self();
        if (!$checklist->getFromDB($cl_id)) {
            // La checklist vient d'être purgée : ses tâches partent avec elle,
            // il n'y a plus rien à recalculer.
            return;
        }

        $was_complete = (int) $checklist->fields['items_total'] > 0
                        && (int) $checklist->fields['items_done'] === (int) $checklist->fields['items_total'];

        $p = self::computeProgress($cl_id);

        $input = [
            'id'           => $cl_id,
            'items_total'  => $p['total'],
            'items_done'   => $p['done'],
            'percent_done' => $p['percent'],
            'status'       => $p['complete'] ? 'done' : 'open',
            '_no_history'  => true,
        ];
        if ($p['complete'] && !$was_complete) {
            $input['date_completed'] = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
        }

        $checklist->update($input);

        if ($p['complete'] && !$was_complete) {
            self::onCompleted($cl_id);
        }
    }

    public function isComplete(): bool
    {
        return (int) $this->fields['items_total'] > 0
            && (int) $this->fields['items_done'] === (int) $this->fields['items_total'];
    }

    /**
     * Raise the native `checklist_completed` notification.
     *
     * Called from refreshProgress() ONLY on the !complete -> complete edge, so a
     * bulk validation of N tasks yields exactly ONE event — and never during a
     * purge, because refreshProgress() returns early while self::$purging is
     * set. That guard is not cosmetic: cleanDBonPurge() cascades the task
     * deletions BEFORE the checklist row is dropped, so the counters briefly
     * read done === total on a checklist that is being destroyed. Without it,
     * deleting a checklist would e-mail people that it had been completed.
     *
     * SEPARATE opt-in key, OFF by default. It deliberately does NOT reuse
     * `notify_on_item_done`: that key means "do not mute the followup", and
     * overloading it would hand every existing installation a duplicate e-mail
     * on upgrade — precisely the noise the v1.1.0 bulk aggregation removed.
     *
     * Best-effort, exactly like PluginChecklistFollowup::post(): this is a side
     * channel, and a dead SMTP server must never be able to roll back a
     * technician ticking a task.
     */
    protected static function onCompleted(int $cl_id): void
    {
        $cfg = PluginChecklistConfig::get();
        if ((int) ($cfg['native_notify_on_completed'] ?? 0) !== 1) {
            return;
        }

        if (!class_exists('NotificationEvent')) {
            return;
        }

        $checklist = new self();
        if (!$checklist->getFromDB($cl_id)) {
            return;
        }

        try {
            // The event is raised on the CHECKLIST, not on the parent ticket:
            // NotificationTarget::getInstanceClass() derives the target class
            // from the item's own type, so raising it on a Ticket would look for
            // NotificationTargetTicket and never reach this plugin.
            // Options may carry SCALARS only — they travel through the
            // notification queue.
            NotificationEvent::raiseEvent('checklist_completed', $checklist, [
                'entities_id' => (int) ($checklist->fields['entities_id'] ?? 0),
            ]);
        } catch (\Throwable $e) {
            Toolbox::logWarning('Checklist: completion notification failed - ' . $e->getMessage());
        }
    }

    // ═══════════════════════════════════════════════════════════════════════════
    //  TÂCHES OUVERTES D'UN ÉLÉMENT — source unique de la jointure checklist⇄tâche
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * Cheap gate for the solve/close veto: how many open tasks belong to a
     * BLOCKING checklist of this item. Runs on every ITIL update, so it must
     * stay a COUNT — never a row fetch.
     *
     * Exceptional (ad-hoc added) tasks count: the blocking flag lives on the
     * checklist, not on the individual task.
     */
    public static function countBlockingOpen(string $itemtype, int $items_id): int
    {
        global $DB;

        if ($items_id <= 0 || $itemtype === '') {
            return 0;
        }

        $criteria          = self::openItemsQuery([
            'cl.itemtype'    => $itemtype,
            'cl.items_id'    => $items_id,
            'cl.is_blocking' => 1,
            'it.status'      => 'todo',
        ]);
        $criteria['COUNT'] = 'cpt';

        $row = $DB->request($criteria)->current();

        return (int) ($row['cpt'] ?? 0);
    }

    /**
     * Detailed enumeration for the veto message and the validate modal.
     *
     * @return array<int, array{checklist_id:int,checklist_name:string,item_id:int,item_name:string,is_exceptional:bool}>
     */
    public static function getBlockingOpenItems(string $itemtype, int $items_id): array
    {
        if ($items_id <= 0 || $itemtype === '') {
            return [];
        }

        return self::fetchOpenItems([
            'cl.itemtype'    => $itemtype,
            'cl.items_id'    => $items_id,
            'cl.is_blocking' => 1,
            'it.status'      => 'todo',
        ]);
    }

    /**
     * Same enumeration WITHOUT the blocking filter: every open task of the
     * element, whatever its checklist. Feeds ajax/get_todo_items.php — the
     * validate modal lets the user tick any task, not only the blocking ones.
     *
     * @return array<int, array{checklist_id:int,checklist_name:string,item_id:int,item_name:string,is_exceptional:bool}>
     */
    public static function getOpenItemsFor(string $itemtype, int $items_id): array
    {
        if ($items_id <= 0 || $itemtype === '') {
            return [];
        }

        return self::fetchOpenItems([
            'cl.itemtype' => $itemtype,
            'cl.items_id' => $items_id,
            'it.status'   => 'todo',
        ]);
    }

    /**
     * THE single place the checklist ⇄ task join is expressed. Both the COUNT
     * gate and the two enumerations above build on it, so the join lives in one
     * spot instead of being re-typed (and drifting) in every caller — it used to
     * be hand-rolled inside ajax/get_todo_items.php.
     *
     * Query-builder criteria, never a raw SQL string: GLPI quotes the aliases
     * and the values itself, and \Glpi\DBAL is not needed for a plain equi-join.
     *
     * @param array<string,mixed> $where alias-qualified filters
     * @return array<string,mixed>
     */
    private static function openItemsQuery(array $where): array
    {
        return [
            'FROM'       => self::getTable() . ' AS cl',
            'INNER JOIN' => [
                PluginChecklistItem::getTable() . ' AS it' => [
                    'ON' => [
                        'it' => 'plugin_checklist_checklists_id',
                        'cl' => 'id',
                    ],
                ],
            ],
            'WHERE'      => $where,
        ];
    }

    /**
     * Runs openItemsQuery() and normalises the rows to the documented shape.
     *
     * @param array<string,mixed> $where
     * @return array<int, array{checklist_id:int,checklist_name:string,item_id:int,item_name:string,is_exceptional:bool}>
     */
    private static function fetchOpenItems(array $where): array
    {
        global $DB;

        $criteria           = self::openItemsQuery($where);
        $criteria['SELECT'] = [
            'cl.id AS checklist_id',
            'cl.name AS checklist_name',
            'it.id AS item_id',
            'it.name AS item_name',
            'it.is_exceptional AS is_exceptional',
        ];
        // Ordre historique de la modale de validation : par checklist, puis dans
        // l'ordre du kanban « à faire ».
        $criteria['ORDER'] = ['cl.name ASC', 'it.rank_todo ASC'];

        $rows = [];
        foreach ($DB->request($criteria) as $row) {
            $rows[] = [
                'checklist_id'   => (int) $row['checklist_id'],
                'checklist_name' => (string) $row['checklist_name'],
                'item_id'        => (int) $row['item_id'],
                'item_name'      => (string) $row['item_name'],
                'is_exceptional' => (bool) $row['is_exceptional'],
            ];
        }

        return $rows;
    }

    public static function deleteChecklist(int $cl_id): bool
    {
        // Purge réelle (la table n'a pas de colonne is_deleted) : cleanDBonPurge()
        // emporte les tâches, post_deleteFromDB() journalise sur l'élément parent.
        return (bool) (new self())->delete(['id' => $cl_id], true);
    }

    /**
     * Les tâches suivent leur checklist. deleteChildrenAndRelationsFromDb()
     * les supprime par le modèle (CommonDBChild::cleanDBonItemDelete), donc
     * avec les hooks GLPI — plus de DELETE brut à maintenir.
     */
    public function cleanDBonPurge(): void
    {
        // Le recalcul est neutralisé le temps de la cascade : voir $purging.
        // try/finally, pour qu'une exception au milieu d'une suppression ne
        // laisse pas le plugin avec un recalcul définitivement éteint.
        self::$purging = true;
        try {
            $this->deleteChildrenAndRelationsFromDb([PluginChecklistItem::class]);
        } finally {
            self::$purging = false;
        }
    }
}
