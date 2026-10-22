<?php

namespace App\Services\Security\Analysis;

use RuntimeException;

/**
 * The AI analyst could not produce an analysis. The message is safe to show administrators.
 */
class SecurityAnalysisException extends RuntimeException {}
