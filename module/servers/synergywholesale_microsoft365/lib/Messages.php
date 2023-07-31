<?php

namespace WHMCS\Module\Server\SynergywholesaleMicrosoft365;

class Messages
{
    const SUCCESS = 'success';
    const TENANT_EXISTED = '[FAILED] This tenant has already been created.';
    const OK_SUSPEND = '[SUCCESS] Successfully suspended service.';
    const OK_UNSUSPEND = '[SUCCESS] Successfully unsuspended service.';
    const OK_TERMINATE = '[SUCCESS] Successfully terminated service.';
    const OK_CHANGE_PLAN = '[SUCCESS] Successfully changed plan for service.';
    const OK_SYNCHRONIZE = '[SUCCESS] Successfully synced data for service. ';
    const FAILED_SUSPEND_LIST = '[FAILED] Failed to suspend the following subscriptions: ';
    const FAILED_UNSUSPEND_LIST = '[FAILED] Failed to unsuspend the following subscriptions: ';
    const FAILED_TERMINATE_LIST = '[FAILED] Failed to terminate the following subscriptions: ';
    const FAILED_CHANGE_PLAN = '[FAILED] Failed to change plan for service.';
    const FAILED_SYNCHRONIZE = '[FAILED] Failed to synchronize data for service. ';
    const FAILED_INVALID_CONFIGURATION = '[FAILED] Unable to perform action due to invalid configuration.';
    const FAILED_MISSING_MODULE_CONFIGS = '[FAILED] Synergy Wholesale Reseller ID or API Key is missing.';
    const UNKNOWN_ERROR = "Unknown Error";
    const NO_CHANGES = "No changes were made.";
}