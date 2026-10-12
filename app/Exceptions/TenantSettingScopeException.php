<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a tenant-owned setting is written while multi-business is enabled
 * but no active business context is available.
 *
 * Tenant configuration must never land on the shared global row (which would
 * leak one business's value to another). It is first resolved through the ADR-04
 * business scope; when there is no business to attribute the write to, the
 * operation fails closed instead of leaking.
 */
class TenantSettingScopeException extends RuntimeException {}
