<?php

use WHMCS\Module\Server\SynergywholesaleMicrosoft365\SynergyAPI;
use WHMCS\Module\Server\SynergywholesaleMicrosoft365\WhmcsLocalDb as LocalDB;
use WHMCS\Module\Server\SynergywholesaleMicrosoft365\Messages;
use WHMCS\Module\Server\SynergywholesaleMicrosoft365\ModuleEnums;
use WHMCS\Module\Server\SynergywholesaleMicrosoft365\ServiceStatuses as Status;
use WHMCS\Database\Capsule as DB;

if (!defined('WHMCS'))
    die('You cannot access this file directly.');

// This hook is triggered after the details are saved into database
add_hook('AdminProductConfigFieldsSave', 1, function($vars) {
    logActivity("Hook triggered: Admin Product Saved.");

    $configData = [
        'createConfigOptions' => $_REQUEST['packageconfigoption'][3],
        'createCustomFields' => $_REQUEST['packageconfigoption'][4],
        'configOptionPackage' => $_REQUEST['packageconfigoption'][5],
    ];
    logActivity("The values are: " . print_r($configData, true));
});