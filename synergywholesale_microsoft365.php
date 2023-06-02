<?php
use WHMCS\Database\Capsule as DB;

use WHMCS\Module\Server\SynergywholesaleMicrosoft365\SynergyAPI;
use WHMCS\Module\Server\SynergywholesaleMicrosoft365\WhmcsLocalDb as LocalDB;

const SUCCESS = 'success';
const MODULE_NAME = 'synergywholesale_microsoft365';
const OK_PROVISION = '[SUCCESS] Successfully provisioned new service.';
const TENANT_EXISTED = '[FAILED] This tenant has already been created.';
const OK_CREATE_TENANT = '[SUCCESS] Successfully created new tenant.';
const OK_SUSPEND = '[SUCCESS] Successfully suspended service.';
const OK_UNSUSPEND = '[SUCCESS] Successfully unsuspended service.';
const OK_TERMINATE = '[SUCCESS] Successfully terminated service.';
const OK_CHANGE_PLAN = '[SUCCESS] Successfully changed plan for service.';
const FAILED_SUSPEND_LIST = '[FAILED] Failed to suspend the following subscriptions: ';
const FAILED_UNSUSPEND_LIST = '[FAILED] Failed to unsuspend the following subscriptions: ';
const FAILED_TERMINATE_LIST = '[FAILED] Failed to terminate the following subscriptions: ';
const FAILED_CHANGE_PLAN = '[FAILED] Failed to change plan for service.';
const FAILED_INVALID_CONFIGURATION = '[FAILED] Unable to perform action due to invalid configuration.';
const STATUS_DELETED = 'Deleted';
const STATUS_CANCELLED = 'Cancelled';
const STATUS_ACTIVE = 'Active';
const STATUS_SUSPENDED = 'Suspended';
const STATUS_STAFF_SUSPENDED = 'Suspended By Staff';
const STATUS_PENDING = 'Pending';
const ACTIVE_STATUS = [
    STATUS_ACTIVE,
    STATUS_PENDING,
];
const SUSPENDED_STATUS = [
    STATUS_SUSPENDED,
    STATUS_STAFF_SUSPENDED,
    STATUS_DELETED,
    STATUS_CANCELLED,
];

const TERMINATED_STATUS = [
    STATUS_DELETED,
    STATUS_CANCELLED,
];

const STATE_MAP = [
    'Australian Capital Territory' => 'ACT',
    'New South Wales' => 'NSW',
    'Northern Territory' => 'NT',
    'Queensland' => 'QLD',
    'South Australia' => 'SA',
    'Tasmania' => 'TAS',
    'Victoria' => 'VIC',
    'Western Australia' => 'WA',
];

