<?php

use WHMCS\Module\Server\SynergywholesaleMicrosoft365\SynergyAPI;
use WHMCS\Module\Server\SynergywholesaleMicrosoft365\WhmcsLocalDb as LocalDB;
use WHMCS\Module\Server\SynergywholesaleMicrosoft365\Messages;
use WHMCS\Module\Server\SynergywholesaleMicrosoft365\ModuleEnums;
use WHMCS\Module\Server\SynergywholesaleMicrosoft365\ServiceStatuses as Status;

function synergywholesale_microsoft365_ConfigOptions()
{
    return [
        'resellerId' => [
            'FriendlyName' => 'SWS Reseller ID',
            'Type' => 'text',
            'Size' => '20',
            'Description' => 'Your Synergy Wholesale Reseller ID',
        ],
        'apiKey' => [
            'FriendlyName' => 'SWS API Key',
            'Type' => 'text',
            'Size' => '100',
            'Description' => 'Your Synergy Wholesale API Key',
        ],
    ];
}

/** Create new tenant and subscriptions in SWS API */
function synergywholesale_microsoft365_CreateAccount($params)
{
    if (empty($params['configoption1']) || empty($params['configoption2'])) {
        return Messages::FAILED_MISSING_MODULE_CONFIGS;
    }

    // New instance of local WHMCS database and Synergy API
    $whmcsLocalDb = new LocalDB();
    $synergyAPI = new SynergyAPI($params['configoption1'], $params['configoption2']);

    // Collect tenant's contact details from module params (firstname, lastname,address1,etc...)
    $clientDetails = $params['clientsdetails'];

    // Re-map the details to match with Synergy API validation
    // For state, we only validate if country is Australia, otherwise just leave it as is
    $clientDetails['state'] = $clientDetails['countryname'] == 'Australia' ? (ModuleEnums::STATE_MAP[$params['clientsdetails']['state']] ?? '') : ($params['clientsdetails']['state'] ?? '');
    $clientDetails['address'] = $params['clientsdetails']['address1'] ?? '';
    $clientDetails['phone'] = $params['clientsdetails']['phonenumberformatted'] ?? '';
    $clientDetails['suburb'] = $params['clientsdetails']['city'] ?? '';

    // Get and organise custom fields from DB
    $customFields = $whmcsLocalDb->getProductAndServiceCustomFields($params['pid'], $params['serviceid']);

    // Get Client Details
    $clientObj = $whmcsLocalDb->getClientById($params['userid']);

    /**
     * VALIDATE IF THIS TENANT HAS BEEN CREATED IN SYNERGY
     */
    if (!empty($customFields['Remote Tenant ID']['value'])) {
        $remoteTenant = $synergyAPI->getTenantDetails($customFields['Remote Tenant ID']['value']);

        if ($remoteTenant) {
            // ConvertTo Array
            $remoteTenant = json_decode(json_encode($remoteTenant), true);

            // Logs for error
            logModuleCall(ModuleEnums::MODULE_NAME, 'CreateAccount', $customFields['Remote Tenant ID']['value'], [
                'status' => $remoteTenant['status'],
                'message' => $remoteTenant['errorMessage'],
            ], Messages::TENANT_EXISTED);

            $tenantId = $customFields['Remote Tenant ID']['value'];
        }
    } else {
        /**
         * START CREATE NEW TENANT IN SYNERGY
         */
        $otherData = [
            'password' => $params['password'],
            'description' => $params['domain'] ?? ($clientObj->description ?? ''),
            'agreement' => !empty($customFields['Customer Agreement']['value']) &&  $customFields['Customer Agreement']['value'] == 'on',
        ];

        // Format and merge array for request
        $newTenantRequest = array_merge($clientDetails, $otherData);
        // Send request to SWS API
        $newTenantResult = $synergyAPI->createClient($newTenantRequest);
        // ConvertTo Array
        $newTenantResult = json_decode(json_encode($newTenantResult), true);

        $formatted = synergywholesale_microsoft365_formatStatusAndMessage($newTenantResult);
        if (!empty($newTenantResult['error']) || empty($newTenantResult['identifier'])) {
            // Logs for error
            logModuleCall(ModuleEnums::MODULE_NAME, 'CreateAccount', $newTenantRequest, [
                'status' => $newTenantResult['status'],
                'error' => $newTenantResult['error'],
            ], $formatted);

            return $formatted;
        }

        // Update new values of Remote Tenant ID, Domain Prefix into custom fields
        $whmcsLocalDb->updateCustomFieldValues($customFields['Remote Tenant ID']['fieldId'], $params['serviceid'], $newTenantResult['identifier']);
        $whmcsLocalDb->updateCustomFieldValues($customFields['Domain Prefix']['fieldId'], $params['serviceid'], $newTenantResult['domainPrefix']);

        // Logs for successful
        logModuleCall(ModuleEnums::MODULE_NAME, 'CreateAccount', $newTenantRequest, [
            'status' => $newTenantResult['status'],
            'message' => $newTenantResult['errorMessage'],
        ], $formatted);

        $tenantId = $newTenantResult['identifier'];
    }

    /**
     * START CREATE NEW SUBSCRIPTION IN SYNERGY
     */
    // Get and organise subscriptionOrder request for SWS API
    $subscriptionOrder = $whmcsLocalDb->getSubscriptionsForAction($params['serviceid'], 'create');
    if (empty($subscriptionOrder)) {
        $formatted = synergywholesale_microsoft365_formatStatusAndMessage(['error' => Messages::FAILED_INVALID_CONFIGURATION]);

        // Logs for error
        logModuleCall(ModuleEnums::MODULE_NAME, 'CreateAccount', ['serviceId' => $params['serviceid']], ['error' => Messages::FAILED_INVALID_CONFIGURATION], $formatted);

        return $formatted;
    }

    // Get remote product's ID that belong to the current service's product
    $eligibleProductIds = $whmcsLocalDb->getRemoteProductIdsFromPackage($params['pid']);
    // Check if all the quantities of config options are 0, that mean user has just placed the order, so we only want to create the tenant, not subscriptions
    $purchasableOrder = [];
    foreach ($subscriptionOrder as $order) {
        // If this productId isn't within the eligible product ID list, the skip to next loop, we don't want to touch subscriptions from previous package
        if (!in_array($order['productId'], $eligibleProductIds)) {
            continue;
        }

        if ($order['quantity'] != 0) {
            $purchasableOrder[] = $order;
        }
    }

    if (empty($purchasableOrder)) {
        return Messages::SUCCESS;
    }

    //Format and merge array for request
    $newSubscriptionsRequest = array_merge(['subscriptionOrder' => $purchasableOrder], ['identifier' => $tenantId]);
    // Send request to SWS API
    $newSubscriptionsResult = $synergyAPI->purchaseSubscription($newSubscriptionsRequest);
    // ConvertTo Array
    $newSubscriptionsResult = json_decode(json_encode($newSubscriptionsResult), true);

    $formatted = synergywholesale_microsoft365_formatStatusAndMessage($newSubscriptionsResult);
    if (!empty($newSubscriptionsResult['error']) || empty($newSubscriptionsResult['subscriptionList'])) {
        // Logs for error
        logModuleCall(ModuleEnums::MODULE_NAME, 'CreateAccount', $newSubscriptionsRequest, [
            'status' => $newSubscriptionsResult['status'],
            'error' => $newSubscriptionsResult['error'],
        ], $formatted);

        return $formatted;
    }

    /**
     * INSERT OR UPDATE NEW REMOTE VALUES TO LOCAL WHMCS DATABASE (Remote Subscriptions)
     */
    // Retrieve the list of new subscriptions purchased from Synergy
    $newSubscriptionList = [];
    foreach ($newSubscriptionsResult['subscriptionList'] as $eachSubscription) {
        $newSubscriptionList[] = $eachSubscription['subscriptionId'];
    }

    // We should check and keep the subscriptions from previous package
    // Retrieve list of custom fields of this service
    $customFields = $whmcsLocalDb->getProductAndServiceCustomFields($params['pid'], $params['serviceid']);
    // Split list of subscription IDs into an array for looping through
    $subscriptionList = explode(', ', $customFields['Remote Subscriptions']['value']) ?? [];

    // If the subscription list is not empty, that means the user runs "create" command with some subscriptions existed
    // We want to add new subscriptions to the list while keep the old subscriptions from previous package as well if applicable
    // So we loop through the list of current subscriptions, if it is not found in the new subscriptions list, that means it is from the previous package, we want to keep it in the data to update in the custom field
    $finalRemoteSubscriptionData = [];

    // Generate data for saving new subscriptions ID as format "productId|subscriptionId"
    foreach ($newSubscriptionsResult['subscriptionList'] as $eachSubscription) {
        $finalRemoteSubscriptionData[] = "{$eachSubscription['productId']}|{$eachSubscription['subscriptionId']}";
    }

    // Loop through current subscription list
    if (!empty($subscriptionList)) {
        foreach ($subscriptionList as $eachSubscription) {
            $subscriptionId = explode('|', $eachSubscription)[1] ?? '';

            if (!in_array($subscriptionId, $newSubscriptionList)) {
                $finalRemoteSubscriptionData[] = $eachSubscription;
            }
        }
    }

    // Update new records to local database
    $whmcsLocalDb->updateCustomFieldValues($customFields['Remote Subscriptions']['fieldId'], $params['serviceid'], implode(', ', $finalRemoteSubscriptionData));

    // Logs for successful
    logModuleCall(ModuleEnums::MODULE_NAME, 'CreateAccount', $newSubscriptionsRequest, [
        'status' => $newSubscriptionsResult['status'],
        'message' => $newSubscriptionsResult['errorMessage'],
    ], $formatted);

    return Messages::SUCCESS;
}

