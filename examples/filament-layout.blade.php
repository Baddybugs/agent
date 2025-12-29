{{--
    Filament Admin Panel Layout with BaddyBugs Monitoring
    
    This is an example of how to integrate BaddyBugs with FilamentPHP.
    
    To use this:
    1. Publish Filament's layout: php artisan vendor:publish --tag=filament-panels
    2. Add @baddybugs to the <head> section as shown below
    3. That's it! All Filament components will be automatically monitored
--}}
<!DOCTYPE html>
<html 
    lang="{{ str_replace('_', '-', app()->getLocale()) }}"
    dir="{{ __('filament-panels::layout.direction') ?? 'ltr' }}"
    class="antialiased"
>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    {{-- Application Name --}}
    <title>{{ filled($heading = ($livewire ?? null)?->getHeading()) ? "{$heading} - " : null }}{{ config('app.name') }}</title>

    {{-- 
        BaddyBugs Frontend Monitoring
        
        This single directive enables:
        - Trace ID exposure for correlation
        - Livewire error tracking
        - Livewire performance monitoring
        - Automatic FilamentPHP support
    --}}
    @baddybugs

    {{-- Favicon --}}
    <link rel="icon" type="image/x-icon" href="{{ asset('favicon.ico') }}">

    {{-- Filament Styles --}}
    @filamentStyles
    @vite('resources/css/filament/admin/theme.css')
</head>

<body class="antialiased">
    {{ $slot }}

    @livewire('notifications')

    {{-- Filament Scripts --}}
    @filamentScripts
    @vite('resources/js/app.js')

    {{-- 
        Optional: Custom JavaScript that uses BaddyBugs API
        
        The @baddybugs directive exposes window.Baddybugs with:
        - getTraceId(): Get current trace ID
        - getConfig(): Get BaddyBugs configuration
        - record(event, data): Record custom events
    --}}
    <script>
        // Example: Track custom Filament interactions
        document.addEventListener('DOMContentLoaded', function() {
            // Log the trace ID for debugging
            if (window.Baddybugs) {
                const traceId = window.Baddybugs.getTraceId();
                console.log('BaddyBugs Trace ID:', traceId);
                
                // Example: Track when user opens a modal
                document.addEventListener('open-modal', function(event) {
                    if (window.Baddybugs) {
                        window.Baddybugs.record('filament_modal_opened', {
                            modal_id: event.detail?.id,
                            trace_id: traceId
                        });
                    }
                });
            }
        });
    </script>
</body>
</html>
