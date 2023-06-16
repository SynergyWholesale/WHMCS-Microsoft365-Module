<?php

namespace WHMCS\Module\Server\SynergywholesaleMicrosoft365;

class ModuleEnums
{
    const WHMCS_TENANT_TABLE = 'tblclients';
    const WHMCS_HOSTING_TABLE = 'tblhosting';
    const WHMCS_PRODUCT_TABLE = 'tblproducts';
    const WHMCS_CURRENCY_TABLE = 'tblcurrencies';

    const STATE_MAP = [
        'Australian Capital Territory' => 'ACT',
        'New South Wales' => 'NSW',
        'Northern Territory' => 'NT',
        'Queensland' => 'QLD',
        'South Australia' => 'SA',
        'Tasmania' => 'TAS',
        'Victoria' => 'VIC',
        'Western Australia' => 'WA',
    ];

    const MODULE_NAME = '{{MODULE_NAME}}';
}