<?php

namespace WHMCS\Module\Server\SynergywholesaleMicrosoft365;

class ProductEnums
{
    /**
     * NOTE: THESE CONSTANTS CAN BE UPDATED PRIOR TO DEPLOYMENT
     */
    const CONFIG_GROUP_BASIC_NAME = 'Synergy Wholesale Microsoft 365 Business Basic';
    const CONFIG_GROUP_BASIC_DESCRIPTION = 'Synergy Wholesale Microsoft 365 Business Basic';
    const CONFIG_GROUP_STANDARD_NAME = 'Synergy Wholesale Microsoft 365 Business Standard';
    const CONFIG_GROUP_STANDARD_DESCRIPTION = 'Synergy Wholesale Microsoft 365 Business Standard';
    const CONFIG_GROUP_PREMIUM_NAME = 'Synergy Wholesale Microsoft 365 Business Premium';
    const CONFIG_GROUP_PREMIUM_DESCRIPTION = 'Synergy Wholesale Microsoft 365 Business Premium';

    /** FOR THESE CONFIG OPTION NAMES, LATER WE WILL ADD SYNERGY PRODUCT'S ID TO PREFIX */
    const CONFIG_OPTION_BASIC_NAME = 'Microsoft 365 Business Basic';
    const CONFIG_OPTION_STANDARD_NAME = 'Microsoft 365 Business Standard';
    const CONFIG_OPTION_PREMIUM_NAME = 'Microsoft 365 Business Premium';
    const CONFIG_OPTION_EXCHANGE_ONE = 'Exchange Online (Plan 1)';
    const CONFIG_OPTION_EXCHANGE_TWO = 'Exchange Online (Plan 2)';

    /** CONSTANTS FOR CONFIG OPTION TYPES */
    const OPTION_TYPE_QUANTITY = 4;
    const DEFAULT_MIN_QUANTITY = 0;
    const DEFAULT_MAX_QUANTITY = 0;
    const DEFAULT_ORDER = 0;
    const DEFAULT_HIDDEN = 0; // Means false

    /** CONSTANTS FOR ALL GROUPS AND ALL OPTIONS */
    const ALL_CONFIG_OPTIONS = [
        [
            'group' => self::CONFIG_GROUP_BASIC_NAME,
            'optionname' => self::CONFIG_OPTION_BASIC_NAME,
            'optiontype' => self::OPTION_TYPE_QUANTITY,
            'qtyminimum' => self::DEFAULT_MIN_QUANTITY,
            'qtymaximum' => self::DEFAULT_MAX_QUANTITY,
            'order' => self::DEFAULT_ORDER,
            'hidden' => self::DEFAULT_HIDDEN,
        ],
        [
            'group' => self::CONFIG_GROUP_STANDARD_NAME,
            'optionname' => self::CONFIG_OPTION_STANDARD_NAME,
            'optiontype' => self::OPTION_TYPE_QUANTITY,
            'qtyminimum' => self::DEFAULT_MIN_QUANTITY,
            'qtymaximum' => self::DEFAULT_MAX_QUANTITY,
            'order' => self::DEFAULT_ORDER,
            'hidden' => self::DEFAULT_HIDDEN,
        ],
        [
            'group' => self::CONFIG_GROUP_PREMIUM_NAME,
            'optionname' => self::CONFIG_OPTION_PREMIUM_NAME,
            'optiontype' => self::OPTION_TYPE_QUANTITY,
            'qtyminimum' => self::DEFAULT_MIN_QUANTITY,
            'qtymaximum' => self::DEFAULT_MAX_QUANTITY,
            'order' => self::DEFAULT_ORDER,
            'hidden' => self::DEFAULT_HIDDEN,
        ],
        [
            'group' => self::CONFIG_GROUP_STANDARD_NAME,
            'optionname' => self::CONFIG_OPTION_EXCHANGE_ONE,
            'optiontype' => self::OPTION_TYPE_QUANTITY,
            'qtyminimum' => self::DEFAULT_MIN_QUANTITY,
            'qtymaximum' => self::DEFAULT_MAX_QUANTITY,
            'order' => self::DEFAULT_ORDER,
            'hidden' => self::DEFAULT_HIDDEN,
        ],
        [
            'group' => self::CONFIG_GROUP_STANDARD_NAME,
            'optionname' => self::CONFIG_OPTION_EXCHANGE_TWO,
            'optiontype' => self::OPTION_TYPE_QUANTITY,
            'qtyminimum' => self::DEFAULT_MIN_QUANTITY,
            'qtymaximum' => self::DEFAULT_MAX_QUANTITY,
            'order' => self::DEFAULT_ORDER,
            'hidden' => self::DEFAULT_HIDDEN,
        ],
    ];

    const ALL_CONFIG_GROUPS = [
        [
            'name' => self::CONFIG_GROUP_BASIC_NAME,
            'description' => self::CONFIG_GROUP_BASIC_DESCRIPTION,
        ],
        [
            'name' => self::CONFIG_GROUP_STANDARD_NAME,
            'description' => self::CONFIG_GROUP_STANDARD_DESCRIPTION,
        ],
        [
            'name' => self::CONFIG_GROUP_PREMIUM_NAME,
            'description' => self::CONFIG_GROUP_PREMIUM_DESCRIPTION,
        ],
    ];
}