/** Suspend service and subscriptions in SWS API */
function synergywholesale_microsoft365_SuspendAccount($params)
{
    if (empty($params['configoption1']) || empty($params['configoption2'])) {
        return Messages::FAILED_MISSING_MODULE_CONFIGS;
    }

    // New instance of local WHMCS database and Synergy API
    $whmcsLocalDb = new LocalDB();
    $synergyAPI = new SynergyAPI($params['configoption1'], $params['configoption2']);

    // Retrieve list of custom fields of this service
    $customFields = $whmcsLocalDb->getProductAndServiceCustomFields($params['pid'], $params['serviceid']);
    // Split list of subscription IDs into an array for looping through
    $subscriptionList = explode(', ', $customFields['Remote Subscriptions']['value']) ?? [];

    if (empty($subscriptionList)) {
        // Logs for error
        logModuleCall(ModuleEnums::MODULE_NAME, 'SuspendAccount', [
            'productId' => $params['pid'],
            'serviceId' => $params['serviceid'],
        ], ['error' => 'Unable to retrieve remote subscription IDs'], Messages::FAILED_INVALID_CONFIGURATION);

        return Messages::FAILED_INVALID_CONFIGURATION;
    }

    // Get remote product's ID that belong to the current service's product
    $eligibleProductIds = $whmcsLocalDb->getRemoteProductIdsFromPackage($params['pid']);

    $error = [];
    $success = [];
    foreach ($subscriptionList as $eachSubscription) {
        // Get the subscription ID and product ID split from "productId|subscriptionId"
        $subscriptionId = explode('|', $eachSubscription)[1] ?? '';
        $productId = explode('|', $eachSubscription)[0] ?? '';

        // If this productId isn't within the eligible product ID list, the skip to next loop, we don't want to touch subscriptions from previous package
        if (!in_array($productId, $eligibleProductIds)) {
            continue;
        }

        // Check if subscription is currently in Active or Pending, if NOT, then skip it
        $thisSubscription = $synergyAPI->getSubscriptionDetails($subscriptionId);
        // ConvertTo Array
        $thisSubscription = json_decode(json_encode($thisSubscription), true);

        if (!empty($thisSubscription['error']) || empty($thisSubscription)) {
            $formatted = synergywholesale_microsoft365_formatStatusAndMessage($thisSubscription);
            $error[] = "[{$subscriptionId}] {$formatted}";
            continue;
        }

        // Validate if current service status is valid for suspend, if error exists then we skip it
        $validateResult = synergywholesale_microsoft365_getSubscriptionStatusInvalid('Suspend', $thisSubscription['subscriptionStatus'], $subscriptionId);
        if ($validateResult) {
            $error[] = $validateResult;
            continue;
        }

        // Send request for provisioning and format the response for display
        $actionResult = json_decode(json_encode($synergyAPI->suspendSubscription($subscriptionId)), true);
        $formattedMessage = synergywholesale_microsoft365_formatStatusAndMessage($actionResult);
        //NOTE: We don't need to  update subscription's status in local WHMCS database as we only store the required id columns

        // This means the API request wasn't successful, add this ID to $error array for displaying message
        if (!is_numeric(strpos($formattedMessage, '[SUCCESS]'))) {
            $error[] = "[{$subscriptionId}] {$formattedMessage}";
            continue;
        }

        $success[] = "[{$subscriptionId}] {$formattedMessage}";
    }

    // if $error array is not empty, that means one or more subscriptions couldn't be suspended
    if (!empty($error)) {
        $returnMessage = Messages::FAILED_SUSPEND_LIST . implode(', ', $error);

        // Logs for error
        logModuleCall(ModuleEnums::MODULE_NAME, 'SuspendAccount', [
            'productId' => $params['pid'],
            'serviceId' => $params['serviceid'],
        ], $error, $returnMessage);

        return $returnMessage;
    }

    // Update service status in local WHMCS
    $whmcsLocalDb->updateServiceStatus($params['serviceid'], Status::STATUS_SUSPENDED);

    // Logs for success
    logModuleCall(ModuleEnums::MODULE_NAME, 'SuspendAccount', [
        'productId' => $params['pid'],
        'serviceId' => $params['serviceid'],
    ], $success, Messages::OK_SUSPEND . implode(', ', $success));

    return Messages::SUCCESS;
}

