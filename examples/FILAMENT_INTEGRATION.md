# BaddyBugs + FilamentPHP Integration Example

## Automatic Integration

BaddyBugs works **automatically** with FilamentPHP with zero additional configuration needed.

### Step 1: Add @baddybugs to Your Filament Layout

If you're using a custom Filament layout, add the `@baddybugs` directive to your head section:

```blade
{{-- resources/views/vendor/filament-panels/components/layout.blade.php --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ config('app.name') }}</title>

    {{-- BaddyBugs Frontend Monitoring --}}
    @baddybugs

    {{-- Filament Styles --}}
    @filamentStyles
    
    {{-- Your custom styles --}}
    @vite('resources/css/app.css')
</head>
<body class="antialiased">
    {{ $slot }}

    @filamentScripts
    @vite('resources/js/app.js')
</body>
</html>
```

### Step 2: That's It!

BaddyBugs will now automatically monitor:

- ✅ **Filament Resources** - All CRUD operations (create, read, update, delete)
- ✅ **Filament Widgets** - Chart updates, stats widgets, custom widgets
- ✅ **Filament Actions** - Modal actions, bulk actions, table actions
- ✅ **Filament Forms** - Validation errors, field updates
- ✅ **Filament Tables** - Sorting, filtering, search, pagination
- ✅ **Filament Notifications** - Success, error, warning toasts
- ✅ **Custom Livewire Components** - Any component you create

## What Gets Tracked

### 1. Component Errors
```
Type: livewire_error
Name: message_failed
Payload:
  - component: App\Filament\Resources\UserResource\Pages\ListUsers
  - duration_ms: 1234
  - response_status: 500
  - trace_id: <shared-with-backend>
```

### 2. Slow Operations
```
Type: livewire_performance  
Name: slow_request
Payload:
  - component: App\Filament\Widgets\StatsOverviewWidget
  - duration_ms: 11500 (exceeded 10s threshold)
  - updates: {...}
  - trace_id: <shared-with-backend>
```

### 3. Dehydration Errors
```
Type: livewire_error
Name: dehydration_exception
Payload:
  - component: App\Filament\Resources\OrderResource
  - exception: Symfony\Component\HttpKernel\Exception\HttpException
  - message: "Property [orders] not found on component"
  - trace_id: <shared-with-backend>
```

## Correlation with Backend Events

Every Livewire event shares the **same trace_id** as backend events, allowing you to:

1. See the exact SQL queries that caused a slow Filament table load
2. Trace a Filament form submission from frontend → validation → database → response
3. Debug Filament widget errors by seeing the complete backend context
4. Monitor Filament action performance end-to-end

### Example Trace Timeline:
```
[trace_id: 9d8f7e6a-5b4c-3d2e-1a0b-9c8d7e6f5a4b]

Timeline:
09:15:23.100 - livewire_component.initialized (FilamentTableWidget)
09:15:23.102 - request.started (GET /admin/users)
09:15:23.105 - query.executed (SELECT * FROM users) - 450ms ⚠️
09:15:23.555 - query.executed (SELECT * FROM roles) - 45ms
09:15:23.600 - request.completed - 500ms total
09:15:23.602 - livewire_performance.slow_request - 502ms total
```

## Configuration

Fine-tune Livewire monitoring in `config/baddybugs.php`:

```php
// Enable/disable Livewire monitoring
'livewire_monitoring_enabled' => env('BADDYBUGS_LIVEWIRE_MONITORING', true),

// Adjust slow request threshold (10 seconds default)
'livewire_timeout_threshold' => env('BADDYBUGS_LIVEWIRE_TIMEOUT_MS', 10000),

// Track component initialization (creates more events)
'livewire_track_initialization' => env('BADDYBUGS_LIVEWIRE_TRACK_INIT', false),
```

## Advanced: Accessing trace_id in JavaScript

If you need to access the trace_id in custom Filament JavaScript:

```javascript
// Get the current trace ID
const traceId = window.Baddybugs.getTraceId();

// Get BaddyBugs configuration
const config = window.Baddybugs.getConfig();
console.log(config.project_id); // your-project-id

// Record custom events (requires @baddybugs/js-sdk)
window.Baddybugs.record('custom_event', {
    action: 'bulk_delete_users',
    count: 25
});
```

## Performance Impact

BaddyBugs adds **< 0.5ms overhead** to each Livewire request with:
- Zero user-facing latency (terminable middleware)
- Zero database queries for monitoring
- Conditional execution (only when enabled)
- Automatic sampling support

You can disable it any time with:

```env
BADDYBUGS_LIVEWIRE_MONITORING=false
```

## Support for Filament Plugins

BaddyBugs automatically works with ALL Filament plugins:
- Filament Tables
- Filament Forms  
- Filament Notifications
- Filament Actions
- Filament Infolists
- Any custom Filament plugin that uses Livewire

No additional configuration needed!
