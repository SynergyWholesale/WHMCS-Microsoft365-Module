<?php

namespace WHMCS\Module\Server\SynergywholesaleMicrosoft365;

class ServiceStatuses
{
    const STATUS_DELETED = 'Deleted';
    const STATUS_CANCELLED = 'Cancelled';
    const STATUS_ACTIVE = 'Active';
    const STATUS_SUSPENDED = 'Suspended';
    const STATUS_STAFF_SUSPENDED = 'Suspended By Staff';
    const STATUS_PENDING = 'Pending';
    const STATUS_TERMINATED = 'Terminated';
    const ACTIVE_STATUS = [
        self::STATUS_ACTIVE,
        self::STATUS_PENDING,
    ];
    const SUSPENDED_STATUS = [
        self::STATUS_SUSPENDED,
        self::STATUS_STAFF_SUSPENDED,
    ];

    const TERMINATED_STATUS = [
        self::STATUS_DELETED,
        self::STATUS_CANCELLED,
        self::STATUS_TERMINATED,
    ];
}