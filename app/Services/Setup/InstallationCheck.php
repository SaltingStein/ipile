<?php

namespace App\Services\Setup;

use Illuminate\Database\QueryException;
use Illuminate\Encryption\MissingAppKeyException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Explains the install commands when a fresh deploy has no app key or no database tables yet, instead of a
 * bare error page. A database that is merely unreachable on an installed site is left to the normal error page.
 */
class InstallationCheck
{
    public function render(Throwable $exception, Request $request): ?Response
    {
        $reason = match (true) {
            $exception instanceof MissingAppKeyException => 'app_key',
            $exception instanceof QueryException && $this->tablesAreMissing() => 'database',
            default => null,
        };

        if ($reason === null) {
            return null;
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => 'This site is not installed yet.'], 503);
        }

        return response()->view('setup.install', ['reason' => $reason], 503);
    }

    public function tablesAreMissing(): bool
    {
        try {
            return ! Schema::hasTable('users');
        } catch (Throwable) {
            return false;
        }
    }
}
