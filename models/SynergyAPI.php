<?php

use WHMCS\Database\Capsule as DB;

class SynergyAPI {

    const API_ENDPOINT = 'https://api.synergywholesale.test/?wsdl';
    const MODULE_NAME = 'synergywholesale_microsoft365';
    const SWS_TENANT_TARGET = 'tblTenants';
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
    private function sendRequest($action, $data)
    {
        try {
            $response = $this->client->{$action}($data);
            $logResponse = is_string($response) ? $response : (array) $response;
            logModuleCall(self::MODULE_NAME, $action, $data, $logResponse, $logResponse, $this->auth);
        } catch (SoapFault $e) {
            logModuleCall(self::MODULE_NAME, $action, $data, $e->getMessage(), $e->getMessage(), $this->auth);

            return [
                'error' => $e->getMessage(),
            ];
        }

        if (!preg_match('/^(OK|AVAILABLE).*?/', $response->status)) {
            return [
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
        $data = array_merge([
            'identifier' => $id,
        ], $this->auth);

        return $this->sendRequest($action, $data);
    }

    /**
     * Get list of all objects of a model from SWS API
     * @param $action
     * @return mixed
     */
    public function getAll($action, $tenantId = null)
    {
        if (empty($action)) {
            return [
                'error' => 'The action input is empty.',
            ];
        }

        return $this->sendRequest($action, $this->auth);
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
}
?>