<?php
namespace WHMCS\Module\Server\SynergywholesaleMicrosoft365;

class SynergyAPI {

    const API_ENDPOINT = 'https://{{API}}';
    const MODULE_NAME = '{{MODULE_NAME}}';
    private $client;
    private $resellerId;
    private $apiKey;
    private $auth = [];
    public function __construct($resellerId, $apiKey)
    {
        if (empty($resellerId) || empty($apiKey)) {
            return false;
        }

        try {
            $this->resellerId = $resellerId;
            $this->apiKey = $apiKey;
            $this->auth = [
                'resellerID' => $resellerId,
                'apiKey' => $apiKey,
            ];

            $this->client = new \SoapClient(null,
                [
                    'location' => self::API_ENDPOINT,
                    'uri' => '',
                    'trace' => true,
                    'exceptions' => true
                ]
            );
            return $this->client;
        } catch (SoapFault $e) {
            logModuleCall(self::MODULE_NAME, 'createSoapClient', $this->auth, [
                'exception' => get_class($e),
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
        return false;
    }

    /**
     * Send request to SWS API
     * @param $action (name of function we want to call in SWS API)
     * @param $data (body request of the API call)
     * @return array|mixed|string
     */
    private function sendRequest($action, $request)
    {
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

    /**
     * Get details of a model by its ID
     * @param $action (name of function we want to call in SWS API)
     * @param $id (ID of the model we are targeting)
     * @return array|bool
     */
    public function getById($action, $id)
    {
        if (empty($id) || empty($action)) {
            return [
                'error' => 'Cannot query with empty input value(s).',
            ];
        }

        // Prepare data for sending request
        $request = array_merge([
            'identifier' => $id,
        ], $this->auth);

        return $this->sendRequest($action, $request);
    }

    /**
     * Get list of all objects of a model from SWS API
     * @param $action
     * @return mixed
     */
    public function getAll($target, $action, $referenceId = null)
    {
        if (empty($action)) {
            return [
                'error' => 'The action input is empty.',
            ];
        }

        // If we pass through an ID, it means we want to get the full list of subscriptions belong to a tenant
        switch($target) {
            case Subscription::SWS_SUBSCRIPTION_TARGET:
                if (!$referenceId){
                    return [
                        'error' => 'The required tenant ID field is empty',
                    ];
                }

                $request = array_merge([
                    'identifier' => $referenceId,
                ], $this->auth);
                break;
            case Tenant::SWS_TENANT_TARGET:
            default:
                $request = $this->auth;
                break;
        }

        return $this->sendRequest($action, $request);
    }

    /**
     * Get list of objects by some conditions set in the array $conditions
     * @param $action
     * @param $conditions
     * @return array|mixed|string
     */
    public function getByConditions($action, $conditions)
    {
        return $this->sendRequest($action, array_merge($conditions, $this->auth));
    }

    public function provisioningActions($action, $id)
    {
        $request = array_merge([
            'identifier' => $id,
        ], $this->auth);
        return $this->sendRequest($action, $request);
    }

    public function crudOperations($action, $data)
    {
        $data = array_merge($data, $this->auth);
        return $this->sendRequest($action, $data);
    }
}
?>