/** Unsuspend service and subscriptions in SWS API */
function synergywholesale_microsoft365_UnsuspendAccount($params)
{
    if (empty($params['configoption1']) || empty($params['configoption2'])) {
        return Messages::FAILED_MISSING_MODULE_CONFIGS;
    }

    // New instance of local WHMCS database and Synergy API
    $whmcsLocalDb = new LocalDB();
    $synergyAPI = new SynergyAPI($params['configoption1'], $params['configoption2']);

    // Retrieve list of custom fields of this service
    $customFields = $whmcsLocalDb->getProductAndServiceCustomFields($params['pid'], $params['serviceid']);
    // Split list of subscription IDs into an array for looping through
    $subscriptionList = explode(', ', $customFields['Remote Subscriptions']['value']) ?? [];

    if (empty($subscriptionList)) {
        // Logs for error
        logModuleCall(ModuleEnums::MODULE_NAME, 'UnsuspendAccount', [
            'productId' => $params['pid'],
            'serviceId' => $params['serviceid'],
        ], ['error' => 'Unable to retrieve remote subscription IDs'], Messages::FAILED_INVALID_CONFIGURATION);

        return Messages::FAILED_INVALID_CONFIGURATION;
    }

    // Get remote product's ID that belong to the current service's product
    $eligibleProductIds = $whmcsLocalDb->getRemoteProductIdsFromPackage($params['pid']);

    $error = [];
    $success = [];
    foreach ($subscriptionList as $eachSubscription) {
        // Get the subscription ID split from "productId|subscriptionId"
        $subscriptionId = explode('|', $eachSubscription)[1] ?? '';
        $productId = explode('|', $eachSubscription)[0] ?? '';

        // If this productId isn't within the eligible product ID list, the skip to next loop, we don't want to touch subscriptions from previous package
        if (!in_array($productId, $eligibleProductIds)) {
            continue;
        }

        // Check if subscription is currently in Active or Pending, then skip it
        // If it is currently  terminated, then skip it
        // We only unsuspend if it is suspended
        $thisSubscription = $synergyAPI->getSubscriptionDetails($subscriptionId);
        // ConvertTo Array
        $thisSubscription = json_decode(json_encode($thisSubscription), true);

        if (!empty($thisSubscription['error']) || empty($thisSubscription)) {
            $formatted = synergywholesale_microsoft365_formatStatusAndMessage($thisSubscription);
            $error[] = "[{$subscriptionId}] {$formatted}";
            continue;
        }

        // Validate if current service status is valid for unsuspend, if error exists then we skip it
        $validateResult = synergywholesale_microsoft365_getSubscriptionStatusInvalid('Unsuspend', $thisSubscription['subscriptionStatus'], $subscriptionId);
        if ($validateResult) {
            $error[] = $validateResult;
            continue;
        }

        // Send request for provisioning and format the response for display
        $actionResult = json_decode(json_encode($synergyAPI->unsuspendSubscription($subscriptionId)), true);
        $formattedMessage = synergywholesale_microsoft365_formatStatusAndMessage($actionResult);
        //NOTE: We don't need to  update subscription's status in local WHMCS database as we only store the required id columns

        // This means the API request wasn't successful, add this ID to $error array for displaying message
        if (!is_numeric(strpos($formattedMessage, '[SUCCESS]'))) {
            $error[] = "[{$subscriptionId}] {$formattedMessage}";
            continue;
        }

        $success[] = "[{$subscriptionId}] {$formattedMessage}";
    }

    // if $error array is not empty, that means one or more subscriptions couldn't be suspended
    if (!empty($error)) {
        $returnMessage = Messages::FAILED_UNSUSPEND_LIST . implode(', ', $error);

        // Logs for error
        logModuleCall(ModuleEnums::MODULE_NAME, 'UnsuspendAccount', [
            'productId' => $params['pid'],
            'serviceId' => $params['serviceid'],
        ], $error, $returnMessage);

        return Messages::FAILED_UNSUSPEND_LIST . implode(', ', $error);
    }

    // Update service status in local WHMCS
    $whmcsLocalDb->updateServiceStatus($params['serviceid'], Status::STATUS_ACTIVE);

    // Logs for success
    logModuleCall(ModuleEnums::MODULE_NAME, 'UnsuspendAccount', [
        'productId' => $params['pid'],
        'serviceId' => $params['serviceid'],
    ], $success, Messages::OK_UNSUSPEND . implode(', ', $success));

    return Messages::SUCCESS;
}

