<?php
/**
 * PluginChecklistTemplateItem — Tâche dans un template de checklist
 */

declare(strict_types=1);

class PluginChecklistTemplateItem extends CommonDBTM
{
    public static $rightname = 'plugin_checklist_template';

    public static function getTypeName($nb = 0): string
    {
        return _n('Template item', 'Template items', $nb, 'checklist');
    }

    public static function getTable($classname = null): string
    {
        return 'glpi_plugin_checklist_templateitems';
    }

    /**
     * Options de recherche natives — voir PluginChecklistChecklist::rawSearchOptions()
     * pour le détail du contrat (`searchOptions()` est `final`, liste plate, `id`
     * et `name` obligatoires, objet instancié vide).
     */
    public function rawSearchOptions()
    {
        // Comme pour PluginChecklistItem : l'`id => 1` par défaut est retiré puis
        // redéfini en `string`. Une tâche de modèle n'a pas de formulaire
        // autonome, un `itemlink` mènerait donc nulle part.
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
            'field'         => 'rank',
            'name'          => __('Rank', 'checklist'),
            'datatype'      => 'number',
            'massiveaction' => false,
        ];

        return $tab;
    }

    public static function getForTemplate(int $template_id): array
    {
        global $DB;

        $items    = [];
        $iterator = $DB->request([
            'FROM'  => static::getTable(),
            'WHERE' => ['plugin_checklist_templates_id' => $template_id],
            'ORDER' => ['rank ASC'],
        ]);

        foreach ($iterator as $row) {
            $items[] = $row;
        }

        return $items;
    }

    /**
     * Réordonne les tâches d'un template selon l'ordre des IDs fournis.
     * Sécurise en vérifiant que chaque tâche appartient bien au template.
     */
    public static function updateRanks(int $template_id, array $item_ids): bool
    {
        global $DB;

        foreach ($item_ids as $rank => $id) {
            $DB->update(static::getTable(), ['rank' => (int) $rank + 1], [
                'id'                            => (int) $id,
                'plugin_checklist_templates_id' => $template_id,
            ]);
        }

        return true;
    }
}
