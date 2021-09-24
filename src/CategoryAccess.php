<?php
/**
 * Category Access plugin for Craft CMS 3.x
 *
 * Category based access control
 *
 * @link      https://www.github.com/extensibleseth
 * @copyright Copyright (c) 2021 Seth Hendrick
 */

namespace extensibleseth\categoryaccess;

use extensibleseth\categoryaccess\services\AccessUpdate as AccessUpdateService;

use Craft;
use craft\base\Plugin;
use craft\services\Plugins;
use craft\services\UserGroups;
use craft\events\PluginEvent;
use craft\elements\Entry;
use craft\helpers\ElementHelper;
use craft\events\ModelEvent;
use craft\events\UserGroupsAssignEvent;

use trendyminds\isolate\records\IsolateRecord;
use trendyminds\isolate\services\IsolateService;

use yii\base\Event;

/**
 * Craft plugins are very much like little applications in and of themselves. We’ve made
 * it as simple as we can, but the training wheels are off. A little prior knowledge is
 * going to be required to write a plugin.
 *
 * For the purposes of the plugin docs, we’re going to assume that you know PHP and SQL,
 * as well as some semi-advanced concepts like object-oriented programming and PHP namespaces.
 *
 * https://docs.craftcms.com/v3/extend/
 *
 * @author    Seth Hendrick
 * @package   CategoryAccess
 * @since     0.1.0
 *
 * @property  AccessUpdateService $accessUpdate
 */
class CategoryAccess extends Plugin
{
    // Static Properties
    // =========================================================================

    /**
     * Static property that is an instance of this plugin class so that it can be accessed via
     * CategoryAccess::$plugin
     *
     * @var CategoryAccess
     */
    public static $plugin;

    // Public Properties
    // =========================================================================

    /**
     * To execute your plugin’s migrations, you’ll need to increase its schema version.
     *
     * @var string
     */
    public $schemaVersion = '0.1.0';

    /**
     * Set to `true` if the plugin should have a settings view in the control panel.
     *
     * @var bool
     */
    public $hasCpSettings = false;

    /**
     * Set to `true` if the plugin should have its own section (main nav item) in the control panel.
     *
     * @var bool
     */
    public $hasCpSection = false;

    // Public Methods
    // =========================================================================

