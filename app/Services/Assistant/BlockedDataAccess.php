<?php

namespace App\Services\Assistant;

use RuntimeException;

/**
 * The assistant tried to read data that {@see AssistantDataPolicy} protects. The query was not run.
 */
class BlockedDataAccess extends RuntimeException {}
