<?php
namespace WHMCS\Microsoft365\Models;

class Tenant {
    const API_ENDPOINT = 'https://api.synergywholesale.test/?wsdl';
    const SWS_TENANT_TARGET = 'tblTenants';
    const SWS_SUBSCRIPTION_TARGET = 'tblSubscriptions';
    const WHMCS_TABLE = 'tbltenants';
    private $synergyApi;
    private $whmcsDB;
    private $swsApiInstant;
    private $whmcsInstant;

    public function __construct($resellerId, $apiKey, $tenantId = '') {
        $this->synergyApi = new SynergyAPI($resellerId, $apiKey);
        $this->whmcsDB = new WhmcsLocalDb();

        // First we get details from local WHMCS database
        $this->whmcsInstant = !empty($tenantId) ? $this->whmcsDB->getById(self::WHMCS_TABLE, $tenantId) : $this->whmcsDB->getAll(self::WHMCS_TABLE);

        // If we pass a tenant ID, then we get the specific tenant from SWS, otherwise just set list of all tenants
        if ($this->whmcsInstant) {
            $this->swsApiInstant = !empty($tenantId)
                ? $this->synergyApi->getById('subscriptionGetClient', $this->whmcsInstant->remoteSwsId)
                : $this->synergyApi->getAll(self::SWS_TENANT_TARGET, 'subscriptionListClients');
        }
    }
}

?>
