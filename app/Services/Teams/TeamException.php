<?php

namespace App\Services\Teams;

use RuntimeException;

/**
 * A team operation that is not allowed in the team's current state. The message is safe to show to the user.
 */
class TeamException extends RuntimeException {}
