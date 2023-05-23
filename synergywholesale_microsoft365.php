<?php
use WHMCS\Database\Capsule as DB;

use WHMCS\Microsoft365\Models\Tenant;
use WHMCS\Microsoft365\Models\SynergyAPI;
use WHMCS\Microsoft365\Models\Subscription;
use WHMCS\Microsoft365\Models\WhmcsLocalDb as LocalDB;

function synergywholesale_microsoft365_ConfigOptions()
{
    return [
        'tenantId' => [
            'FriendlyName' => 'SWS Tenant ID',
            'Type' => 'text',
            'Size' => '50',
            'Description' => '',
            'Default' => '', // TODO: RETRIEVE TENANT ID AND DISPLAY HERE
        ],
        'subscriptionId' => [
            'FriendlyName' => 'MS Subscription ID',
            'Type' => 'text',
            'Size' => '50',
            'Description' => '',
            'Default' => '', // TODO: RETRIEVE REMOTE ID AND DISPLAY HERE
        ],
        'domainPrefix' => [
            'FriendlyName' => 'MS Domain Prefix',
            'Type' => 'text',
            'Description' => '',
            'Default' => '', // TODO: RETRIEVE DOMAIN PREFIX AND DISPLAY HERE
        ],
    ];
}

function synergywholesae_microsoft365_CreateAccount()
{

}

function synergywholesale_microsoft365_SuspendAccount()
{

}

function synergywholesale_microsoft365_UnsuspendAccount()
{

}

function synergywholesale_microsoft365_TerminateAccount()
{

}

function synergywholesale_microsoft365_ChangePassword()
{

}

?>