// Database tables
const WHMCS_HOSTING_TABLE = 'tblhosting';
const WHMCS_PRODUCT_TABLE = 'tblproducts';
const WHMCS_CURRENCY_TABLE = 'tblcurrencies';

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
    // New instance of local WHMCS database and Synergy API
    $whmcsLocalDb = new LocalDB();
    $synergyAPI = new SynergyAPI($params['configoption1'], $params['configoption2']);

    // Collect tenant's contact details from module params (firstname, lastname,address1,etc...)
    $clientDetails = $params['clientsdetails'];

    // Re-map the details to match with Synergy API validation
    $clientDetails['state'] = STATE_MAP[$params['clientsdetails']['state']] ?? '';
    $clientDetails['address'] = $params['clientsdetails']['address1'] ?? '';
    $clientDetails['phone'] = $params['clientsdetails']['phonenumberformatted'] ?? '';
    $clientDetails['suburb'] = $params['clientsdetails']['city'] ?? '';

    // Get and organise custom fields from DB
    $customFields = $whmcsLocalDb->getProductAndServiceCustomFields($params['pid'], $params['serviceid']);

    // Get Client Details
    $clientObj = $whmcsLocalDb->getById(LocalDB::WHMCS_TENANT_TABLE, $params['userid']);

    /**
     * VALIDATE IF THIS TENANT HAS BEEN CREATED IN SYNERGY
     */

    if (!empty($customFields['Remote Tenant ID']['value'])) {
        $remoteTenant = $synergyAPI->getById('subscriptionGetClient', $customFields['Remote Tenant ID']['value']);

        if ($remoteTenant) {
            // ConvertTo Array
            $remoteTenant = json_decode(json_encode($remoteTenant), true);

            // Logs for error
            logModuleCall(MODULE_NAME, 'CreateAccount', $customFields['Remote Tenant ID']['value'], [
                'status' => $remoteTenant['status'],
                'message' => $remoteTenant['errorMessage'],
            ], TENANT_EXISTED);

            $tenantId = $customFields['Remote Tenant ID']['value'];
        }

    } else {
        /**
         * START CREATE NEW TENANT IN SYNERGY
         */
        $otherData = [
            'password' => $params['password'],
            'description' => $clientObj->description ?? '',
            'agreement' => !empty($customFields['Customer Agreement']['value']) &&  $customFields['Customer Agreement']['value'] == 'on',
        ];

        // Format and merge array for request
        $newTenantRequest = array_merge($clientDetails, $otherData);
        // Send request to SWS API
        $newTenantResult = $synergyAPI->crudOperations('subscriptionCreateClient', $newTenantRequest);
        // ConvertTo Array
        $newTenantResult = json_decode(json_encode($newTenantResult), true);

        $formatted = synergywholesale_microsoft365_formatStatusAndMessage($newTenantResult);
        if ($newTenantResult['error'] || !$newTenantResult['identifier']) {

            // Logs for error
            logModuleCall(MODULE_NAME, 'CreateAccount', $newTenantRequest, [
                'status' => $newTenantResult['status'],
                'error' => $newTenantResult['error'],
            ], $formatted);

            return $formatted;
        }

        // Update new values of Remote Tenant ID, Domain Prefix into custom fields
        $whmcsLocalDb->updateCustomFieldValues($customFields['Remote Tenant ID']['fieldId'], $params['serviceid'], $newTenantResult['identifier']);
        $whmcsLocalDb->updateCustomFieldValues($customFields['Domain Prefix']['fieldId'], $params['serviceid'], $newTenantResult['domainPrefix']);

        // Logs for successful
        logModuleCall(MODULE_NAME, 'CreateAccount', $newTenantRequest, [
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
        $formatted = synergywholesale_microsoft365_formatStatusAndMessage(['error' => FAILED_INVALID_CONFIGURATION]);

        // Logs for error
        logModuleCall(MODULE_NAME, 'CreateAccount', ['serviceId' => $params['serviceid']], ['error' => FAILED_INVALID_CONFIGURATION], $formatted);

        return $formatted;
    }

    // Check if all the quantities of config options are 0, that mean user has just placed the order, so we only want to create the tenant, not subscriptions
    $purchasableOrder = [];
    foreach ($subscriptionOrder as $order) {
        if ($order['quantity'] != 0) {
            $purchasableOrder[] = $order;
        }
    }
    if (empty($purchasableOrder)) {
        return SUCCESS;
    }

    //Format and merge array for request
    $newSubscriptionsRequest = array_merge(['subscriptionOrder' => $purchasableOrder], ['identifier' => $tenantId]);
    // Send request to SWS API
    $newSubscriptionsResult = $synergyAPI->crudOperations('subscriptionPurchase', $newSubscriptionsRequest);
    // ConvertTo Array
    $newSubscriptionsResult = json_decode(json_encode($newSubscriptionsResult), true);

    $formatted = synergywholesale_microsoft365_formatStatusAndMessage($newSubscriptionsResult);
    if ($newSubscriptionsResult['error'] || !$newSubscriptionsResult['subscriptionList']) {
        // Logs for error
        logModuleCall(MODULE_NAME, 'CreateAccount', $newSubscriptionsRequest, [
            'status' => $newSubscriptionsResult['status'],
            'error' => $newSubscriptionsResult['error'],
        ], $formatted);

        return $formatted;
    }

    /**
     * INSERT OR UPDATE NEW REMOTE VALUES TO LOCAL WHMCS DATABASE (Remote Subscriptions)
     */

    // Generate data for saving new subscriptions ID as format "productId|subscriptionId"
    $remoteSubscriptionData = [];
    foreach ($newSubscriptionsResult['subscriptionList'] as $eachSubscription) {
        $remoteSubscriptionData[] = "{$eachSubscription['productId']}|{$eachSubscription['subscriptionId']}";
    }

    // Update new records to local database
    $whmcsLocalDb->updateCustomFieldValues($customFields['Remote Subscriptions']['fieldId'], $params['serviceid'], implode(', ', $remoteSubscriptionData));

    // Logs for successful
    logModuleCall(MODULE_NAME, 'CreateAccount', $newSubscriptionsRequest, [
        'status' => $newSubscriptionsResult['status'],
        'message' => $newSubscriptionsResult['errorMessage'],
    ], $formatted);

    return SUCCESS;
}

/** Suspend service and subscriptions in SWS API */
function synergywholesale_microsoft365_SuspendAccount($params)
{
    // New instance of local WHMCS database and Synergy API
    $whmcsLocalDb = new LocalDB();
    $synergyAPI = new SynergyAPI($params['configoption1'], $params['configoption2']);

    // Retrieve list of custom fields of this service
    $customFields = $whmcsLocalDb->getProductAndServiceCustomFields($params['pid'], $params['serviceid']);
    // Split list of subscription IDs into an array for looping through
    $subscriptionList = explode(', ', $customFields['Remote Subscriptions']['value']) ?? [];

    if (empty($subscriptionList)) {
        // Logs for error
        logModuleCall(MODULE_NAME, 'SuspendAccount', [
            'productId' => $params['pid'],
            'serviceId' => $params['serviceid'],
        ], ['error' => 'Unable to retrieve remote subscription IDs'], FAILED_INVALID_CONFIGURATION);

        return FAILED_INVALID_CONFIGURATION;
    }

    $error = [];
    $success = [];
    foreach ($subscriptionList as $eachSubscription) {
        // Get the subscription ID split from "productId|subscriptionId"
        $subscriptionId = explode('|', $eachSubscription)[1] ?? '';

        // Check if subscription is currently in Active or Pending, if NOT, then skip it
        $thisSubscription = $synergyAPI->getById('subscriptionGetDetails', $subscriptionId);
        // ConvertTo Array
        $thisSubscription = json_decode(json_encode($thisSubscription), true);

        if ($thisSubscription['error'] || !$thisSubscription) {
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
        $actionResult = json_decode(json_encode($synergyAPI->provisioningActions('subscriptionSuspend', $subscriptionId)), true);
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
        $returnMessage = FAILED_SUSPEND_LIST . implode(', ', $error);

        // Logs for error
        logModuleCall(MODULE_NAME, 'SuspendAccount', [
            'productId' => $params['pid'],
            'serviceId' => $params['serviceid'],
        ], $error, $returnMessage);

        return $returnMessage;
    }

    // Update service status in local WHMCS
    $whmcsLocalDb->update(WHMCS_HOSTING_TABLE, $params['serviceid'], ['domainstatus' => STATUS_SUSPENDED]);

    // Logs for success
    logModuleCall(MODULE_NAME, 'SuspendAccount', [
        'productId' => $params['pid'],
        'serviceId' => $params['serviceid'],
    ], $success, OK_SUSPEND . implode(', ', $success));

    return SUCCESS;
}

/** Unsuspend service and subscriptions in SWS API */
function synergywholesale_microsoft365_UnsuspendAccount($params)
{
    // New instance of local WHMCS database and Synergy API
    $whmcsLocalDb = new LocalDB();
    $synergyAPI = new SynergyAPI($params['configoption1'], $params['configoption2']);

    // Retrieve list of custom fields of this service
    $customFields = $whmcsLocalDb->getProductAndServiceCustomFields($params['pid'], $params['serviceid']);
    // Split list of subscription IDs into an array for looping through
    $subscriptionList = explode(', ', $customFields['Remote Subscriptions']['value']) ?? [];

    if (empty($subscriptionList)) {
        // Logs for error
        logModuleCall(MODULE_NAME, 'UnsuspendAccount', [
            'productId' => $params['pid'],
            'serviceId' => $params['serviceid'],
        ], ['error' => 'Unable to retrieve remote subscription IDs'], FAILED_INVALID_CONFIGURATION);

        return FAILED_INVALID_CONFIGURATION;
    }

    $error = [];
    $success = [];
    foreach ($subscriptionList as $eachSubscription) {
        // Get the subscription ID split from "productId|subscriptionId"
        $subscriptionId = explode('|', $eachSubscription)[1] ?? false;

        // Check if subscription is currently in Active or Pending, then skip it
        // If it is currently  terminated, then skip it
        // We only unsuspend if it is suspended
        $thisSubscription = $synergyAPI->getById('subscriptionGetDetails', $subscriptionId);
        // ConvertTo Array
        $thisSubscription = json_decode(json_encode($thisSubscription), true);

        if ($thisSubscription['error'] || !$thisSubscription) {
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
        $actionResult = json_decode(json_encode($synergyAPI->provisioningActions('subscriptionUnsuspend', $subscriptionId)), true);
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
        $returnMessage = FAILED_UNSUSPEND_LIST . implode(', ', $error);

        // Logs for error
        logModuleCall(MODULE_NAME, 'UnsuspendAccount', [
            'productId' => $params['pid'],
            'serviceId' => $params['serviceid'],
        ], $error, $returnMessage);

        return FAILED_UNSUSPEND_LIST . implode(', ', $error);
    }

    // Update service status in local WHMCS
    $whmcsLocalDb->update(WHMCS_HOSTING_TABLE, $params['serviceid'], ['domainstatus' => STATUS_ACTIVE]);

    // Logs for success
    logModuleCall(MODULE_NAME, 'UnsuspendAccount', [
        'productId' => $params['pid'],
        'serviceId' => $params['serviceid'],
    ], $success, OK_UNSUSPEND . implode(', ', $success));

    return SUCCESS;
}

/** Terminate service and subscriptions in SWS API */
function synergywholesale_microsoft365_TerminateAccount($params)
{
    // New instance of local WHMCS database and Synergy API
    $whmcsLocalDb = new LocalDB();
    $synergyAPI = new SynergyAPI($params['configoption1'], $params['configoption2']);

    // Retrieve list of custom fields of this service
    $customFields = $whmcsLocalDb->getProductAndServiceCustomFields($params['pid'], $params['serviceid']);
    // Split list of subscription IDs into an array for looping through
    $subscriptionList = explode(', ', $customFields['Remote Subscriptions']['value']) ?? [];

    if (empty($subscriptionList)) {
        // Logs for error
        logModuleCall(MODULE_NAME, 'TerminateAccount', [
            'productId' => $params['pid'],
            'serviceId' => $params['serviceid'],
        ], ['error' => 'Unable to retrieve remote subscription IDs'], FAILED_INVALID_CONFIGURATION);

        return FAILED_INVALID_CONFIGURATION;
    }

    $error = [];
    $success = [];
    foreach ($subscriptionList as $eachSubscription) {
        // Get the subscription ID split from "productId|subscriptionId"
        $subscriptionId = explode('|', $eachSubscription)[1] ?? false;

        // Check if subscription is currently in terminated, then skip it
        // If it is currently  active stage or suspended stage, then we terminate
        $thisSubscription = $synergyAPI->getById('subscriptionGetDetails', $subscriptionId);
        // ConvertTo Array
        $thisSubscription = json_decode(json_encode($thisSubscription), true);

        if ($thisSubscription['error'] || !$thisSubscription) {
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
        $actionResult = json_decode(json_encode($synergyAPI->provisioningActions('subscriptionTerminate', $subscriptionId)), true);
        $formattedMessage = synergywholesale_microsoft365_formatStatusAndMessage($actionResult);
        //NOTE: We don't need to  update subscription's status in local WHMCS database as we only store the required id columns

        // This means the API request wasn't successful, add this ID to $error array for displaying message
        if (!is_numeric(strpos($formattedMessage, '[SUCCESS]'))) {
            $error[] = "[{$subscriptionId}] {$formattedMessage}";
        }

    }

    // if $error array is not empty, that means one or more subscriptions couldn't be suspended
    if (!empty($error)) {
        $returnMessage = FAILED_TERMINATE_LIST . implode(', ', $error);

        // Logs for error
        logModuleCall(MODULE_NAME, 'TerminateAccount', [
            'productId' => $params['pid'],
            'serviceId' => $params['serviceid'],
        ], $error, $returnMessage);

        return FAILED_TERMINATE_LIST . implode(', ', $error);
    }

    // Update service status in local WHMCS
    $whmcsLocalDb->update(WHMCS_HOSTING_TABLE, $params['serviceid'], ['domainstatus' => STATUS_CANCELLED]);

    // Logs for success
    logModuleCall(MODULE_NAME, 'TerminateAccount', [
        'productId' => $params['pid'],
        'serviceId' => $params['serviceid'],
    ], $success, OK_TERMINATE);

    return SUCCESS;

}

/** Perform change plan (subscriptions and quantities) for this tenant (service)
 * @param $params
 * @return string
 */
function synergywholesale_microsoft365_ChangePackage($params)
{
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
        $thisSubscription = $synergyAPI->getById('subscriptionGetDetails', $existingSubscriptionId);
        // ConvertTo Array
        $thisSubscription = json_decode(json_encode($thisSubscription), true);

        if ($thisSubscription['error'] || !$thisSubscription) {
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
            $actionResult = json_decode(json_encode($synergyAPI->provisioningActions('subscriptionTerminate', $existingSubscriptionId)), true);
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
            case STATUS_SUSPENDED:
            case STATUS_CANCELLED:
                $actionResult = json_decode(json_encode($synergyAPI->provisioningActions('subscriptionUnsuspend', $existingSubscriptionId)), true);
                $formattedMessage = synergywholesale_microsoft365_formatStatusAndMessage($actionResult);

                // This means the API request wasn't successful, add this ID to $error array for displaying message
                if (!is_numeric(strpos($formattedMessage, '[SUCCESS]'))) {
                    $error[] = "[{$existingSubscriptionId}] {$formattedMessage}";
                    break;
                }

                $success[] = "[UNSUSPEND SUBSCRIPTION] [{$existingSubscriptionId}] {$formattedMessage}";

                // After unsuspend the subscription, we would proceed to Update Quantity part below the switch
                $updateQuantity = true;
                break;

            // if service is active or pending, we can just update seat accordingly
            case STATUS_ACTIVE:
            case STATUS_PENDING:
                $updateQuantity = true;
                break;

            // If service is already deleted, we purchase new subscription for that product ID
            // NOTE: In this case when purchase new subscription for that pre-own product, Synergy would re-activate the old subscription, so we don't have to delete or update the subscription ID in custom fields
            case STATUS_DELETED:
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

            $actionResult = json_decode(json_encode($synergyAPI->crudOperations('subscriptionUpdateQuantity', [
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
        $purchaseResult = $synergyAPI->crudOperations('subscriptionPurchase', array_merge(['subscriptionOrder' => $subscriptionsToCreate], ['identifier' => $tenantId]));
        // Convert to array
        $purchaseResult = json_decode(json_encode($purchaseResult), true);

        if ($purchaseResult['error'] || !$purchaseResult['subscriptionList']) {
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
        $returnMessage = FAILED_CHANGE_PLAN . ' Error: ' . implode(', ', $logResult);

        // Logs for error
        logModuleCall(MODULE_NAME, 'ChangePackage', [
            'productId' => $params['pid'],
            'serviceId' => $params['serviceid'],
        ], $logResult, $returnMessage);

        return $returnMessage;
    }

    // Logs for success
    logModuleCall(MODULE_NAME, 'ChangePackage', [
        'productId' => $params['pid'],
        'serviceId' => $params['serviceid'],
    ], $success, OK_CHANGE_PLAN . implode(', ', $success));

    return SUCCESS;
}

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

function synergywholesale_microsoft365_ClientArea($params)
{
    // New instance of local WHMCS database and Synergy API
    $whmcsLocalDb = new LocalDB();
    $synergyAPI = new SynergyAPI($params['configoption1'], $params['configoption2']);

    $currentProductLocal = $whmcsLocalDb->getById(WHMCS_PRODUCT_TABLE, $params['pid']);
    $currentService = $whmcsLocalDb->getById(WHMCS_HOSTING_TABLE, $params['serviceid']);

    // By default we want to take AUD (id 1), or we can take id of currency from params
    $currency = $whmcsLocalDb->getById(WHMCS_CURRENCY_TABLE, $params['clientdetails']['currency'] ?? 1);
    $customFields = $whmcsLocalDb->getProductAndServiceCustomFields($params['pid'], $params['serviceid']);
    $configOptions = $whmcsLocalDb->getSubscriptionsForAction($params['serviceid'], 'compare');

    return [
        'tabOverviewReplacementTemplate' => 'clientarea',
        'vars' => [
            'service' => $currentService,
            'product' => $currentProductLocal,
            'customFields' => $customFields,
            'configOptions' => $configOptions,
            'server' => [
                'serverName' => $params['serverhostname'],
                'ipAddress' => $params['serverip'],
            ],
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

function synergywholesale_microsoft365_metaData()
{
    return [
        'DisplayName' => 'Synergy Wholesale Microsoft 365',
    ];
}

/**
 * CUSTOM FUNCTIONS FOR USING INTERNALLY
 */

/** Validate subscription status for provisioning actions */
function synergywholesale_microsoft365_getSubscriptionStatusInvalid($action, $status, $subscriptionId)
{
    switch ($action) {
        case 'Suspend':
            // Message if subscription is already terminated
            if (in_array($status, TERMINATED_STATUS)) {
                return "[{$subscriptionId}] Subscription already terminated.";
            }

            // Message if subscription is already suspended
            if (in_array($status, SUSPENDED_STATUS)) {
                return "[{$subscriptionId}] Subscription already suspended.";
            }

            // Message for other unrecognised statuses
            if (!in_array($status, ACTIVE_STATUS)) {
                return "[{$subscriptionId}] Subscription not in active status.";
            }
            break;
        case 'Unsuspend':
            // Message if subscription is already active or pending
            if (in_array($status, ACTIVE_STATUS)) {
                return "[{$subscriptionId}] Subscription already active.";
            }

            // Message for other unrecognised statuses
            if (!in_array($status, SUSPENDED_STATUS)) {
                return "[{$subscriptionId}] Subscription not in suspended status.";
            }
            break;
        case 'Terminate':
            // Message if subscription is already terminated
            if (in_array($status, TERMINATED_STATUS)) {
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
    return $apiResult['error'] ? "[{$apiResult['status']}] {$apiResult['error']}" : "[SUCCESS] {$apiResult['errorMessage']}.";

}

?>