/** Terminate service and subscriptions in SWS API */
function synergywholesale_microsoft365_TerminateAccount($params)
{
    if (empty($params['configoption1']) || empty($params['configoption2'])) {
        return Messages::FAILED_MISSING_MODULE_CONFIGS;
    }

    // New instance of local WHMCS database and Synergy API
    $whmcsLocalDb = new LocalDB();
    $synergyAPI = new SynergyAPI($params['configoption1'], $params['configoption2']);

    // Retrieve list of custom fields of this service
    $customFields = $whmcsLocalDb->getProductAndServiceCustomFields($params['pid'], $params['serviceid']);
    // Split list of subscription IDs into an array for looping through
    $subscriptionList = explode(', ', $customFields['Remote Subscriptions']['value']) ?? [];

    if (empty($subscriptionList)) {
        // Logs for error
        logModuleCall(ModuleEnums::MODULE_NAME, 'TerminateAccount', [
            'productId' => $params['pid'],
            'serviceId' => $params['serviceid'],
        ], ['error' => 'Unable to retrieve remote subscription IDs'], Messages::FAILED_INVALID_CONFIGURATION);

        return Messages::FAILED_INVALID_CONFIGURATION;
    }

    // Get remote product's ID that belong to the current service's product
    $eligibleProductIds = $whmcsLocalDb->getRemoteProductIdsFromPackage($params['pid']);

    $error = [];
    $success = [];
    foreach ($subscriptionList as $eachSubscription) {
        // Get the subscription ID split from "productId|subscriptionId"
        $subscriptionId = explode('|', $eachSubscription)[1] ?? '';
        $productId = explode('|', $eachSubscription)[0] ?? '';

        // If this productId isn't within the eligible product ID list, the skip to next loop, we don't want to touch subscriptions from previous package
        if (!in_array($productId, $eligibleProductIds)) {
            continue;
        }

        // Check if subscription is currently in terminated, then skip it
        // If it is currently  active stage or suspended stage, then we terminate
        $thisSubscription = $synergyAPI->getSubscriptionDetails($subscriptionId);
        // ConvertTo Array
        $thisSubscription = json_decode(json_encode($thisSubscription), true);

        if (!empty($thisSubscription['error']) || empty($thisSubscription)) {
            $formatted = synergywholesale_microsoft365_formatStatusAndMessage($thisSubscription);
            $error[] = "[{$subscriptionId}] {$formatted}";
            continue;
        }

        // Validate if current service status is valid for unsuspend, if error exists then we skip it
        $validateResult = synergywholesale_microsoft365_getSubscriptionStatusInvalid('Terminate', $thisSubscription['subscriptionStatus'], $subscriptionId);
        if ($validateResult) {
            $error[] = $validateResult;
            continue;
        }

        // Send request for provisioning and format the response for display
        $actionResult = json_decode(json_encode($synergyAPI->terminateSubscription($subscriptionId)), true);
        $formattedMessage = synergywholesale_microsoft365_formatStatusAndMessage($actionResult);
        //NOTE: We don't need to  update subscription's status in local WHMCS database as we only store the required id columns

        // This means the API request wasn't successful, add this ID to $error array for displaying message
        if (!is_numeric(strpos($formattedMessage, '[SUCCESS]'))) {
            $error[] = "[{$subscriptionId}] {$formattedMessage}";
        }
    }

    // if $error array is not empty, that means one or more subscriptions couldn't be suspended
    if (!empty($error)) {
        $returnMessage = Messages::FAILED_TERMINATE_LIST . implode(', ', $error);

        // Logs for error
        logModuleCall(ModuleEnums::MODULE_NAME, 'TerminateAccount', [
            'productId' => $params['pid'],
            'serviceId' => $params['serviceid'],
        ], $error, $returnMessage);

        return Messages::FAILED_TERMINATE_LIST . implode(', ', $error);
    }

    // Update service status in local WHMCS
    $whmcsLocalDb->updateServiceStatus($params['serviceid'], Status::STATUS_CANCELLED);

    // Logs for success
    logModuleCall(ModuleEnums::MODULE_NAME, 'TerminateAccount', [
        'productId' => $params['pid'],
        'serviceId' => $params['serviceid'],
    ], $success, Messages::OK_TERMINATE);

    return Messages::SUCCESS;
}

