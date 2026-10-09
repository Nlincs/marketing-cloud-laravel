<?php

namespace Nlincs\MarketingCloudLaravel;

use RuntimeException;

/**
 * Marketing Cloud accepted the request but refused it (e.g. TriggeredSpamFilter,
 * an invalid email or list ID). Retrying the same data will not succeed.
 */
class MarketingCloudRejectedException extends RuntimeException
{
}
