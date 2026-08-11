<?php
/**
 * PluginChecklistProfile — Matrice des droits (onglet Profils)
 *
 * Ajoute deux droits standard CommonDBTM (READ / CREATE / UPDATE / PURGE) :
 *   - plugin_checklist_template   : gestion des modèles de checklist
 *   - plugin_checklist_checklist  : ajout / gestion des checklists sur les objets
 *
 * La sauvegarde est prise en charge nativement par le formulaire Profile de GLPI
 * (le formulaire poste vers Profile::getFormURL() avec le champ « update »).
 */

declare(strict_types=1);

class PluginChecklistProfile extends Profile
{
    public static $rightname = 'profile';

    public static function getTypeName($nb = 0): string
    {
        return __('Checklists', 'checklist');
    }

    /**
     * Déclaration des droits gérés par la matrice.
     * Chaque droit s'appuie sur getRights() de l'itemtype (CommonDBTM →
     * READ / CREATE / UPDATE / PURGE).
     *
     * @return array<int, array<string, string>>
     */
    public static function getAllRights(): array
    {
        return [
            [
                'itemtype' => 'PluginChecklistTemplate',
                'label'    => __('Checklist templates', 'checklist'),
                'field'    => 'plugin_checklist_template',
            ],
            [
                'itemtype' => 'PluginChecklistChecklist',
                'label'    => __('Checklists on tickets/assets', 'checklist'),
                'field'    => 'plugin_checklist_checklist',
            ],
        ];
    }

    // ═══════════════════════════════════════════════════════════════════════════
    //  INTÉGRATION ONGLET PROFIL
    // ═══════════════════════════════════════════════════════════════════════════

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (
            $item instanceof Profile
            && (int) $item->getID() > 0
            && Session::haveRight('profile', READ)
        ) {
            return self::createTabEntry(self::getTypeName());
        }

        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0): bool
    {
        if ($item instanceof Profile) {
            self::showForProfile((int) $item->getID());
        }

        return true;
    }

    /**
     * Affiche la matrice des droits checklist pour un profil donné.
     * La sauvegarde est native (formulaire Profile) : aucun handler custom.
     */
    public static function showForProfile(int $profiles_id): void
    {
        $profile = new Profile();
        $profile->getFromDB($profiles_id);

        $canedit = Session::haveRightsOr('profile', [CREATE, UPDATE, PURGE]);

        echo "<div class='spaced'>";
        if ($canedit) {
            echo "<form method='post' action='" . htmlspecialchars(Profile::getFormURL()) . "'>";
        }

        $profile->displayRightsChoiceMatrix(self::getAllRights(), [
            'canedit'       => $canedit,
            'default_class' => 'tab_bg_2',
            'title'         => self::getTypeName(),
        ]);

        if ($canedit) {
            echo "<div class='center'>";
            echo Html::hidden('id', ['value' => $profiles_id]);
            echo Html::submit(_sx('button', 'Save'), ['name' => 'update']);
            echo "</div>";
            Html::closeForm();
        }

        echo "</div>";
    }

    // ═══════════════════════════════════════════════════════════════════════════
    //  INSTALLATION / DÉSINSTALLATION DES DROITS
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * Crée les droits en base et accorde le droit complet
     * (READ|CREATE|UPDATE|PURGE) aux profils qui possèdent déjà « config » en
     * écriture — de sorte que les administrateurs actuels conservent l'accès.
     */
    public static function install(): void
    {
        global $DB;

        foreach (self::getAllRights() as $right) {
            $field = $right['field'];

            if (countElementsInTable('glpi_profilerights', ['name' => $field]) !== 0) {
                continue;
            }

            // Crée le droit pour tous les profils (valeur 0 par défaut).
            ProfileRight::addProfileRights([$field]);

            // Profils déjà administrateurs (« config » en écriture).
            $admin_ids = [];
            foreach ($DB->request([
                'SELECT' => 'profiles_id',
                'FROM'   => 'glpi_profilerights',
                'WHERE'  => [
                    'name'   => 'config',
                    'rights' => ['&', UPDATE],
                ],
            ]) as $row) {
                $admin_ids[] = (int) $row['profiles_id'];
            }

            if (!empty($admin_ids)) {
                $DB->update('glpi_profilerights', [
                    'rights' => READ | CREATE | UPDATE | PURGE,
                ], [
                    'name'        => $field,
                    'profiles_id' => $admin_ids,
                ]);
            }
        }
    }

    /**
     * Supprime les droits de la base.
     */
    public static function uninstall(): void
    {
        foreach (self::getAllRights() as $right) {
            ProfileRight::deleteProfileRights([$right['field']]);
        }
    }
}
