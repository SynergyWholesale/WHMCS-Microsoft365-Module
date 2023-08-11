<?php

use WHMCS\Module\Server\SynergywholesaleMicrosoft365\SynergyAPI;
use WHMCS\Module\Server\SynergywholesaleMicrosoft365\WhmcsLocalDb as LocalDB;
use WHMCS\Module\Server\SynergywholesaleMicrosoft365\Messages;
use WHMCS\Module\Server\SynergywholesaleMicrosoft365\ProductEnums;
use WHMCS\Module\Server\SynergywholesaleMicrosoft365\ModuleEnums;

if (!defined('WHMCS'))
    die('You cannot access this file directly.');

// This hook is triggered after the details are saved into database
add_hook('AdminProductConfigFieldsSave', 1, function($vars) {
    // Create instance of WHMCS Local DB
    $whmcsLocalDb = new LocalDB();

    // Get the current product ID
    $productId = $vars['pid'];
    $product = $whmcsLocalDb->getProductById($productId);

    // Validate and only allow this hook continue to be run if this product is assigned to MS365 module
    if ($product->servertype != 'synergywholesale_microsoft365') {
        return 0;
    }

    // Get its module's config option values
    $configData = [
        'createConfigOptions' => $product->configoption3,
        'createCustomFields' => $product->configoption4,
        'configOptionPackage' => $product->configoption5,
    ];

    /** CHECK IF ANY CHECKBOX IS CHECKED */
    $createCustomFields = !empty($configData['createCustomFields']) && $configData['createCustomFields'] == 'on' ;
    $createConfigOptions = !empty($configData['createConfigOptions']) && $configData['createConfigOptions'] == 'on';

    if (!$createCustomFields && !$createConfigOptions) {
        // If these 2 checkboxes are not checked, that means we don't need to do anything else, just end the hook
        logActivity("Product #{$product->id} ({$product->name}) was saved but no further actions are required.");

        return 0;
    }
    /** Disable the checkboxes before doing anything else because whether the actions are successful or not, we always disable them */
    if ($createConfigOptions) { // If this config options checkbox is checked, we disable it
        if (!$whmcsLocalDb->disableProductCreateConfigOptions($product->id)) {
            $error[] = "Disable product's Create Config Options value";
        } else {
            $success[] = "Disable product's Create Config Options value";
        }
    }
    if ($createCustomFields) { // If this custom field checkbox is checked, we disable it
        if (!$whmcsLocalDb->disableProductCreateCustomFields($product->id)) {
            $error[] = "Disable product's Create Custom Fields value";
        } else {
            $success[] = "Disable product's Create Custom Fields value";
        }
    }
    /** At this part, it means user wants to create new fields, we check if Synergy API credentials have been saved */
    // If user hasn't saved Synergy Reseller ID and API Key, then we error out
    if (empty($product->configoption1) || empty($product->configoption2)) {
        logActivity("Failed to create or assign config option for product #{$product->id} ({$product->name}). Error: Synergy Wholesale API credentials not provided");

        return 0;
    }
    // If the credentials were provided, we create new Synergy API instance
    $synergyAPI = new SynergyAPI($product->configoption1, $product->configoption2);

    // We want to log any success or error actions
    $success = [];
    $error = [];

    /** ADD CONFIG GROUP AND CONFIG OPTIONS */
    // At this stage, we should add the config group and config options if this checkbox is checked
    if ($createConfigOptions) {
        /** Validate and perform action for config options and config groups */
        $validateAndCreateConfigsResult = hookSynergywholesale_Microsoft365_validateAndCreateConfigOptionGroups($whmcsLocalDb, $synergyAPI);
        // Sync the list of config groups that we just retrieved from the above function
        $allConfigGroupsFinal = $validateAndCreateConfigsResult['allConfigGroupsFinal'];
        // Sync the new error and success messages with the current messages list
        $success = $validateAndCreateConfigsResult['success'];
        $error = $validateAndCreateConfigsResult['error'];

        /** After creating all configurations, we check if user wants to assign this product to a config group */
        // We only assign this product to a config group if user provided the requested package and we have added some groups before
        if (!empty($configData['configOptionPackage']) && !empty($allConfigGroupsFinal)) {
            $assignResult = hookSynergywholesale_Microsoft365_assignConfigGroupToProduct($whmcsLocalDb, $configData['configOptionPackage'], $allConfigGroupsFinal, $product->id);

            // Add log message
            if ($assignResult['status'] == 'success') {
                $success[] = $assignResult['message'];
            } else {
                $error[] = $assignResult['message'];
            }
        }
    }

    /** ADD CUSTOM FIELDS FOR PRODUCT */
    // At this stage, we should add the custom fields if this checkbox is checked
    if ($createCustomFields) {
        /** Validate and perform action for custom fields */
        $validateAndCreateCustomFieldsResult = hookSynergywholesale_Microsoft365_validateAndCreateCustomFields($whmcsLocalDb, $product->id);
        // Sync the new error and success messages with the current messages list
        $success = array_merge($success, $validateAndCreateCustomFieldsResult['success']);
        $error = array_merge($error, $validateAndCreateCustomFieldsResult['error']);
    }

    /** FINALLY AT THE END WE LOG ANY SUCCESS OR ERRORS DURING THE PROCESS */
    if (!empty($success)) {
        logActivity("[Save product #{$product->id} ({$product->name})] Successfully performed the following actions: " . implode(' ---- ', $success));
    }

    if (!empty($error)) {
        logActivity("[Save product #{$product->id} ({$product->name})] Failed to perform the following actions: " . implode(' ---- ', $error));
    }
});