/** Perform change plan (subscriptions and quantities) for this tenant (service)
 * @param $params
 * @return string
 */
function synergywholesale_microsoft365_ChangePackage($params)
{
    if (empty($params['configoption1']) || empty($params['configoption2'])) {
        return Messages::FAILED_MISSING_MODULE_CONFIGS;
    }

    // New instance of local WHMCS database and Synergy API
    $whmcsLocalDb = new LocalDB();
    $synergyAPI = new SynergyAPI($params['configoption1'], $params['configoption2']);

    // Get existing subscriptions (custom fields) and overall subscriptions (config options) from local WHMCS DB
    $existingSubscriptions = $whmcsLocalDb->getSubscriptionsForAction($params['serviceid'], 'changePlan', $params['pid']);
    $overallSubscriptions = $whmcsLocalDb->getSubscriptionsForAction($params['serviceid'], 'compare');

    // In case of tenant has switched to a new package, we need to set quantities of subscriptions from previous package to 0, so that we can terminate them in the request below
    $requestSubscriptions = synergywholesale_microsoft365_checkAndFilterPackageChange($params['configoptions'], $overallSubscriptions);

    $subscriptionsToCreate = [];

    $error = [];
    $success = [];
    foreach ($requestSubscriptions as $row) {
        $productId = $row['productId'];

        /** If this config option doesn't exist in custom fields, that means this subscription hasn't been created in Synergy */
        if (empty($existingSubscriptions[$productId])) {
            // If quantity = 0, that means user doesn't want to create new subscription for this config option, we can just skip it
            if ($row['quantity'] == 0) {
                continue;
            }

            // If quantity is negative, we return error
            if ($row['quantity'] < 0) {
                $error[] = " [NEW SUBSCRIPTION] [{$productId}] Invalid quantity provided";
                continue;
            }

            // Otherwise, we add this config option to an array to purchase at the end
            $subscriptionsToCreate[] = $row;
            continue;
        }

        /** Otherwise if this config option exists in custom fields, that mean this subscription already provisioned in Synergy, now we check 'quantity' to see if we need to terminate, unsuspend or update quantity for this subscription */
        $existingSubscriptionId = $existingSubscriptions[$productId]['subscriptionId'];

        // Get current details of subscription from Synergy API
        $thisSubscription = $synergyAPI->getSubscriptionDetails($existingSubscriptionId);
        // ConvertTo Array
        $thisSubscription = json_decode(json_encode($thisSubscription), true);

        if (!empty($thisSubscription['error']) || empty($thisSubscription)) {
            $formatted = synergywholesale_microsoft365_formatStatusAndMessage($thisSubscription);
            $error[] = "[CURRENT SUBSCRIPTION] [{$existingSubscriptionId}] {$formatted}";
            continue;
        }

        foreach ($thisSubscription['tasks'] as $task) {
            if (in_array($task['status'],['InProgress', 'Pending'])) {
                $error[] = "[CURRENT SUBSCRIPTION] [{$existingSubscriptionId}] Failed to update quantity due to pending subscription tasks.";
                break;
            }
        }

        // If quantity = 0, that means user wants to terminate this subscription
        if ($row['quantity'] == 0) {
            // Validate if this subscription status is valid for termination
            $validateStatus = synergywholesale_microsoft365_getSubscriptionStatusInvalid('Terminate', $thisSubscription['subscriptionStatus'], $existingSubscriptionId);

            // If error status exists, we add it to error logs
            if ($validateStatus) {
                continue;
            }

            // If error status is NULL, then we terminate this subscription
            $actionResult = json_decode(json_encode($synergyAPI->terminateSubscription($existingSubscriptionId)), true);
            $formattedMessage = synergywholesale_microsoft365_formatStatusAndMessage($actionResult);

            // This means the API request wasn't successful, add this ID to $error array for displaying message
            if (!is_numeric(strpos($formattedMessage, '[SUCCESS]'))) {
                $error[] = "[{$existingSubscriptionId}] {$formattedMessage}";
                continue;
            }

            $success[] = "[TERMINATE SUBSCRIPTION] [{$existingSubscriptionId}] {$formattedMessage}";
            continue;
        }

        // If quantity is negative, we return error
        if ($row['quantity'] < 0) {
            $error[] = "[{$existingSubscriptionId}] Invalid quantity provided";
            continue;
        }

        $updateQuantity = false;
        // Check and handle action based on remote status of subscription
        switch ($thisSubscription['subscriptionStatus']) {
            // If service is suspended or cancelled, we unsuspend and update seat
            case Status::STATUS_SUSPENDED:
                // In case the service is suspended or terminated, we shouldn't do anything, just add log message that customer should unsuspend it before performing change package action
                $error[] = "[{$existingSubscriptionId}] This service is currently {$thisSubscription['subscriptionStatus']}. Please unsuspend the service before proceeding the change package action.";

                break;
            // if service is active or pending, we can just update seat accordingly
            case Status::STATUS_ACTIVE:
            case Status::STATUS_PENDING:
                $updateQuantity = true;
                break;
            // If service is already deleted, we purchase new subscription for that product ID
            // NOTE: In this case when purchase new subscription for that pre-own product, Synergy would re-activate the old subscription, so we don't have to delete or update the subscription ID in custom fields
            case Status::STATUS_CANCELLED:
            case Status::STATUS_DELETED:
                $subscriptionsToCreate[] = $row;
                break;
        }

        // If UpdateQuantity is TRUE, we perform update quantity for this subscription
        if ($updateQuantity) {
            // If quantity = current quantity in Synergy, that means user doesn't change seat on this subscription, just ignore
            if ($row['quantity'] == $thisSubscription['quantity']) {
                $success[] = "[{$existingSubscriptionId}] Quantity hasn't changed.";
                continue;
            }

            $actionResult = json_decode(json_encode($synergyAPI->updateSubscriptionQuantity([
                'identifier' => $existingSubscriptionId,
                'quantity' => $row['quantity'],
            ])), true);
            $formattedMessage = synergywholesale_microsoft365_formatStatusAndMessage($actionResult);

            // This means the API request wasn't successful, add this ID to $error array for displaying message
            if (!is_numeric(strpos($formattedMessage, '[SUCCESS]'))) {
                $error[] = "[{$existingSubscriptionId}] {$formattedMessage}";
                continue;
            }

            $success[] = "[CHANGE QUANTITY SUBSCRIPTION] [{$existingSubscriptionId}] Successfully updated to {$row['quantity']} seat(s).";
        }
    }

    /** Now we want to check if $subscriptionsToCreate not empty, then we purchase subscriptions */
    if (!empty($subscriptionsToCreate)) {
        $tenantId = $params['customfields']['Remote Tenant ID'];

        // Send API request to SWS for purchasing new subscription(s)
        $purchaseResult = $synergyAPI->purchaseSubscription(array_merge(['subscriptionOrder' => $subscriptionsToCreate], ['identifier' => $tenantId]));
        // Convert to array
        $purchaseResult = json_decode(json_encode($purchaseResult), true);

        if (!empty($purchaseResult['error']) || empty($purchaseResult['subscriptionList'])) {
            $error[] = "[NEW SUBSCRIPTION] " . synergywholesale_microsoft365_formatStatusAndMessage($purchaseResult);
        } else {
            $success[] = "[NEW SUBSCRIPTION] " . synergywholesale_microsoft365_formatStatusAndMessage($purchaseResult);

            // Compare data to check which subscriptions are newly purchased and which ones are re-activated
            $updateValue = [];
            foreach ($purchaseResult['subscriptionList'] as $eachSubscription) {
                // Check if this product ID already had a subscription and that subscription ID is equal to the API result's subscription ID, then we ignore because they would be the same anyway
                // Only add the new subscriptions to the custom field values or update the existing records if their subscription IDs are different from each other
                if ($existingSubscriptions[$eachSubscription['productId']]) {
                    if ($existingSubscriptions[$eachSubscription['productId']]['subscriptionId'] == $eachSubscription['subscriptionId']) {
                        continue;
                    }

                    // Update if IDs are different and then add to array for Database update
                    $existingSubscriptions[$eachSubscription['productId']]['subscriptionId'] = $eachSubscription['subscriptionId'];
                    continue;
                }

                // At this part, it means this new purchase subscription hasn't been created before in the custom fields, we add it as usual
                $existingSubscriptions[$eachSubscription['productId']] = [
                    'subscriptionId' => $eachSubscription['subscriptionId'],
                ];
            }

            // Generate data for saving new subscriptions ID as format "productId|subscriptionId"
            foreach ($existingSubscriptions as $queryProductId => $subscriptionId) {
                if (empty($queryProductId) || empty($subscriptionId['subscriptionId'])) {
                    continue;
                }

                $updateValue[] = "{$queryProductId}|{$subscriptionId['subscriptionId']}";
            }

            // Retrieve the custom fields of this service
            $customFields = $whmcsLocalDb->getProductAndServiceCustomFields($params['pid'], $params['serviceid']);
            // Update records to local database
            $whmcsLocalDb->updateCustomFieldValues($customFields['Remote Subscriptions']['fieldId'], $params['serviceid'], implode(', ', $updateValue));
        }
    }

    // If there is error set during the process, we just put them into the response
    if (!empty($error)) {
        // Even in case of error, we still want to see if any part of the process was successful
        $logResult = array_merge($success, $error);
        $returnMessage = Messages::FAILED_CHANGE_PLAN . ' Error: ' . implode(', ', $logResult);

        // Logs for error
        logModuleCall(ModuleEnums::MODULE_NAME, 'ChangePackage', [
            'productId' => $params['pid'],
            'serviceId' => $params['serviceid'],
        ], $logResult, $returnMessage);

        return $returnMessage;
    }

    // Logs for success
    logModuleCall(ModuleEnums::MODULE_NAME, 'ChangePackage', [
        'productId' => $params['pid'],
        'serviceId' => $params['serviceid'],
    ], $success, Messages::OK_CHANGE_PLAN . implode(', ', $success));

    return Messages::SUCCESS;
}

