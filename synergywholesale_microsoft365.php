<?php
use WHMCS\Database\Capsule as DB;

use WHMCS\Microsoft365\Models\SynergyAPI;
use WHMCS\Microsoft365\Models\WhmcsLocalDb as LocalDB;

const MODULE_NAME = 'synergywholesale_microsoft365';
const OK_PROVISION = '[SUCCESS] Successfully provisioned new service.';
const TENANT_EXISTED = '[FAILED] This tenant has already been created.';
const OK_CREATE_TENANT = '[SUCCESS] Successfully created new tenant.';
const OK_SUSPEND = '[SUCCESS] Successfully suspended service.';
const OK_UNSUSPEND = '[SUCCESS] Successfully unsuspended service.';
const OK_TERMINATE = '[SUCCESS] Successfully unsuspended service.';
const OK_CHANGE_PLAN = '[SUCCESS] Successfully changed plan for service.';
const FAILED_SUSPEND_LIST = '[FAILED] Failed to suspend the following subscriptions: ';
const FAILED_UNSUSPEND_LIST = '[FAILED] Failed to unsuspend the following subscriptions: ';
const FAILED_TERMINATE_LIST = '[FAILED] Failed to unsuspend the following subscriptions: ';
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
];

const TERMINATED_STATUS = [
    STATUS_DELETED,
    STATUS_CANCELLED,
];

// Database tables
const WHMCS_HOSTING_TABLE = 'tblhosting';

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
function synergywholesae_microsoft365_CreateAccount($params)
{
    // New instance of local WHMCS database and Synergy API
    $whmcsLocalDb = new LocalDB();
    $synergyAPI = new SynergyAPI(['configoption1'], $params['configoption2']);

    // Collect tenant's contact details from module params (firstname, lastname,address1,etc...)
    $clientDetails = $params['clientsdetails'];

    // Get and organise custom fields from DB
    $customFields = $whmcsLocalDb->getProductAndServiceCustomFields($params['pid'], $params['serviceid']);

    // Get Client Details
    $clientObj = $whmcsLocalDb->getById(LocalDB::WHMCS_TENANT_TABLE, $params('userid'));

    /**
     * VALIDATE IF THIS TENANT HAS BEEN CREATED IN SYNERGY
     */

    /** logModuleCall($module, $action, $requestString, $responseData, $processedData, $replaceVars); */
    if (!empty($customFields['Remote Tenant ID']['value'])) {
        $remoteTenant = $synergyAPI->getById('subscriptionGetClient', $customFields['Remote Tenant ID']['value']);

        if ($remoteTenant) {
            // Logs for error
            logModuleCall(MODULE_NAME, 'CreateAccount', $customFields['Remote Tenant ID']['value'], [
                'status' => $remoteTenant['status'],
                'message' => $remoteTenant['errorMessage'],
            ], TENANT_EXISTED);

            return TENANT_EXISTED;
        }
    }

    /**
     * START CREATE NEW TENANT IN SYNERGY
     */
    $otherData = [
        'password' => $params['password'],
        'description' => $clientObj->description ?? '',
        'agreement' => $customFields['CustomerAgreement'],
    ];

    // Format and merge array for request
    $newTenantRequest = array_merge($clientDetails, $otherData);
    // Send request to SWS API
    $newTenantResult = $synergyAPI->crudOperations('subscriptionCreateClient', $newTenantRequest);

    $formatted = synergywholesale_microsoft365_formatStatusAndMessage($newTenantResult);
    if ($newTenantResult['error'] || !$newTenantResult['identifier']) {

        // Logs for error
        logModuleCall(MODULE_NAME, 'CreateAccount', $newTenantRequest, [
            'status' => $newTenantResult['status'],
            'error' => $newTenantResult['error'],
        ], $formatted);

        return $formatted;
    }
    // Insert new values of Remote Tenant ID, Domain Prefix into custom fields
    $whmcsLocalDb->createNewCustomFieldValues($customFields['Remote Tenant ID']['fieldId'], $params['serviceid'], $newTenantResult['identifier']);
    $whmcsLocalDb->createNewCustomFieldValues($customFields['Domain Prefix']['fieldId'], $params['serviceid'], $newTenantResult['domainPrefix']);

    // Logs for successful
    logModuleCall(MODULE_NAME, 'CreateAccount', $newTenantRequest, [
        'status' => $newTenantResult['status'],
        'message' => $newTenantResult['errorMessage'],
    ], $formatted);

    /**
     * START CREATE NEW SUBSCRIPTION IN SYNERGY
     */
    $tenantId = $newTenantResult['identifier'];

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
        return OK_CREATE_TENANT;
    }

    //Format and merge array for request
    $newSubscriptionsRequest = array_merge($subscriptionOrder, ['identifier' => $tenantId]);
    // Send request to SWS API
    $newSubscriptionsResult = $synergyAPI->crudOperations('subscriptionPurchase', $newSubscriptionsRequest);

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
    foreach ($newSubscriptionsResult as $eachSubscription) {
        $remoteSubscriptionData[] = "{$eachSubscription['productId']}|{$eachSubscription['subscriptionId']}";
    }

    // If current subscription data is empty, then we insert
    if (empty($customFields['Remote Subscriptions']['value'])) {
        $whmcsLocalDb->createNewCustomFieldValues($customFields['Remote Subscriptions']['fieldId'], $params['serviceid'], implode(', ', $remoteSubscriptionData));

        // Logs for successful
        logModuleCall(MODULE_NAME, 'CreateAccount', $newSubscriptionsRequest, [
            'status' => $newSubscriptionsResult['status'],
            'message' => $newSubscriptionsResult['errorMessage'],
        ], $formatted);

        return OK_PROVISION;
    }

    $whmcsLocalDb->updateCustomFieldValues($customFields['Remote Subscriptions']['fieldId'], $params['serviceid'], implode(', ', $remoteSubscriptionData));

    // Logs for successful
    logModuleCall(MODULE_NAME, 'CreateAccount', $newSubscriptionsRequest, [
        'status' => $newSubscriptionsResult['status'],
        'message' => $newSubscriptionsResult['errorMessage'],
    ], $formatted);

    return OK_PROVISION;
}

