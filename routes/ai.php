<?php

use App\Http\Middleware\AuthenticateWidgetData;
use App\Mcp\Servers\WidgetDataServer;
use Laravel\Mcp\Facades\Mcp;

// Data connection for the third-party assistant widget (Admin → Integrations → Third-party assistant widget → Data access).
Mcp::web('/mcp/widgets', WidgetDataServer::class)
    ->middleware(['throttle:mcp-widgets', AuthenticateWidgetData::class])
    ->name('mcp.widgets');
