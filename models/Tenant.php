<?php
require_once 'SynergyAPI.php';

class Tenant {
    const API_ENDPOINT = 'https://api.synergywholesale.test/?wsdl';
    const SWS_TENANT_TARGET = 'tblTenants';
    const WHMCS_TABLE = 'tbltenants';
    private $synergyApi;
    private $instant;

    public function __construct($resellerId, $apiKey, $tenantId = '') {
        $this->synergyApi = new SynergyAPI($resellerId, $apiKey);

        // If we pass a tenant ID, then we set instant to be a specific tenant, otherwise just set list of all tenants
        $this->instant = $tenantId ? $this->synergyApi->getById('subscriptionGetClient', $tenantId) : $this->synergyApi->getAll('subscriptionListClients');
    }
}

?>
