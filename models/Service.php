<?php
namespace WHMCS\Microsoft365\Models;

class Service {
    const SWS_SERVICE_TARGET = 'tblHostingAccounts';
    const WHMCS_SERVICE_TABLE = 'tblhosting';
    private $synergyApi;
    private $whmcsDB;
    private $swsApiInstant;
    private $whmcsInstant;

    public function __construct($resellerId, $apiKey, $serviceId)
    {
        $this->synergyApi = new SynergyAPI($resellerId, $apiKey);
        $this->whmcsDB = new WhmcsLocalDb();

        // First we get details from local WHMCS database
        $this->whmcsInstant = !empty($tenantId) ? $this->whmcsDB->getById(self::WHMCS_SERVICE_TABLE, $serviceId) : $this->whmcsDB->getAll(self::WHMCS_SERVICE_TABLE);

        // If we pass a tenant ID, then we get the specific tenant from SWS, otherwise just set list of all tenants
        if ($this->whmcsInstant) {
            $this->swsApiInstant = !empty($tenantId)
                ? $this->synergyApi->getById('subscriptionGetClient', $this->whmcsInstant->remote_sws_id)
                : $this->synergyApi->getAll(self::SWS_SERVICE_TARGET, 'subscriptionListClients');
        }
    }

    public function getLocalServiceById($serviceId)
    {
        // First we get details from local WHMCS database
        return !empty($serviceId) ? $this->whmcsDB->getById(self::WHMCS_SERVICE_TABLE, $serviceId) : false;
    }

    public function getSwsServiceById($hostingId)
    {
        return !empty($hostingId) ? $this->synergyApi->getById('subscriptionGetClient', $hostingId) : false;
    }

    public function getAllLocalServices()
    {
        return $this->whmcsDB->getAll(self::WHMCS_SERVICE_TABLE);
    }

    public function getAllSwsServices()
    {
        return $this->synergyApi->getAll(self::SWS_SERVICE_TARGET, 'subscriptionListClients');;
    }
}

?>