/** Suspend service and subscriptions in SWS API */
function synergywholesale_microsoft365_SuspendAccount($params)
{
    // New instance of local WHMCS database and Synergy API
    $whmcsLocalDb = new LocalDB();
    $synergyAPI = new SynergyAPI(['configoption1'], $params['configoption2']);

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
        if ($thisSubscription['error'] || !$thisSubscription) {
            $formatted = synergywholesale_microsoft365_formatStatusAndMessage($thisSubscription);
            $error[] = "[{$subscriptionId}] {$formatted}";
            continue;
        }

        // Validate if current service status is valid for suspend, if error exists then we skip it
        $validateResult = synergywholesale_microsoft365_getSubscriptionStatusInvalid('Suspend', $thisSubscription['domainStatus'], $subscriptionId);
        if ($validateResult) {
            $error[] = $validateResult;
            continue;
        }

        // Send request for provisioning and format the response for display
        $formattedMessage = synergywholesale_microsoft365_formatStatusAndMessage($synergyAPI->provisioningActions('subscriptionSuspend', $subscriptionId));
        //NOTE: We don't need to  update subscription's status in local WHMCS database as we only store the required id columns

        // This means the API request wasn't successful, add this ID to $error array for displaying message
        if (!strpos($formattedMessage, '[SUCCESS]')) {
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
    ], $success, OK_SUSPEND);

    return OK_SUSPEND;
}

/** Unsuspend service and subscriptions in SWS API */
function synergywholesale_microsoft365_UnsuspendAccount($params)
{
    // New instance of local WHMCS database and Synergy API
    $whmcsLocalDb = new LocalDB();
    $synergyAPI = new SynergyAPI(['configoption1'], $params['configoption2']);

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
        if ($thisSubscription['error'] || !$thisSubscription) {
            $formatted = synergywholesale_microsoft365_formatStatusAndMessage($thisSubscription);
            $error[] = "[{$subscriptionId}] {$formatted}";
            continue;
        }

        // Validate if current service status is valid for unsuspend, if error exists then we skip it
        $validateResult = synergywholesale_microsoft365_getSubscriptionStatusInvalid('Suspend', $thisSubscription['domainStatus'], $subscriptionId);
        if ($validateResult) {
            $error[] = $validateResult;
            continue;
        }

        // Send request for provisioning and format the response for display
        $formattedMessage = synergywholesale_microsoft365_formatStatusAndMessage($synergyAPI->provisioningActions('subscriptionUnsuspend', $subscriptionId));
        //NOTE: We don't need to  update subscription's status in local WHMCS database as we only store the required id columns

        // This means the API request wasn't successful, add this ID to $error array for displaying message
        if (!strpos($formattedMessage, '[SUCCESS]')) {
            $error[] = "[{$subscriptionId}] {$formattedMessage}";
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
    ], $success, OK_UNSUSPEND);

    return OK_UNSUSPEND;
}