function synergywholesale_microsoft365_ClientArea($params)
{
    if (empty($params['configoption1']) || empty($params['configoption2'])) {
        return Messages::FAILED_MISSING_MODULE_CONFIGS;
    }

    // New instance of local WHMCS database and Synergy API
    $whmcsLocalDb = new LocalDB();

    $currentProductLocal = $whmcsLocalDb->getProductById($params['pid']);
    $currentService = $whmcsLocalDb->getServiceById($params['serviceid']);

    // By default we want to take AUD (id 1), or we can take id of currency from params
    $currency = $whmcsLocalDb->getCurrencyById($params['clientdetails']['currency'] ?? 1);
    $customFields = $whmcsLocalDb->getProductAndServiceCustomFields($params['pid'], $params['serviceid']);
    $configOptions = $whmcsLocalDb->getSubscriptionsForAction($params['serviceid'], 'compare');

    // We only want to display Domain Prefix into client area
    $customFields = collect($customFields)->only('Domain Prefix')->toArray();
    $customFields['Domain Prefix']['value'] = "{$customFields['Domain Prefix']['value']}.onmicrosoft.com";

    // Retrieve the list of current product's config option IDs
    $currentProductConfigOptionIds = $whmcsLocalDb->getRemoteProductIdsFromPackage($params['pid']);

    return [
        'tabOverviewReplacementTemplate' => 'clientarea',
        'vars' => [
            'service' => $currentService,
            'product' => $currentProductLocal,
            'customFields' => $customFields,
            'configOptions' => $configOptions,
            'currentProductConfigOptionIds' => $currentProductConfigOptionIds,
            'domainPassword' => $params['password'],
            'serviceIsOverdue' => strtotime('now') > strtotime($params['model']['nextduedate']),
            'billing' => [
                'Registration Date' => [
                    'value' => date_format(date_create($params['model']['regdate']), "d M Y"),
                ],

                'Recurring Amount' => [
                    'value' => "{$params['model']['amount']} {$currency->code}",
                ],

                'Billing Cycle' => [
                    'value' => $params['model']['billingcycle'],
                ],

                'Next Due Date' => [
                    'value' => date_format(date_create($params['model']['nextduedate']), "d M Y"),
                ],
                'Payment Method' => [
                    'value' => $params['model']['paymentmethod']
                ],
            ]
        ],
    ];
}

