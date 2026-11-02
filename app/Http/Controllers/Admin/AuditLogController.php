<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin → Security → Audit log: who changed what, newest first.
 */
class AuditLogController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'action' => ['nullable', 'string', 'max:100'],
        ]);
        $action = $filters['action'] ?? null;

        $entries = AuditLog::query()
            ->with('actor')
            // "user" matches user.updated, user.roles_changed, ...
            ->when($action, fn ($query) => $query->where('action', 'like', str_replace(['%', '_'], ['\%', '\_'], $action).'%'))
            ->latest('id')
            ->paginate(50)
            ->withQueryString()
            ->through(fn (AuditLog $entry) => [
                'id' => $entry->id,
                'at' => $entry->created_at?->toIso8601String(),
                'actor' => match (true) {
                    $entry->actor_id === null => 'System',
                    $entry->actor === null => 'Removed user',
                    $entry->actor->trashed() => 'Deleted user #'.$entry->actor_id,
                    default => $entry->actor->display_name,
                },
                'action' => $entry->action,
                'subject' => $entry->auditable_type === null ? null : class_basename($entry->auditable_type).' #'.$entry->auditable_id,
                'changes' => $entry->changes,
                'ip_address' => $entry->ip_address,
            ]);

        return Inertia::render('Admin/AuditLog', [
            'entries' => $entries,
            'filters' => ['action' => $action],
        ]);
    }
}
