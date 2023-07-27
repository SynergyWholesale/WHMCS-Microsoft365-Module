<?php

use WHMCS\Module\Server\SynergywholesaleMicrosoft365\SynergyAPI;
use WHMCS\Module\Server\SynergywholesaleMicrosoft365\WhmcsLocalDb as LocalDB;
use WHMCS\Module\Server\SynergywholesaleMicrosoft365\Messages;
use WHMCS\Module\Server\SynergywholesaleMicrosoft365\ModuleEnums;
use WHMCS\Module\Server\SynergywholesaleMicrosoft365\ProductEnums;
use WHMCS\Module\Server\SynergywholesaleMicrosoft365\ServiceStatuses as Status;
use WHMCS\Database\Capsule as DB;

if (!defined('WHMCS'))
    die('You cannot access this file directly.');

// This hook is triggered after the details are saved into database
add_hook('AdminProductConfigFieldsSave', 1, function($vars) {
    // Create instance of WHMCS Local DB
    $whmcsLocalDb = new LocalDB();

    // Get the current product ID
    $productId = $vars['pid'];
    $product = $whmcsLocalDb->getProductById($productId);

    // Get its module's config option values
    $configData = [
        'createConfigOptions' => $_REQUEST['packageconfigoption'][3],
        'createCustomFields' => $_REQUEST['packageconfigoption'][4],
        'configOptionPackage' => $_REQUEST['packageconfigoption'][5],
    ];

    // If for some reasons, this field is empty, then we don't do anything
    if (empty($configData['configOptionPackage'])) {
        logActivity("Failed to create or assign config option for product #{$product->id} ({$product->name}). Error: Module's Config Option Package not provided.");

        return 0;
    }

    /** CHECK IF ANY CHECKBOX IS CHECKED */
    $createCustomFields = !empty($configData['createCustomFields']) && $configData['createCustomFields'] == 'on' ;
    $createConfigOptions = !empty($configData['createConfigOptions']) && $configData['createConfigOptions'] == 'on';

    if (!$createCustomFields && !$createConfigOptions) {
        // If these 2 checkboxes are not checked, that means we don't need to do anything else, just end the hook
        logActivity("Product #{$product->id} ({$product->name}) was saved but no need to create or assign config options and custom fields.");
        return 0;
    }

    // We want to log any success or error actions
    $success = [];
    $error = [];

    /** ADD CONFIG GROUP AND CONFIG OPTIONS */
    // At this stage, we should add the config group and config options if this checkbox is checked
    if ($createConfigOptions) {
        foreach (ProductEnums::ALL_CONFIG_GROUPS as $configGroup) {
            // If there is a config option group already exists with this name, we don't add it
            if ($whmcsLocalDb->configOptionGroupExists($configGroup)) {
                $error[] = "Create new config option group: [{$configGroup['name']}] (Group Existed)";
                continue;
            }

            // Otherwise if not exists, then we create new group
            // Perform action, check success status and add message
            if (!$whmcsLocalDb->createConfigOptionGroup($configGroup)) {
                // If failed, we add error message
                $error[] = "Create new config option group: [{$configGroup['name']}] (Unknown Error)";
                continue;
            }

            // If success, then add success message
            $success[] = "Create new config option group [{$configGroup['name']}]";
        }
    }

    if (!empty($success)) {
        logActivity("Successfully performed the following actions: " . implode(', ', $success));
    }

    if (!empty($error)) {
        logActivity("Failed to perform the following actions: " . implode(', ', $error));
    }

    logActivity("Finished action for saving product #{$product->id} ({$product->name}).");
});