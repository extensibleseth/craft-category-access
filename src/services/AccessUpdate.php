<?php
/**
 * Category Access plugin for Craft CMS 3.x
 *
 * Category based access control
 *
 * @link      https://www.github.com/extensibleseth
 * @copyright Copyright (c) 2021 Seth Hendrick
 */

namespace extensibleseth\categoryaccess\services;

use extensibleseth\categoryaccess\CategoryAccess;

use Craft;
use craft\base\Component;

/**
 * AccessUpdate Service
 *
 * All of your plugin’s business logic should go in services, including saving data,
 * retrieving data, etc. They provide APIs that your controllers, template variables,
 * and other plugins can interact with.
 *
 * https://craftcms.com/docs/plugins/services
 *
 * @author    Seth Hendrick
 * @package   CategoryAccess
 * @since     0.1.0
 */
class AccessUpdate extends Component
{
    // Public Methods
    // =========================================================================

    /**
     * This function can literally be anything you want, and you can have as many service
     * functions as you want
     *
     * From any other plugin file, call it like this:
     *
     *     CategoryAccess::$plugin->accessUpdate->exampleService()
     *
     * @return mixed
     */
    public function exampleService()
    {
        $result = 'something';

        return $result;
    }
}