/** Function inside hook for logics to check and create custom fields */
function hookSynergywholesale_Microsoft365_validateAndCreateCustomFields(LocalDB $whmcsLocalDb, int $productId)
{
    $success = [];
    $error = [];

    /** Get product's current custom fields and format it for later validation **/
    $currentProductCustomFields = $whmcsLocalDb->getProductCustomFields($productId);
    // If there is some items in to collection, we loop through and format the list, otherwise, just make it an empty array
    $currentProductCustomFields = count($currentProductCustomFields) > 0
        ? collect($currentProductCustomFields)->mapToGroups(function ($customField, int $key) {
            return [$customField->fieldname => $customField];
        })->toArray()
        : [];

    /** Loop through our default list to check and create */
    foreach (ProductEnums::MS365_CUSTOM_FIELDS as $customField) {
        // Check if this field already existed in the current custom fields list, then we don't create new one
        if (!empty ($currentProductCustomFields[$customField['fieldname']])) {
            continue;
        }

        // Assign the product id to the columns
        $customField['relid'] = "{$productId}";

        // At this part, we should just create those fields
        if (!$whmcsLocalDb->createNewProductCustomField($customField)) {
            // If failed, we add error message
            $error[] = "Create custom field ({$customField['fieldname']}). Error: " . Messages::UNKNOWN_ERROR;
            continue;
        }

        // Otherwise, we add success message
        $success[] = "Create custom field ({$customField['fieldname']})";
    }

    return [
        'success' => $success,
        'error' => $error,
    ];
}

