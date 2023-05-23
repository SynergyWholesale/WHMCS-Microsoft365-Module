<?php
require_once 'SynergyAPI.php';

class Subscription {
    const API_ENDPOINT = 'https://api.synergywholesale.test/?wsdl';
    const SWS_TENANT_TARGET = 'tblSubscriptions';
    const WHMCS_TABLE = 'tblsubscriptions';
    private $synergyApi;
    private $instant;

    // When creating an instant of Tenant, we can set an instant as a tenant OR a list of tenants
    public function __construct($resellerId, $apiKey, $tenantId = '', $subscriptionId = '') {
        $this->synergyApi = new SynergyAPI($resellerId, $apiKey);

        // If we pass a subscription ID, then we set instant to be a specific subscription
        // If we pass a tenant ID, then we set instant to be the list of subscriptions belong to this tenant
        // If we don't pass either of the IDs, then it won't set instant
        $this->instant = $subscriptionId ? $this->synergyApi->getById('subscriptionGetDetails', $subscriptionId) : (!empty($tenantId) ? $this->synergyApi->getAll('subscriptionListClientSubscriptions', $tenantId) : null);
    }
}

?>