/**
 * Add extra messages to Admin Area that some custom fields shouldn't be edited
 */
function synergywholesale_microsoft365_AdminServicesTabFields($params)
{
    return [
        "<span style='color: red; font-weight: bold; height: 100%'>IMPORTANT</span>" => "<span style='font-weight: bold'>PLEASE NOTE: CHANGING <span style='text-decoration: underline; color: red;'>THE REMOTE TENANT ID</span>, <span style='text-decoration: underline; color: red;'>DOMAIN PREFIX</span> OR <span style='text-decoration: underline; color: red;'>REMOTE SUBSCRIPTION FIELDS</span> IS NOT RECOMMENDED AND CAN CAUSE ISSUES WITH THE CONNECTION TO YOUR CLIENT/SUBSCRIPTIONS IN SYNERGY WHOLESALE</span>",
        '' => "<span style='font-weight: bold'>PLEASE ONLY MODIFY IF YOU ARE CERTAIN ABOUT THE CHANGES BEING MADE</span>",
    ];
}

function synergywholesale_microsoft365_metaData()
{
    return [
        'DisplayName' => 'Synergy Wholesale Microsoft 365',
    ];
}

/**
 * CUSTOM FUNCTIONS FOR USING INTERNALLY
 */

function synergywholesale_microsoft365_checkAndFilterPackageChange($filteredList, $fullList)
{
    // Get list of Product IDs within the current config group
    $filteredProductIds = array_keys($filteredList);

    $return = [];
    foreach ($fullList as $eachProduct) {
        // If this product is not in the filtered list, that means tenant has switched package, and we need to reset quantity of it to 0
        if (!in_array($eachProduct['productId'], $filteredProductIds)) {
            $eachProduct['quantity'] = 0;
        }

        // If this product is in filtered list, that means we just hold it back in the request data, no need to set quantity to 0
        $return[] = $eachProduct;
    }

    return $return;
}