/** Function inside hook for logics to check and create config option groups */
function hookSynergywholesale_Microsoft365_validateAndCreateConfigOptionGroups(LocalDB $whmcsLocalDb, SynergyAPI $synergyAPI): array
{
    $success = [];
    $error = [];
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

    // Placeholder for all config option groups after being created
    $allConfigGroupsFinal = [];

    foreach (ProductEnums::ALL_CONFIG_GROUPS as $configGroup) {
        /** First we create the config group */
        // If there is a config option group already exists with this name, we don't create another one, instead just add it to the final list
        $existingGroup = $whmcsLocalDb->getConfigOptionGroupByName($configGroup['name']);
        if (!empty($existingGroup)) {
            $allConfigGroupsFinal[$existingGroup->name] = collect($existingGroup)->toArray();
            continue;
        }

        // Otherwise if not exists, then we create new group
        // Perform action, check success status and add message
        if (!$whmcsLocalDb->createConfigOptionGroup($configGroup)) {
            // If failed, we add error message
            $error[] = "Create new config option group: [{$configGroup['name']}] (" . Messages::UNKNOWN_ERROR . ")";
            continue;
        }

        // If success, then add success message
        $success[] = "Create new config option group [{$configGroup['name']}]";

        // Get the product we just created so we can add config options to this group
        $newGroup = $whmcsLocalDb->getConfigOptionGroupByName($configGroup['name']);
        // Add this new group to the After Created list
        $allConfigGroupsFinal[$newGroup->name] = collect($newGroup)->toArray();

        /** After creating config group, we create the config options inside it */
        // We don't need to check existing config option, because the group was just created
        // If we have many config options with same name, it's okay because they are within different groups
        foreach ($allConfigOptions[$configGroup['name']] as $configOption) {
            // We exclude column 'group' out of the request data when create new config option
            $configOption = collect($configOption)->except('group')->toArray();
            // Append column 'gid' to request data
            $configOption['gid'] = $newGroup->id;

            // Perform action, check success status and add message
            if (!$whmcsLocalDb->createConfigOption($configOption)) {
                // If failed, we add error message
                $error[] = "Create new config option for group ({$configGroup['name']}): [{$configOption['optionname']}] (" . Messages::UNKNOWN_ERROR . ")";
                continue;
            }

            /** After creating the config option, we want to add the sub option 'Seats' */
            // Get id of the config option we just created, it will always true because we just created it before
            $newOption = $whmcsLocalDb->getConfigOptionByGroupIdAndOptionName($newGroup->id, $configOption['optionname']);
            // Prepare data for new config option sub
            $newConfigOptionSub = ProductEnums::DEFAULT_CONFIG_OPTION_SUB_DETAILS;
            $newConfigOptionSub['configid'] = $newOption->id;
            // Perform action, check success status and add message
            if (!$whmcsLocalDb->createConfigOptionSub($newConfigOptionSub)) {
                // If failed, we add error message
                $error[] = "Create new sub option [{$newConfigOptionSub['optionname']}] for config option ({$configOption['optionname']}): (" . Messages::UNKNOWN_ERROR . ")";
                continue;
            }

            // If success, then add success message
            $success[] = "Create new sub option [{$newConfigOptionSub['optionname']}] for config option ({$configOption['optionname']})";

            /** After creating the sub option 'Seats', we want to configure the price for it as 0.00 as well */
            // Get id of the config option we just created, it will always true because we just created it before
            $newOptionSubObject = $whmcsLocalDb->getConfigOptionSubByConfigIdAndSubOptionName($newOption->id, $newConfigOptionSub['optionname']);
            // Prepare data for new config option sub pricing
            $newConfigOptionSubPricing = ProductEnums::DEFAULT_CONFIG_OPTION_SUB_PRICING_DETAILS; // Get all default details
            $newConfigOptionSubPricing['relid'] = $newOptionSubObject->id; // Assign the targeted config option sub id
            // Get currency 'AUD' instead of defaulting it as 1 as some partners might have other currencies along with 'AUD'
            $currency = $whmcsLocalDb->getCurrencyByCode(ProductEnums::DEFAULT_CURRENCY);
            if (!$currency) {
                // If we can't find currency AUD somehow, then we display error mentioning that user should create the AUD currency first as we cannot create it for them, it might cause conflict in convert rate and stuffs
                $error[] = "Create default pricing for new config option sub [{$newConfigOptionSub['optionname']}] in config option ({$configOption['optionname']}): (" . Messages::CURRENCY_AUD_MISSING . ")";
                continue;
            }
            $newConfigOptionSubPricing['currency'] = $currency->id; // If currency AUD was found, then we assign it to the new pricing
            // Perform action, check success status and add message
            if (!$whmcsLocalDb->createConfigOptionSubPricing($newConfigOptionSubPricing)) {
                // If failed, we add error message
                $error[] = "Create default pricing for new sub option [{$newConfigOptionSub['optionname']}] in config option ({$configOption['optionname']}): (" . Messages::UNKNOWN_ERROR . ")";
                continue;
            }

            // If success, then add success message
            $success[] = "Create default pricing for new sub option [{$newConfigOptionSub['optionname']}] in config option ({$configOption['optionname']})";
        }
    }

    return [
        'allConfigGroupsFinal' => $allConfigGroupsFinal,
        'success' => $success,
        'error' => $error,
    ];
}

/** Function inside hook for logics to assign config group to product */
function hookSynergywholesale_Microsoft365_assignConfigGroupToProduct(LocalDB $whmcsLocalDb, string $configOptionPackage, array $allConfigGroupsFinal, int $productId): array
{
    // Although this case might never happen, but if for some reasons the requested package doesn't exist in the list of groups we just created, we add error message and not do anything
    if (empty($allConfigGroupsFinal[$configOptionPackage])) {
        return [
            'status' => 'error',
            'message' => "Assign product to config option group ({$configOptionPackage}). Error: Config Group not exist",
        ];
    }

    // Start action
    $data = [
        'gid' => $allConfigGroupsFinal[$configOptionPackage]['id'],
        'pid' => $productId,
    ];
    if (!$whmcsLocalDb->assignConfigGroupToProduct($data)) {
        return [
            'status' => 'error',
            'message' => "Assign product to config option group ({$configOptionPackage}). Error: Config Group Already Assigned.",
        ];
    }

    return [
        'status' => 'success',
        'message' => "Assign product to config option group ({$configOptionPackage})",
    ];
}