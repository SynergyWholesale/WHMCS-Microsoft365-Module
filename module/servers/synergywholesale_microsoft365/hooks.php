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
    /** At this part, it means user wants to create new fields, we check if Synergy API credentials have been saved */
    // If user hasn't saved Synergy Reseller ID and API Key, then we error out
    if (empty($_REQUEST['packageconfigoption'][1]) || empty($_REQUEST['packageconfigoption'][2])) {
        logActivity("Failed to create or assign config option for product #{$product->id} ({$product->name}). Error: Synergy Wholesae API credentials not provided");

        return 0;
    }
    // If the credentials were provided, we create new Synergy API instance
    $synergyAPI = new SynergyAPI($_REQUEST['packageconfigoption'][1], $_REQUEST['packageconfigoption'][2]);

    // We want to log any success or error actions
    $success = [];
    $error = [];

    /** ADD CONFIG GROUP AND CONFIG OPTIONS */
    // At this stage, we should add the config group and config options if this checkbox is checked
    if ($createConfigOptions) {
        // Get products list from Synergy API and format it so we can assign it to local DB config option name
        $productsListResponse = $synergyAPI->getProductsList();
        $productsList = $productsListResponse->subscriptionList[0];
        $productIdPlaceholder = [];
        foreach ($productsList as $productRow) {
            $productIdPlaceholder[$productRow->productName] = $productRow->productId;
        }

        // Now assign the id into the config option names
        $allConfigOptions = collect(ProductEnums::ALL_CONFIG_OPTIONS)->mapToGroups(function (array $item, int $key) use ($productIdPlaceholder) {
            $item['optionname'] = "{$productIdPlaceholder[$item['optionname']]}|{$item['optionname']}";

            return [$item['group'] => $item];
        })->toArray();

        foreach (ProductEnums::ALL_CONFIG_GROUPS as $configGroup) {
            /** First we create the config group */
            // If there is a config option group already exists with this name, we don't add it
            if ($whmcsLocalDb->getConfigOptionGroupByName($configGroup['name'])) {
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

            // Get the product we just created so we can add config options to this group
            $newGroup = $whmcsLocalDb->getConfigOptionGroupByName($configGroup['name'], 'get');

            /** After creating config group, we create the config options inside it */
            // We don't need to check existing config option, because the group was just created
            // If we have many config options with same name, it's okay because they are within different groups
            foreach ($allConfigOptions[$configGroup['name']] as $configOption) {
                // We exclude column 'group' out of the request data when create new config option
                $configOption = collect($configOption)->except('group')->toArray();
                // Append column 'gid' to request data
                $configOption['gid'] = $newGroup->id;
                logActivity("Config options for group {$configGroup['name']} is: " . print_r($configOption,true));

                // Perform action, check success status and add message
                if (!$whmcsLocalDb->createConfigOption($configOption)) {
                    // If failed, we add error message
                    $error[] = "Create new config option for group ({$configGroup['name']}): [{$configOption['optionname']}] (Unknown Error)";
                    continue;
                }

                // If success, then add success message
                $success[] = "Create new config option [{$configOption['optionname']}] for group ({$configGroup['name']})";
            }
        }
    }

    if (!empty($success)) {
        logActivity("Successfully performed the following actions: " . implode(' ---- ', $success));
    }

    if (!empty($error)) {
        logActivity("Failed to perform the following actions: " . implode(' ---- ', $error));
    }

    logActivity("Finished action for saving product #{$product->id} ({$product->name}).");
});