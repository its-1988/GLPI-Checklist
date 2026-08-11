# Changelog

Toutes les modifications notables de ce projet sont documentées dans ce fichier.

Le format est basé sur [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/),
et ce projet adhère au [Semantic Versioning](https://semver.org/lang/fr/).

## [Non publié]

### À venir
- Jeu de tests d'intégration sur une instance GLPI réelle

## [2.1.0] - 2026-07-22

### Corrigé

- **Avancement faux côté client** : une checklist à 999/1000 affichait **100 % et
  un badge vert** dans la carte, tandis que la base, la recherche et la timeline
  lisaient 99 %. Le correctif `floor()` de la 2.0.0 (côté serveur) n'avait jamais
  atteint le navigateur, resté sur `Math.round`. Le client ne fait désormais
  **plus aucun calcul de pourcentage** : le serveur est l'unique source de vérité
  et renvoie l'avancement qu'il a calculé (`move_item` / `reorder_items` /
  `add_item`).
- **Colonne Kanban vide à la création depuis un modèle** : créer une checklist à
  partir d'un modèle de 5 tâches dessinait « 0/5 » et un badge de 5 au-dessus d'une
  colonne **vide**, jusqu'au rechargement de la page. La carte est maintenant
  rendue côté serveur et arrive **déjà peuplée**.
- **Fenêtres de confirmation cassées sous une locale traduite** : le texte de
  confirmation de suppression était interpolé brut dans un `onclick` en ligne ;
  une traduction contenant une apostrophe (le français « l'élément ») cassait le
  gestionnaire — le bouton **supprimait alors sans confirmer**. Le texte passe
  désormais par un attribut `data-cl-confirm` lu par un écouteur délégué, et **tous
  les gestionnaires `onclick` en ligne sont retirés** dans l'ensemble du plugin.
- **Robustesse de la migration 2.0.1** : l'échec de conversion d'une colonne de
  date n'abandonne plus les conversions des autres colonnes, indépendantes. Chaque
  conversion est isolée et toute erreur est **journalisée** plutôt que
  silencieusement avalée.

### Modifié

- **Balisage à source unique** : le balisage des cartes et des tâches existait en
  **trois** copies tenues à la main (PHP, JS, timeline), qui avaient déjà divergé à
  quatre reprises. Le serveur le rend désormais **une seule fois** et le client
  l'insère. Trois endpoints AJAX gagnent un champ `html` ; leurs clés existantes
  sont conservées pour la compatibilité ascendante.

### Sécurité

- **Autorisation par enregistrement sur le CRUD des modèles** :
  `front/template.form.php` et l'endpoint de réordonnancement gardaient toute la
  surface avec un **unique droit global** et transmettaient le POST brut à
  add / update / delete **sans `check()`**. Un utilisateur de rang « config » dans
  une entité pouvait ainsi lire, modifier et purger les modèles d'une **autre
  entité**, et déplacer un modèle vers l'**entité 0 récursive** — gelant, via
  `is_blocking`, la résolution des tickets à l'échelle de **toute l'installation**.
  Chaque chemin de mutation appelle désormais le `check()` **par enregistrement**
  avec le droit correct, et l'entité d'un modèle **ne peut plus être changée** par
  le formulaire d'édition (les champs Entité et « Entités filles » n'y sont plus
  affichés qu'à la création, où `check(-1, CREATE)` valide l'entité choisie).
- **Existence et nombre de tâches d'une checklist ne fuitent plus** : le libellé de
  l'onglet ITIL et l'action « valider » de la timeline divulguaient l'existence
  d'une checklist et son **décompte exact** à des utilisateurs **sans le droit
  `plugin_checklist_checklist` en lecture** (l'état par défaut des profils
  non-`config`). Les deux respectent désormais ce droit en lecture.
- **Échappement** : un attribut `title=` brut et six URL brutes dans des attributs
  `action=` / `href=` sont désormais échappés ; les deux implémentations
  d'échappement sont fusionnées en une seule.

## [2.0.1] - 2026-07-22

### Corrigé

- **Avertissement GLPI 11 « Usage of "DATETIME" fields is discouraged » à
  l'installation** : toutes les colonnes de date des quatre tables du plugin
  passent de `DATETIME` à `TIMESTAMP NULL DEFAULT NULL`, conformément à la
  convention du cœur de GLPI (`install/mysql/glpi-empty.sql` n'utilise aucun
  `DATETIME`). GLPI inspecte chaque requête exécutée et signale le type déprécié ;
  seule `date_completed`, ajoutée en 2.0.0, déclenchait effectivement
  l'avertissement, les autres colonnes y échappaient par un simple hasard
  d'écriture. La forme explicite `NULL DEFAULT NULL` est indispensable : MySQL
  attribuerait sinon à la première colonne `TIMESTAMP` d'une table un
  `NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` implicite, et
  `date_mod` se réécrirait silencieusement à chaque mise à jour.
- Les installations existantes sont converties automatiquement par la migration
  2.0.1, qui vérifie le type réel de chaque colonne avant de la modifier et ne
  touche que celles encore déclarées en `datetime`.

## [2.0.0] - 2026-07-21

### Ajouté

- **Recherche et export** : les quatre itemtypes du plugin (checklist, tâche,
  modèle, tâche de modèle) déclarent leurs `rawSearchOptions`. Ils deviennent
  donc consultables dans la recherche GLPI, exportables (CSV / PDF / SLK) et
  accessibles par l'**API REST native**, sans une ligne de code supplémentaire.
- **Colonnes checklist dans la recherche Ticket / Change / Problem** : nom de la
  checklist, avancement en %, caractère bloquant et date de création. Elles
  s'ajoutent comme n'importe quelle colonne native et suivent le droit
  `plugin_checklist_checklist` en lecture — un profil qui n'a pas ce droit ne les
  voit pas et ne peut donc pas les extraire par un export.
- **Action de masse « Appliquer un modèle de checklist »** : depuis une liste de
  résultats de recherche sur des tickets, changements ou problèmes, poser le même
  modèle sur toute une sélection en une opération.
- **Blocage de la résolution / clôture** : un modèle peut être marqué *bloquant*.
  Tant qu'une tâche d'une checklist issue de ce modèle reste à faire, l'objet ITIL
  ne peut être ni résolu ni clôturé. Les deux chemins sont couverts — l'ajout
  d'une **solution** et le changement de **statut** — ainsi que le CRON de clôture
  automatique. Piloté par un interrupteur global, **désactivé par défaut**.
- **Notification native « Checklist terminée »** : un vrai événement GLPI
  (`checklist_completed`), avec son gabarit et ses destinataires modifiables dans
  `Configuration → Notifications`. **Désactivée par défaut.**
- **Avancement dans la timeline ITIL** : chaque checklist apparaît comme un
  élément natif de la timeline du ticket, avec sa barre de progression, au lieu
  d'être visible seulement dans l'onglet.

### Ajouté — prérequis techniques

Quatre chantiers sans effet visible immédiat, mais dont dépendent les six
fonctionnalités ci-dessus.

- **Versionnement du schéma et harnais de migration** : la version du schéma est
  enregistrée en base (`schema_version`) et les migrations s'exécutent dans
  l'ordre, chacune revérifiant l'état réel de la base avant d'agir. Rejouer une
  mise à jour est donc sans danger.
- **Colonnes d'avancement et `is_blocking`** : `items_total`, `items_done`,
  `percent_done`, `date_completed` sur les checklists, `is_blocking` sur les
  checklists et les modèles. Dénormalisées à dessein : le veto de clôture et les
  colonnes de recherche se contentent alors d'une lecture indexée, sans jointure
  ni comptage à chaque affichage de ticket. Rétro-remplies à la mise à jour.
- **Point de recalcul unique** de l'avancement, branché sur les hooks natifs
  `post_addItem` / `post_updateItem` / `post_purgeItem` des tâches. Un seul
  endroit met ces colonnes à jour, quel que soit le chemin emprunté (interface,
  validation en masse, CRON, API).
- **Énumération des tâches bloquantes au niveau du modèle** : `countBlockingOpen()`
  (un COUNT, utilisé comme garde) et `getBlockingOpenItems()` (appelé seulement
  une fois le refus acquis, pour nommer les tâches restantes).

### ⚠ Changements de comportement

Ces points modifient ce que voient les utilisateurs — **à lire avant mise à jour**.

- **Le blocage est DÉSACTIVÉ par défaut** (réglage global `blocking_enabled`).
  Rien ne change tant qu'il n'est pas activé.
- **Une fois activé, le blocage porte sur les Tickets, Changements et Problèmes**,
  indépendamment de la liste « Types d'objets » qui pilote l'affichage de
  l'onglet : les hooks de veto sont enregistrés **globalement**, parce que la
  clôture doit être arbitrée partout, y compris là où l'onglet n'est pas affiché.
- ⚠ **Activer le blocage agit sur le stock existant.** Un ticket déjà en
  **Résolu** ne peut atteindre **Clos** que par une véritable transition
  Résolu → Clos, et c'est précisément cette transition qui est refusée tant que
  des tâches bloquantes restent ouvertes. Autrement dit : des tickets résolus de
  longue date, portant une checklist incomplète issue d'un modèle bloquant, se
  retrouveront **impossibles à clôturer** dès l'activation du réglage — sans
  qu'aucune action récente ne l'explique. C'est le seul changement de cette
  version capable de surprendre un exploitant dès le premier jour. À vérifier
  avant d'activer : `percent_done < 100` sur les checklists `is_blocking = 1`
  d'objets déjà résolus.
- **Le CRON de clôture automatique est refusé lui aussi.** Comme personne ne lit
  les messages de session d'un CRON, le refus est tracé par un **suivi privé sur
  le ticket** — **une seule fois par ticket**, pas une fois par passage du CRON :
  la condition est persistante, la répéter n'ajouterait que du bruit.
- **La notification native est DÉSACTIVÉE par défaut**
  (`native_notify_on_completed`). C'est un réglage **distinct** de « Envoyer une
  notification » du suivi : une mise à jour ne déclenche donc aucun courriel
  supplémentaire, et personne ne reçoit de doublon.
- **Une checklist ad hoc — créée sans modèle — ne bloque JAMAIS.** Le caractère
  bloquant est recopié depuis le modèle à la création ; sans modèle, il n'existe
  aucun endroit où l'exprimer, et bloquer par défaut transformerait un
  pense-bête en obstacle à la clôture.
- **Le caractère bloquant est figé à la création de la checklist.** Cocher (ou
  décocher) « bloquant » sur un modèle ne modifie **pas** les checklists déjà
  posées à partir de lui : le réglage vaut pour les suivantes.
- **Nouveaux identifiants d'options de recherche : bloc réservé 9000-9099** sur
  Ticket, Change et Problem. L'espace d'identifiants d'un itemtype est partagé
  entre le cœur et tous les plugins ; en cas de collision avec un autre plugin,
  GLPI n'émet qu'un `trigger_error` et la colonne perdante disparaît sans bruit.
  L'installation **signale donc la collision par un avertissement** — et les
  colonnes concernées, simplement, n'apparaissent pas.
- **La colonne `status` des checklists est désormais tenue à jour** (`open` /
  `done`) ; auparavant elle existait sans être maintenue. `items_total`,
  `items_done`, `percent_done` et `date_completed` sont nouvelles et
  rétro-remplies à la mise à jour.
- **Checklists historiques déjà complètes : `status = 'done'` mais
  `date_completed` reste NULL.** Il n'existe aucune source honnête pour dater
  après coup une complétion qui n'a jamais été horodatée ; inventer la date de
  la migration aurait produit une donnée fausse et indétectable.
- **L'action de masse exige le droit `UPDATE` sur l'objet cible** (et le droit
  CREATE sur `plugin_checklist_checklist` pour que l'action soit proposée). Une
  ligne dans un résultat de recherche n'est pas une autorisation : les objets sur
  lesquels l'utilisateur n'a pas ce droit sont rapportés en échec, les autres sont
  traités normalement.

## [1.1.0] - 2026-07-21

### Ajouté
- **Page de réglages globaux** (liste des plugins → roue « Configurer »). Elle
  pilote : l'écriture d'un suivi quand une tâche est terminée, sa visibilité,
  l'envoi d'une notification, les mêmes trois réglages pour les tâches en retard,
  le regroupement des validations en masse, et la **liste des itemtypes** portant
  l'onglet Checklist. Cette liste était jusqu'ici codée en dur dans `setup.php`.
- **Surcharges par modèle** : chaque modèle de checklist peut redéfinir les six
  réglages de suivi/notification, ou hériter du global (sentinelle `inherit`).
- **Traduction `en_GB` complète** et gabarit `checklist.pot` pour les traducteurs.

### Corrigé
- **Internationalisation — la mécanique elle-même était cassée.** Le compilateur
  `.mo` maison ne savait pas écrire les formes plurielles et perdait silencieusement
  l'en-tête du catalogue : `_n()` ne traduisait donc **jamais**, quelle que soit la
  langue. Le compilateur gère désormais les pluriels, l'en-tête et `Plural-Forms`
  est déclaré dans chaque `.po`.
- **Domaines de texte manquants** : de nombreux appels `__()` / `_n()` omettaient
  le domaine `'checklist'` et cherchaient donc leur traduction dans le catalogue de
  GLPI, où elle n'existe pas.
- **Chaînes JavaScript non traduites** : les libellés de l'interface Kanban et de
  la fenêtre de validation étaient figés en dur dans le JS. Ils passent maintenant
  par le mécanisme de traduction natif du navigateur.
- **Validation en masse** : cocher N tâches écrivait N suivis, donc jusqu'à N
  courriels. Un **seul message récapitulatif par checklist** est désormais écrit.
- **Requêtes N+1** : la sélection des modèles visibles interrogeait la base une fois
  par modèle pour filtrer sur l'entité ; le filtrage passe par
  `getEntitiesRestrictCriteria()` en une seule requête.
- `doQueryOrDie()`, @deprecated depuis GLPI 11.0.0, remplacé par `doQuery()`.
  L'installation vérifie désormais que chaque table existe réellement avant
  d'annoncer un succès : un `CREATE TABLE` en échec ne peut plus passer inaperçu.
- La page de réglages s'ouvre avec le droit `config` en **lecture** — le même que
  celui qui affiche la roue « Configurer ». L'enregistrement exige toujours
  l'écriture. Un profil en lecture seule ne tombe donc plus sur une erreur de droits.

### Modifié
- **Ressources statiques** : CSS et JavaScript sortent de l'injection en ligne
  depuis PHP et vivent dans `public/css/` et `public/js/`, seul emplacement servi
  par GLPI 11.
- **Checklists et tâches deviennent des `CommonDBChild`** : le CRUD, le contrôle
  d'accès et l'historique passent par le cœur de GLPI.

### Supprimé
- Table maison `glpi_plugin_checklist_logs` : l'historique est natif. **Elle est
  supprimée à l'installation / mise à jour du plugin.**
- Contrôles IDOR écrits à la main sur les endpoints AJAX : la chaîne
  `CommonDBChild` (tâche → checklist → objet GLPI porteur) valide droit **et**
  entité nativement.

### ⚠ Changements de comportement

Ces points modifient ce que voient les utilisateurs — à lire avant mise à jour.

- **Visibilité du message « tâche terminée »** : la valeur par défaut devient
  **« Comme dans GLPI »**, c'est-à-dire la préférence de suivi propre à chaque
  technicien. Auparavant le suivi était **toujours public**, sans réglage possible.
- **Changements et Problèmes** : les checklists posées sur un `Change` ou un
  `Problem` produisent maintenant des messages. Seuls les tickets en produisaient.
- **Droit requis** : créer une checklist exige désormais le droit **CREATE** sur
  `plugin_checklist_checklist` (sémantique native des objets enfants), et non plus
  le seul droit UPDATE. Vérifiez vos profils après mise à jour.
- **Historique — nuance importante.** La création et la suppression d'une checklist
  sont journalisées **sur l'objet ITIL porteur** et apparaissent bien dans son
  onglet « Historique ». En revanche, cocher/décocher une tâche est journalisé
  **sur la checklist elle-même**, qui n'a pas de page de formulaire : ces lignes
  existent en base mais **ne sont affichées nulle part dans l'interface**. La trace
  visible de la complétion d'une tâche reste donc le **suivi écrit dans la timeline
  du ticket**.

## [1.0.6] - 2026-07-10

### Corrigé
- **Assets 404** : `js/Sortable.min.js` (et `js/checklist.js`, `css/checklist.css`)
  étaient à la racine du plugin. En GLPI 11, les ressources non-PHP (js/css/images)
  ne sont servies QUE depuis `<plugin>/public/…` (routeur
  `RequestRouterTrait::getTargetFile()` → `$plugin_dir.'/public'.$relative_path`).
  Elles renvoyaient donc 404 (`/plugins/checklist/js/Sortable.min.js`), cassant le
  drag-and-drop SortableJS. Fichiers déplacés dans `public/js/` et `public/css/` ;
  les valeurs de hook (`js/Sortable.min.js`) restent inchangées — le routeur ajoute
  `public/` lui-même.

## [1.0.5] - 2026-07-10

### Ajouté
- **Droits par profil** : deux nouveaux droits GLPI standard remplacent le contrôle
  d'accès basé sur le droit `config` :
  - `plugin_checklist_template` — gestion des **modèles** de checklist
    (Administration › Modèles de checklist) ;
  - `plugin_checklist_checklist` — **checklists sur les objets** (ajout à un ticket
    ou un bien, édition, validation des tâches).
  Chaque droit gère lecture / création / mise à jour / purge.
- Onglet **« Checklists »** sur les profils (Administration › Profils) : matrice
  d'attribution des droits (`PluginChecklistProfile`), sauvegarde native par le
  formulaire Profile de GLPI.
- Migration à l'installation : les droits sont créés pour tous les profils et
  accordés en totalité aux profils déjà administrateurs (droit `config` en écriture),
  afin de préserver les accès existants.

### Modifié
- L'application des droits passe du droit `config` aux nouveaux droits de profil :
  `$rightname` des classes de modèles et de checklists, méthodes
  `canView/Create/Update/Purge`, contrôles `Session::checkRight` des pages `front/`
  et vérification supplémentaire (défense en profondeur) sur les endpoints AJAX.
  Les règles d'affectation et la tâche CRON restent sur `config` (administration
  avancée).

## [1.0.4] - 2026-07-10

### Modifié
- Le bouton « Valider une tâche checklist » n'apparaît désormais **que si l'élément
  possède au moins une checklist** (garde `countElementsInTable` sur le hook
  `timeline_actions`).
- Bouton rendu **plus compact** (élément de menu) et **déplacé dans le menu d'actions
  « Répondre » (▾)** du ticket via JS (repli en petit bouton `btn-sm` si ce menu est
  absent — ticket résolu / type d'action unique). Le handler de clic délégué sur
  `[data-clv-id]` est inchangé, donc le flux de validation reste identique.

## [1.0.3] - 2026-07-10

### Corrigé
- **CSRF / pool de jetons** : réutilisation du jeton de page (`Session::getNewCSRFToken()`)
  au lieu d'un jeton « standalone » (`getNewCSRFToken(true)`) régénéré à chaque rendu de
  ticket (hook `timeline_actions`) et de tableau checklist / template. Le jeton standalone
  gonflait le pool CSRF limité de GLPI et pouvait évincer d'autres jetons — provoquant des
  « invalid request » ailleurs (ex. le filtre de date `ajax/genericdate.php`). Le jeton de
  page est envoyé via l'en-tête `X-Glpi-Csrf-Token` et validé avec `preserve_token`, donc
  réutilisable par plusieurs requêtes AJAX. Les jetons de formulaire (`Html::hidden`) sont
  inchangés.
- Remplacement de `Plugin::getWebDir('checklist')` (@deprecated GLPI 11 — émet un avis de
  dépréciation à chaque appel, soit une fois par affichage de ticket) par un helper
  `plugin_checklist_web_dir()` basé sur `$CFG_GLPI['root_doc']`.

## [1.0.2] - 2026-07-10

### Ajouté
- Traduction russe `ru_RU` avec fichiers `.po` et `.mo`.
- Métadonnées Marketplace en russe (`checklist.xml`).


## [1.0.1] - 2026-07-10

### Corrigé
- Compatibilité GLPI 11: exigence PHP alignée sur 8.2+.
- Sélection des templates limitée à l'entité de l'élément parent, avec prise en compte de la récursivité.
- Validation serveur de `templates_id` pour empêcher la réutilisation d'un template hors entité.
- SortableJS embarqué localement pour supprimer la dépendance CDN à l'exécution.
- Tables plugin créées avec `ROW_FORMAT=DYNAMIC`, conformément aux conventions GLPI 11.

## [1.0.0] - 2026-06-10

Première version publique. GLPI 11.0.x.

### Ajouté
- **Modèles de checklists** réutilisables avec CRUD complet (`Configuration › Modèles de checklist`).
  - Réordonnancement des tâches par glisser-déposer.
  - Sélection de l'entité et récursivité (sous-entités).
  - Délai de notification configurable en **heures / jours / semaines**.
- **Onglet Checklist** disponible sur tous les itemtypes GLPI configurés (Ticket, Computer, Phone, Monitor, NetworkEquipment, Printer, Software, Peripheral, Rack, Enclosure).
- **Vue Kanban** « À faire » / « Fait » avec bascule au clic et drag & drop (SortableJS).
- **Plusieurs checklists par élément** avec barre de progression.
- **Tâches exceptionnelles** ad hoc, distinctes des tâches verrouillées issues du modèle.
- **Historique immuable** des actions par checklist.
- **Moteur de règles GLPI** (`Administration › Règles`) : association automatique d'un modèle selon le type d'élément, le titre/nom, la catégorie ITIL ou la description.
- **Notifications des tâches en retard** : tâche CRON `checklistOverdue` (horaire) + suivi privé sur les tickets.
- **Intégration timeline ticket** : bouton « Valider une tâche checklist » + création automatique d'un suivi quand une tâche est cochée.
- **Sélecteur de modèle avec recherche** à la création d'une checklist.
- **Internationalisation FR / EN** (fichiers `.po`/`.mo`).
- **Environnement de développement Docker** (GLPI 11 + MariaDB + PhpMyAdmin).

### Sécurité
- Contrôle d'accès sur tous les endpoints AJAX : vérification des droits de l'utilisateur sur l'élément parent (`$item->can()`), couvrant droit + entité + accès spécifique (protection IDOR).
- Échappement HTML systématique des valeurs dynamiques (serveur + JavaScript).
- CSRF via le header `X-Glpi-Csrf-Token` de GLPI 11.
- L'utilitaire de dev `compile_mo.php` est restreint à la CLI.

[Non publié]: https://github.com/<votre-compte>/glpi-plugin-checklist/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/<votre-compte>/glpi-plugin-checklist/releases/tag/v1.0.0
