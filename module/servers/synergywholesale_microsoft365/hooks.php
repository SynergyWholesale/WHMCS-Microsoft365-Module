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

    logActivity("Successfully create or assign config option for product #{$product->id} ({$product->name}).");
});