    /**
     * Set our $plugin static property to this class so that it can be accessed via
     * CategoryAccess::$plugin
     *
     * Called after the plugin class is instantiated; do any one-time initialization
     * here such as hooks and events.
     *
     * If you have a '/vendor/autoload.php' file, it will be loaded for you automatically;
     * you do not need to load it in your init() method.
     *
     */
    public function init()
    {
        parent::init();
        self::$plugin = $this;

        // Do something after we're installed
        Event::on(
            Plugins::class,
            Plugins::EVENT_AFTER_INSTALL_PLUGIN,
            function (PluginEvent $event) {
                if ($event->plugin === $this) {
                    // We were just installed
                }
            }
        );

        Event::on(
            Entry::class,
            Entry::EVENT_AFTER_SAVE,
            function (ModelEvent $event) {
                /* @var Entry $entry */
                $entry = $event->sender;

		// Could use a service to filter -> false.
		if (!$event->isNew) {
			return false;
		}

		// Check for department category.
                // @TODO handle the case where a department with a UG was removed.
                // - Get all isolated users and remove this entry id via modifyRecords().
		if (!$entry->departmentCategory) {
		    return false;
		}

		// Could use a service to update the users.

		    // Get department categories from the entry.
		    foreach ($entry->departmentCategory->all() as $category) {

			    // Get the first user group handle.
			    // Multiple departmentCategory->department editor user groups not supported.
			    $userGroup = $category->userGroups->getGroups();
			    if (empty($userGroup) || !isset($userGroup[0]['handle'])) {
				    // No userGroups on category.
				    // @TODO handle the case where a department with a UG was removed.
				    // - Get all isolated users and remove this entry id via modifyRecords().
				    break;
			    }

			    // Get the handle to load users.
			    $userGroupHandle = $userGroup[0]['handle'];

			    // Get all the users in that group.
			    $groupedUsers = \craft\elements\User::find()->group($userGroupHandle)->all();
			    //dd($groupedUsers);

			    foreach ($groupedUsers as $deptEditor) {

				// Check if the isolated user already has access to this entry, if so, skip it
				$existingRecord = IsolateRecord::findOne([
				    "userId" => $deptEditor->id,
				    "sectionId" => $entry->sectionId,
				    "entryId" => $entry->duplicateOf->id,
				]);

				if ($existingRecord) {
				    break;
				}

				// Otherwise make sure this user has access to this entry that they just created
				// @TODO remove users in other departments. 
				$record = new IsolateRecord;
				$record->setAttribute('userId', $deptEditor->id);
				$record->setAttribute('sectionId', $entry->sectionId);
				$record->setAttribute('entryId', $entry->duplicateOf->id);
				$record->save();
			    }
		    }
		    return true;
		}
        );

	// @TODO Add all department content on user group assignment.
	Event::on(
		\craft\services\Users::class,
		\craft\services\Users::EVENT_AFTER_ASSIGN_USER_TO_GROUPS,
		function(UserGroupsAssignEvent $event) {

			// Get the user id to load groups.
			$userId = $event->userId;

			// Get the user's user groups.
			$userGroups = \craft\services\UserGroups::getGroupsByUserId($userId);

			// Get User Groups uid.
			$userGroupsIds = array_column($userGroups, 'uid');

			// Wrap each uid in array charaters.
			foreach ($userGroupsIds as $key => $uid){

				// Looks like the Isolate plugin has a deserializing issue.
				$userGroupsIds[$key] = '["' . $uid . '"]';
			}

			// Get the department categories associated with those user groups.
			$departmentCategories = \craft\elements\Category::find()->userGroups($userGroupsIds)->all();

			// Get the ids of entries with one of those departments.
			if (!empty($departmentCategories)) {
				$departmentEntries = \craft\elements\Entry::find()->departmentCategory($departmentCategories)->ids();
			} else {
				// An empty array [] removes that section from isolation.
				$departmentEntries = [];
			}
			
			// Add or remove entries to the user's authorized entry ids.
			// @TODO Get these values from config.
			// 5 = Blog
			// 14 = Departments
			// 6 = FAQ
			$isolateService = new IsolateService;
			foreach ([5, 14, 6] as $sectionId) {
				$isolateService::modifyRecords($userId, $sectionId, $departmentEntries);
			}

			return true;
	});


        Event::on(
            Entry::class,
            Entry::EVENT_BEFORE_SAVE,
            function (ModelEvent $event) {
                /* @var Entry $entry */
                $entry = $event->sender;

		// If the departmentCategory field changed, remove it from isolation.
		if (in_array('departmentCategory', $entry->dirtyFields)){
			$record = new IsolateRecord;
			$record->deleteAll(['entryId' => $entry->id]);
			return true;
		}
	});
/**
 * Logging in Craft involves using one of the following methods:
 *
 * Craft::trace(): record a message to trace how a piece of code runs. This is mainly for development use.
 * Craft::info(): record a message that conveys some useful information.
 * Craft::warning(): record a warning message that indicates something unexpected has happened.
 * Craft::error(): record a fatal error that should be investigated as soon as possible.
 *
 * Unless `devMode` is on, only Craft::warning() & Craft::error() will log to `craft/storage/logs/web.log`
 *
 * It's recommended that you pass in the magic constant `__METHOD__` as the second parameter, which sets
 * the category to the method (prefixed with the fully qualified class name) where the constant appears.
 *
 * To enable the Yii debug toolbar, go to your user account in the AdminCP and check the
 * [] Show the debug toolbar on the front end & [] Show the debug toolbar on the Control Panel
 *
 * http://www.yiiframework.com/doc-2.0/guide-runtime-logging.html
 */
        Craft::info(
            Craft::t(
                'category-access',
                '{name} plugin loaded',
                ['name' => $this->name]
            ),
            __METHOD__
        );
    }

    // Protected Methods
    // =========================================================================

}
