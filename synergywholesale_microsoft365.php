<?php
use WHMCS\Database\Capsule as DB;

use WHMCS\Microsoft365\Models\SynergyAPI;
use WHMCS\Microsoft365\Models\WhmcsLocalDb as LocalDB;

const OK_PROVISION = '[OK] Successfully provisioned new service.';
const TENANT_EXISTED = 'This tenant has already been created.';
const OK_SUSPEND = '[OK] Successfully suspended service.';
const OK_UNSUSPEND = '[OK] Successfully unsuspended service.';
const OK_TERMINATE = '[OK] Successfully unsuspended service.';
const FAILED_SUSPEND_LIST = 'Failed to suspend the following subscriptions: ';
const FAILED_UNSUSPEND_LIST = 'Failed to unsuspend the following subscriptions: ';
const FAILED_TERMINATE_LIST = 'Failed to unsuspend the following subscriptions: ';
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

    if (!empty($customFields['Remote Tenant ID']['value'])) {
        $remoteTenant = $synergyAPI->getById('subscriptionGetClient', $customFields['Remote Tenant ID']['value']);
        if ($remoteTenant) {
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
    $newTenantResult = $synergyAPI->createNewRecord('subscriptionCreateClient', $newTenantRequest);
    if ($newTenantResult['error'] || !$newTenantResult['identifier']) {
        return $this->synergywholesale_microsoft365_formatStatusAndMessage($newTenantResult);
    }

    /**
     * START CREATE NEW SUBSCRIPTION IN SYNERGY
     */
    $tenantId = $newTenantResult['identifier'];

    // Get and organise subscriptionOrder request for SWS API
    $subscriptionOrder = $whmcsLocalDb->getSubscriptionsForCreate($params['serviceid']);
    if (empty($subscriptionOrder)) {
        return $this->synergywholesale_microsoft365_formatStatusAndMessage(['error' => 'Unable to create subscription account due to invalid configuration.']);
    }

    //Format and merge array for request
    $newSubscriptionsRequest = array_merge($subscriptionOrder, ['identifier' => $tenantId]);
    // Send request to SWS API
    $newSubscriptionsResult = $synergyAPI->createNewRecord('subscriptionPurchase', $newSubscriptionsRequest);
    if ($newSubscriptionsResult['error'] || !$newSubscriptionsResult['subscriptionList']) {
        return $this->synergywholesale_microsoft365_formatStatusAndMessage($newTenantResult);
    }

    /**
     * INSERT OR UPDATE NEW REMOTE VALUES TO LOCAL WHMCS DATABASE (Remote Tenant ID, Domain Prefix, Remote Subscriptions)
     */
    // Insert new values of Remote Tenant ID, Domain Prefix into custom fields
    $whmcsLocalDb->createNewCustomFieldValues($customFields['Remote Tenant ID']['fieldId'], $params['serviceid'], $newTenantResult['identifier']);

    $whmcsLocalDb->createNewCustomFieldValues($customFields['Domain Prefix']['fieldId'], $params['serviceid'], $newTenantResult['identifier']);

    // Generate data for saving new subscriptions ID as format "productId|subscriptionId"
    $remoteSubscriptionData = [];
    foreach ($newSubscriptionsResult as $eachSubscription) {
        $remoteSubscriptionData[] = "{$eachSubscription['productId']}|{$eachSubscription['subscriptionId']}";
    }
    // If current subscription data is empty, then we insert
    if (empty($customFields['Remote Subscriptions']['value'])) {
        $whmcsLocalDb->createNewCustomFieldValues($customFields['Remote Subscriptions']['fieldId'], $params['serviceid'], implode(', ', $remoteSubscriptionData));

        return OK_PROVISION;
    }

    $whmcsLocalDb->updateCustomFieldValues($customFields['Remote Subscriptions']['fieldId'], $params['serviceid'], implode(', ', $remoteSubscriptionData));

    return OK_PROVISION;
}

function synergywholesale_microsoft365_SuspendAccount($params)
{
    // New instance of local WHMCS database and Synergy API
    $whmcsLocalDb = new LocalDB();
    $synergyAPI = new SynergyAPI(['configoption1'], $params['configoption2']);

    // Retrieve list of custom fields of this service
    $customFields = $whmcsLocalDb->getProductAndServiceCustomFields($params['pid'], $params['serviceid']);
    // Split list of subscription IDs into an array for looping through
    $subscriptionList = explode(', ', $customFields['Remote Subscriptions']['value']) ?? [];

    $error = [];
    if (!empty($subscriptionList)) {

        foreach ($subscriptionList as $eachSubscription) {
            // Get the subscription ID split from "productId|subscriptionId"
            $subscriptionId = explode('|', $eachSubscription)[1] ?? false;

            // Check if subscription is currently in Active or Pending, if NOT, then skip it
            $thisSubscription = $synergyAPI->getById('subscriptionGetDetails', $subscriptionId);

            // Message if subscription is already terminated
            if (in_array($thisSubscription['subscriptionStatus'], TERMINATED_STATUS)) {
                $error[] = "[{$subscriptionId}] Subscription already terminated.";
                continue;
            }

            // Message if subscription is already suspended
            if (in_array($thisSubscription['subscriptionStatus'], SUSPENDED_STATUS)) {
                $error[] = "[{$subscriptionId}] Subscription already suspended.";
                continue;
            }

            // Message for other unrecognised statuses
            if (!in_array($thisSubscription['subscriptionStatus'], ACTIVE_STATUS)) {
                $error[] = "[{$subscriptionId}] Subscription not in active status.";
                continue;
            }

            // Send request for provisioning and format the response for display
            $formattedMessage = synergywholesale_microsoft365_formatStatusAndMessage($synergyAPI->provisioningActions('subscriptionSuspend', $subscriptionId));
            //NOTE: We don't need to  update subscription's status in local WHMCS database as we only store the required id columns

            // This means the API request wasn't successful, add this ID to $error array for displaying message
            if (!strpos($formattedMessage, 'OK') || !strpos($formattedMessage, 'AVAILABLE')) {
                $error[] = "[{$subscriptionId}] Suspend Request Failed";
            }
        }
    }

    // if $error array is not empty, that means one or more subscriptions couldn't be suspended
    if (!empty($error)) {
        return FAILED_SUSPEND_LIST . implode(', ', $error);
    }

    // Update service status in local WHMCS
    $whmcsLocalDb->update(WHMCS_HOSTING_TABLE, $params['serviceid'], ['domainstatus' => INACTIVE_STATUS[0]]);

    return OK_SUSPEND;
}

function synergywholesale_microsoft365_UnsuspendAccount($params)
{
    // New instance of local WHMCS database and Synergy API
    $whmcsLocalDb = new LocalDB();
    $synergyAPI = new SynergyAPI(['configoption1'], $params['configoption2']);

    // Retrieve list of custom fields of this service
    $customFields = $whmcsLocalDb->getProductAndServiceCustomFields($params['pid'], $params['serviceid']);
    // Split list of subscription IDs into an array for looping through
    $subscriptionList = explode(', ', $customFields['Remote Subscriptions']['value']) ?? [];

    $error = [];
    if (!empty($subscriptionList)) {

        foreach ($subscriptionList as $eachSubscription) {
            // Get the subscription ID split from "productId|subscriptionId"
            $subscriptionId = explode('|', $eachSubscription)[1] ?? false;

            // Check if subscription is currently in Active or Pending, then skip it
            // If it is currently  terminated, then skip it
            // We only unsuspend if it is suspended
            $thisSubscription = $synergyAPI->getById('subscriptionGetDetails', $subscriptionId);

            // Message if subscription is already active or pending
            if (in_array($thisSubscription['subscriptionStatus'], ACTIVE_STATUS)) {
                $error[] = "[{$subscriptionId}] Subscription already active.";
                continue;
            }

            // Message if subscription is already terminated
            if (in_array($thisSubscription['subscriptionStatus'], TERMINATED_STATUS)) {
                $error[] = "[{$subscriptionId}] Subscription already terminated.";
                continue;
            }

            // Message for other unrecognised statuses
            if (!in_array($thisSubscription['subscriptionStatus'], SUSPENDED_STATUS)) {
                $error[] = "[{$subscriptionId}] Subscription not in suspended status.";
                continue;
            }

            // Send request for provisioning and format the response for display
            $formattedMessage = synergywholesale_microsoft365_formatStatusAndMessage($synergyAPI->provisioningActions('subscriptionUnsuspend', $subscriptionId));
            //NOTE: We don't need to  update subscription's status in local WHMCS database as we only store the required id columns

            // This means the API request wasn't successful, add this ID to $error array for displaying message
            if (!strpos($formattedMessage, 'OK') || !strpos($formattedMessage, 'AVAILABLE')) {
                $error[] = "[{$subscriptionId}] Unsuspend Request Failed";
            }
        }
    }

    // if $error array is not empty, that means one or more subscriptions couldn't be suspended
    if (!empty($error)) {
        return FAILED_UNSUSPEND_LIST . implode(', ', $error);
    }

    // Update service status in local WHMCS
    $whmcsLocalDb->update(WHMCS_HOSTING_TABLE, $params['serviceid'], ['domainstatus' => STATUS_ACTIVE]);

    return OK_UNSUSPEND;
}

function synergywholesale_microsoft365_TerminateAccount($params)
{
    // New instance of local WHMCS database and Synergy API
    $whmcsLocalDb = new LocalDB();
    $synergyAPI = new SynergyAPI(['configoption1'], $params['configoption2']);

    // Retrieve list of custom fields of this service
    $customFields = $whmcsLocalDb->getProductAndServiceCustomFields($params['pid'], $params['serviceid']);
    // Split list of subscription IDs into an array for looping through
    $subscriptionList = explode(', ', $customFields['Remote Subscriptions']['value']) ?? [];

    $error = [];
    if (!empty($subscriptionList)) {

        foreach ($subscriptionList as $eachSubscription) {
            // Get the subscription ID split from "productId|subscriptionId"
            $subscriptionId = explode('|', $eachSubscription)[1] ?? false;

            // Check if subscription is currently in terminated, then skip it
            // If it is currently  active stage or suspended stage, then we terminate
            $thisSubscription = $synergyAPI->getById('subscriptionGetDetails', $subscriptionId);

            // Message if subscription is already terminated
            if (in_array($thisSubscription['subscriptionStatus'], TERMINATED_STATUS)) {
                $error[] = "[{$subscriptionId}] Subscription already terminated.";
                continue;
            }

            // Send request for provisioning and format the response for display
            $formattedMessage = synergywholesale_microsoft365_formatStatusAndMessage($synergyAPI->provisioningActions('subscriptionTerminate', $subscriptionId));
            //NOTE: We don't need to  update subscription's status in local WHMCS database as we only store the required id columns

            // This means the API request wasn't successful, add this ID to $error array for displaying message
            if (!strpos($formattedMessage, 'OK') || !strpos($formattedMessage, 'AVAILABLE')) {
                $error[] = "[{$subscriptionId}] Terminate Request Failed";
            }
        }
    }

    // if $error array is not empty, that means one or more subscriptions couldn't be suspended
    if (!empty($error)) {
        return FAILED_TERMINATE_LIST . implode(', ', $error);
    }

    // Update service status in local WHMCS
    $whmcsLocalDb->update(WHMCS_HOSTING_TABLE, $params['serviceid'], ['domainstatus' => STATUS_CANCELLED]);

    return OK_TERMINATE;

}

function synergywholesale_microsoft365_ChangePassword()
{

}

function synergywholesale_microsoft365_formatStatusAndMessage($apiResult)
{
    if (is_null($apiResult)) {
        return 'Fatal Error.';
    }

    // If 'error' is set, that means the API failed to send request or the action was not successful in SWS API
    // If not, that means the process was successful and we return code 'OK' along with the message (SWS API set it as 'errorMessage' even if sucessful)
    return $apiResult['error'] ?? "[{$apiResult->status}] {$apiResult->errorMessage}.";

}

function synergywholesale_microsoft365_metaData()
{
    return [
        'DisplayName' => 'Synergy Wholesale Hosting',
    ];
}

?>