<?php
namespace WHMCS\Microsoft365\Models;

class Subscription {
    const API_ENDPOINT = 'https://api.synergywholesale.test/?wsdl';
    const SWS_SUBSCRIPTION_TARGET = 'tblSubscriptions';
    const WHMCS_SUBSCRIPTION_TABLE = 'tblsubscriptions';
    const WHMCS_TENANT_TABLE = 'tbltenants';

    const TABLE_COLUMNS = [
        'id',
        'tenantId',
        'remoteSwsId',
        'remoteTenantSwsId',
        'productId',
    ];

    private $synergyApi;
    private $swsApiInstant;
    private $whmcsInstant;

    public function __construct($resellerId, $apiKey, $tenantId = '', $subscriptionId = '') {
        $this->synergyApi = new SynergyAPI($resellerId, $apiKey);
        $this->whmcsDB = new WhmcsLocalDb();

        // If we pass a subscription ID, then we set instant to be a specific subscription
        // If we pass a tenant ID, then we set instant to be the list of subscriptions belong to this tenant
        // If we don't pass either of the IDs, then it won't set any thing

        if (!empty($subscriptionId)) {
            $this->whmcsInstant = $this->whmcsDB->getById(self::WHMCS_SUBSCRIPTION_TABLE, $subscriptionId);
            $this->swsApiInstant = $this->synergyApi->getById('subscriptionGetDetails', $this->whmcsInstant->remoteSwsId);

            return 1;
        }

        if (!empty($tenantId)) {
            $this->whmcsInstant = $this->whmcsDB->getAll(self::WHMCS_SUBSCRIPTION_TABLE);

            $remoteTenantId = $this->whmcsDB->getById(self::WHMCS_TENANT_TABLE, $tenantId)->remoteSwsId;
            $this->swsApiInstant = $this->synergyApi->getAll(self::SWS_SUBSCRIPTION_TARGET, 'subscriptionListClientSubscriptions', $remoteTenantId);

            return 1;
        }

        return 0;
    }
}

?>
