<?php

namespace WHMCS\Module\Server\SynergywholesaleMicrosoft365;

use SoapClient;

class SynergyAPI
{
    const API_ENDPOINT = 'https://{{API}}';
    private $client;
    private $auth = [];

    public function __construct($resellerId, $apiKey)
    {
        try {
            $this->auth = [
                'resellerID' => $resellerId,
                'apiKey' => $apiKey,
            ];

            $this->client = new SoapClient(
                null,
                [
                    'location' => self::API_ENDPOINT,
                    'uri' => '',
                    'trace' => true,
                    'exceptions' => true
                ]
            );
        } catch (SoapFault $e) {
            logModuleCall(ModuleEnums::MODULE_NAME, 'createSoapClient', $this->auth, [
                'exception' => get_class($e),
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * Send request to SWS API
     * @param $action (name of function we want to call in SWS API)
     * @param $data (body request of the API call)
     * @return array|mixed|string
     */
    private function sendRequest($action, array $data = [])
    {
        $request = array_merge($data, $this->auth);

        try {
            $response = $this->client->{$action}($request);
        } catch (SoapFault $e) {
            return [
                'status' => $e->getCode(),
                'error' => $e->getMessage(),
            ];
        }

        if (!preg_match('/^(OK|AVAILABLE).*?/', $response->status)) {
            return [
                'status' => $response->status,
                'error' => $response->errorMessage,
            ];
        }

        return $response;
    }

    public function getTenantDetails(int $id)
    {
        if (empty($id)) {
            return [
                'error' => 'Cannot query with empty input value.',
            ];
        }

        return $this->sendRequest('subscriptionGetClient', ['identifier' => $id]);
    }

    public function getSubscriptionDetails(int $id)
    {
        if (empty($id)) {
            return [
                'error' => 'Cannot query with empty input value.',
            ];
        }

        return $this->sendRequest('subscriptionGetDetails', ['identifier' => $id]);
    }

    public function suspendSubscription(int $id)
    {
        return $this->sendRequest('subscriptionSuspend', ['identifier' => $id]);
    }

    public function unsuspendSubscription(int $id)
    {
        return $this->sendRequest('subscriptionUnsuspend', ['identifier' => $id]);
    }

    public function terminateSubscription(int $id)
    {
        return $this->sendRequest('subscriptionTerminate', ['identifier' => $id]);
    }

    public function createClient($data)
    {
        return $this->sendRequest('subscriptionCreateClient', $data);
    }

    public function purchaseSubscription($data)
    {
        return $this->sendRequest('subscriptionPurchase', $data);
    }

    public function updateSubscriptionQuantity($data)
    {
        return $this->sendRequest('subscriptionUpdateQuantity', $data);
    }

    /** Get all subscriptions of a tenant */
    public function getSubscriptionsList(int $id)
    {
        if (empty($id)) {
            return [
                'error' => 'Cannot query with empty input value.',
            ];
        }

        return $this->sendRequest('subscriptionListClientSubscriptions', ['identifier' => $id]);
    }

    public function getProductsList()
    {
        return $this->sendRequest('subscriptionListPurchasable');
    }
}