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
		if (!$entry->departmentCategory) {
		    return false;

		}

		// Could use a service to update the users.

		    // Get deaprtment categories from the entry.
		    foreach ($entry->departmentCategory->all() as $category) {

			    // Get the first user group handle.
			    // Multiple departmentCategory->department editor user groups not supported.
			    $userGroup = $category->userGroups->getGroups()[0]['handle'];

			    // Get all the users in that group.
			    $groupedUsers = \craft\elements\User::find()->group($userGroup)->all();

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
		\craft\services\Elements::class,
		\craft\services\Elements::EVENT_AFTER_SAVE_ELEMENT,
		function(Event $event) {
    		if ($event->element instanceof \craft\elements\User) {
                	/* @var User $user */
			$user = $event->element;
        		//dd($user);

			// Get the user's user groups.
			/* @var UserGroups $userGroups */
			$userGroups = \craft\services\UserGroups::getGroupsByUserId($user->id);
			//dd($userGroups);

			// Use the UserGroups ids as the category search parameter.
			$userGroupsIds = array_column($userGroups, 'uid');
			//dd($userGroupsIds);
			foreach ($userGroupsIds as $key => $uid){
				// Looks like the Isolate plugin has a deserializing issue.
				$userGroupsIds[$key] = '["' . $uid . '"]';
				//dd($userGroupsIds[$key]);
			}
			//dd($userGroupsIds);

			// Get the department categories associated with those user groups.
			$departmentCategories = \craft\elements\Category::find()->userGroups($userGroupsIds)->all();
			//dd($departmentCategories);

			// Get the ids of entries with one of those departments.
			if (!empty($departmentCategories)) {
				$departmentEntries = \craft\elements\Entry::find()->departmentCategory($departmentCategories)->ids();
				//dd($departmentEntries);
			} else {
				$departmentEntries = [0];
				//dd($departmentEntries);
			}
			
			// Add or remove entries to the user's authorized entry ids.
			$isolateService = new IsolateService;
			foreach ([5, 14, 6] as $sectionId) {
				$isolateService::modifyRecords($user->id, $sectionId, $departmentEntries);
			}

			return true;
    		}

		return false;
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