/** Terminate service and subscriptions in SWS API */
function synergywholesale_microsoft365_TerminateAccount($params)
{
    // New instance of local WHMCS database and Synergy API
    $whmcsLocalDb = new LocalDB();
    $synergyAPI = new SynergyAPI(['configoption1'], $params['configoption2']);

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
        if ($thisSubscription['error'] || !$thisSubscription) {
            $formatted = synergywholesale_microsoft365_formatStatusAndMessage($thisSubscription);
            $error[] = "[{$subscriptionId}] {$formatted}";
            continue;
        }

        // Validate if current service status is valid for unsuspend, if error exists then we skip it
        $validateResult = synergywholesale_microsoft365_getSubscriptionStatusInvalid('Terminate', $thisSubscription['domainStatus'], $subscriptionId);
        if ($validateResult) {
            $error[] = $validateResult;
            continue;
        }

        // Send request for provisioning and format the response for display
        $formattedMessage = synergywholesale_microsoft365_formatStatusAndMessage($synergyAPI->provisioningActions('subscriptionTerminate', $subscriptionId));
        //NOTE: We don't need to  update subscription's status in local WHMCS database as we only store the required id columns

        // This means the API request wasn't successful, add this ID to $error array for displaying message
        if (!strpos($formattedMessage, '[SUCCESS]')) {
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

    return OK_TERMINATE;

}

/** Perform change plan (subscriptions and quantities) for this tenant (service)
 * STEPS TO PERFORM CHANGE PLAN (OR CHANGE PACKAGE) ACTION
 * 1. First we want to retrieve all current subscriptions that this service has (from custom fields)
 * 2. Then we want to retrieve new details for config options attached to this service (config options may have quantity as 0)
 * 3. We compare each new config option with the current subscriptions (custom fields) and handle differently as:
 *      - If new config option's quantity is 0, AND that config option currently doesn't have any subscriptions, we ignore it
 *      - If all new config option's quantities are 0, that means we terminate all the subscriptions that this tenant currently has
 *      - If new config option's quantity is 0, AND that config option already has subscriptions under this product, we terminate the subscriptions
 *      - if new config option's quantity is > 0, and that config option already has subscriptions under this product, we perform change plan action
 *      - If new config option's quantity is > 0, AND that config option currently doesn't have any subscriptions, we purchase new subscriptions
 * 4. These logics also work perfectly when user changes package and click change plan module command
 * @param $params
 * @return string
 */
function synergywholesale_microsoft365_ChangePlan($params)
{
    // New instance of local WHMCS database and Synergy API
    $whmcsLocalDb = new LocalDB();
    $synergyAPI = new SynergyAPI(['configoption1'], $params['configoption2']);

    // TODO: May need to retrieve current values from SWS API and compare with new records to generate log message

    // Get existing subscriptions (custom fields) and overall subscriptions (config options) from local WHMCS DB
    $existingSubscriptions = $whmcsLocalDb->getSubscriptionsForAction($params['serviceid'], 'changePlan');
    $overallSubscriptions = $whmcsLocalDb->getSubscriptionsForAction($params['serviceid'], 'create');

    $subscriptionsToCreate = [];

    $error = [];
    $success = [];
    foreach ($overallSubscriptions as $row) {
        $productId = $row['productId'];

        /** If this config option doesn't exist in custom fields, that means this subscription hasn't been created in Synergy */
        if (!empty($existingSubscriptions[$productId])) {
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

        /** Otherwise if this config option exists in custom fields, that mean this subscription already provisioned in Synergy, now we check 'quantity' to see if we need to terminate or update quantity for this subscription */
        $existingSubscriptionId = $existingSubscriptions['subscriptionId'];

        // Get current details of subscription from Synergy API
        $thisSubscription = $synergyAPI->getById('subscriptionGetDetails', $existingSubscriptionId);
        if ($thisSubscription['error'] || !$thisSubscription) {
            $formatted = synergywholesale_microsoft365_formatStatusAndMessage($thisSubscription);
            $error[] = "[CURRENT SUBSCRIPTION] [{$existingSubscriptionId}] {$formatted}";
            continue;
        }

        // If quantity = 0, that means user wants to terminate this subscription
        if ($row['quantity'] == 0) {
            // Validate if this subscription status is valid for termination
            $validateStatus = synergywholesale_microsoft365_getSubscriptionStatusInvalid('Terminate', $thisSubscription['domainStatus'], $existingSubscriptionId);

            // If error status exists, we add it to error logs
            if ($validateStatus) {
                $error[] = "[TERMINATE SUBSCRIPTION] {$validateStatus}";
                continue;
            }

            // If error status is NULL, then we terminate this subscription
            $formattedMessage = synergywholesale_microsoft365_formatStatusAndMessage($synergyAPI->provisioningActions('subscriptionTerminate', $existingSubscriptionId));

            // This means the API request wasn't successful, add this ID to $error array for displaying message
            if (!strpos($formattedMessage, '[SUCCESS]')) {
                $error[] = "[{$existingSubscriptionId}] {$formattedMessage}";
            }

            $success[] = "[TERMINATE SUBSCRIPTION] [{$existingSubscriptionId}] {$formattedMessage}";
            continue;
        }

        // If quantity is negative, we return error
        if ($row['quantity'] < 0) {
            $error[] = "[{$existingSubscriptionId}] Invalid quantity provided";
            continue;
        }

        // Otherwise we perform update quantity for this subscription
        $formattedMessage = synergywholesale_microsoft365_formatStatusAndMessage($synergyAPI->crudOperations('subscriptionUpdateQuantity', [
            'identifier' => $existingSubscriptionId,
            'quantity' => $row['quantity'],
        ]));

        // This means the API request wasn't successful, add this ID to $error array for displaying message
        if (!strpos($formattedMessage, '[SUCCESS]')) {
            $error[] = "[{$existingSubscriptionId}] {$formattedMessage}";
        }

        $success[] = "[CHANGE QUANTITY SUBSCRIPTION] [{$existingSubscriptionId}] Successfully updated to {$row['quantity']} seat(s).";
    }

    /** Now we want to check if $subscriptionsToCreate not empty, then we purchase subscriptions */
    if (!empty($subscriptionsToCreate)) {
        $tenantId = $params['customfields']['Remote Tenant ID'];

        $orderLogMessage = [];
        foreach ($subscriptionsToCreate as $row) {
            $orderLogMessage[] = "{$row['productId']}|{$row['quantity']}";
        }

        // Send API request to SWS for purchasing new subscription(s)
        $purchaseResult = $synergyAPI->crudOperations('subscriptionPurchase', array_merge($subscriptionsToCreate, ['identifier' => $tenantId]));
        if ($purchaseResult['error'] || !$purchaseResult['subscriptionList']) {
            $error[] = "[NEW SUBSCRIPTION] " . synergywholesale_microsoft365_formatStatusAndMessage($purchaseResult);
        } else {
            $success[] = "[NEW SUBSCRIPTION] " . synergywholesale_microsoft365_formatStatusAndMessage($purchaseResult);

            // Generate data for saving new subscriptions ID as format "productId|subscriptionId"
            $remoteSubscriptionData = [];
            foreach ($purchaseResult as $eachSubscription) {
                $remoteSubscriptionData[] = "{$eachSubscription['productId']}|{$eachSubscription['subscriptionId']}";
            }

            // Retrieve the custom fields of this service
            $customFields = $whmcsLocalDb->getProductAndServiceCustomFields($params['pid'], $params['serviceid']);

            // If current subscription data is empty, then we insert
            if (empty($customFields['Remote Subscriptions']['value'])) {
                $whmcsLocalDb->createNewCustomFieldValues($customFields['Remote Subscriptions']['fieldId'], $params['serviceid'], implode(', ', $remoteSubscriptionData));
            } else {
                $whmcsLocalDb->updateCustomFieldValues($customFields['Remote Subscriptions']['fieldId'], $params['serviceid'], implode(', ', $remoteSubscriptionData));
            }
        }
    }

    // If there is error set during the process, we just put them into the response
    if (!empty($error)) {
        $returnMessage = FAILED_CHANGE_PLAN . ' Error: ' . implode(', ', $error);

        // Logs for error
        logModuleCall(MODULE_NAME, 'ChangePlan', [
            'productId' => $params['pid'],
            'serviceId' => $params['serviceid'],
        ], $error, $returnMessage);

        return $returnMessage;
    }

    // Logs for success
    logModuleCall(MODULE_NAME, 'ChangePlan', [
        'productId' => $params['pid'],
        'serviceId' => $params['serviceid'],
    ], $success, OK_CHANGE_PLAN);

    /** Update new remote subscriptions into WHMCS database */

    return OK_CHANGE_PLAN;

}

/** Perform sync data from SWS API to WHMCS */
function synergywholesale_microsoft365_Sync($params)
{

}

/** Define module buttons available for admin */
function synergywholesale_micrsoft365_AdminCustomButtonArray()
{
    return [
        'Change Plan' => 'ChangePlan',
        'Sync Data' => 'Sync',
    ];
}

function synergywholesale_microsoft365_metaData()
{
    return [
        'DisplayName' => 'Synergy Wholesale Hosting',
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

            // Message if subscription is already terminated
            if (in_array($status, TERMINATED_STATUS)) {
                return "[{$subscriptionId}] Subscription already terminated.";
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
    return "[{$apiResult['status']}] {$apiResult['error']}" ?? "[SUCCESS] {$apiResult->errorMessage}.";

}

?>