/** Validate subscription status for provisioning actions */
function synergywholesale_microsoft365_getSubscriptionStatusInvalid($action, $status, $subscriptionId)
{
    switch ($action) {
        case 'Suspend':
            // Message if subscription is already terminated
            if (in_array($status, Status::TERMINATED_STATUS)) {
                return "[{$subscriptionId}] Subscription already terminated.";
            }

            // Message if subscription is already suspended
            if (in_array($status, Status::SUSPENDED_STATUS)) {
                return "[{$subscriptionId}] Subscription already suspended.";
            }

            // Message for other unrecognised statuses
            if (!in_array($status, Status::ACTIVE_STATUS)) {
                return "[{$subscriptionId}] Subscription not in active status.";
            }
            break;
        case 'Unsuspend':
            // Message if subscription is already active or pending
            if (in_array($status, Status::ACTIVE_STATUS)) {
                return "[{$subscriptionId}] Subscription already active.";
            }

            // Message for other unrecognised statuses
            if (!in_array($status, Status::SUSPENDED_STATUS)) {
                return "[{$subscriptionId}] Subscription not in suspended status.";
            }
            break;
        case 'Terminate':
            // Message if subscription is already terminated
            if (in_array($status, Status::TERMINATED_STATUS)) {
                return "[{$subscriptionId}] Subscription already terminated.";
            }
            break;
        default:
            return "[{$subscriptionId}] Unable to validate service status.";
    }

    return null;
}

/** Accept response from API calls and format it for display message */
function synergywholesale_microsoft365_formatStatusAndMessage($apiResult)
{
    if (is_null($apiResult)) {
        return 'Fatal Error.';
    }

    // If 'error' is set, that means the SWS API or LocalDB failed to perform action
    // If not, that means the process was successful, then we return code 'SUCCESS' along with the message (SWS API set it as 'errorMessage' even if successful)
    return $apiResult['error'] ? ($apiResult['status'] ? "[{$apiResult['status']}] {$apiResult['error']}" : "{$apiResult['error']}") : "[SUCCESS] {$apiResult['errorMessage']}.";

}