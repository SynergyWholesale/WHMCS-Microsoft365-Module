<?php

namespace WHMCS\Module\Server\SynergywholesaleMicrosoft365;

class ModuleEnums
{
    const WHMCS_TENANT_TABLE = 'tblclients';
    const WHMCS_HOSTING_TABLE = 'tblhosting';
    const WHMCS_PRODUCT_TABLE = 'tblproducts';
    const WHMCS_CURRENCY_TABLE = 'tblcurrencies';
    const WHMCS_CONFIG_GROUPS_TABLE = 'tblproductconfiggroups';
    const WHMCS_CONFIG_OPTIONS_TABLE = 'tblproductconfigoptions';

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

    // Declare some constants for product package definition
    const MS365_BASIC = "ms365_basic";
    const MS365_STANDARD = "ms365_standard";
    const MS365_PREMIUM = "ms365_premium";
    const MS365_PACKAGES = [
        self:: MS365_BASIC => "Microsoft 365 Business Basic",
        self::MS365_STANDARD => "Microsoft 365 Business Standard",
        self::MS365_PREMIUM => "Microsoft 365 Business Premium",
    ];
}