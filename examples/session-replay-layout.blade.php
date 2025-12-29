{{--
    Example Layout with Session Replay
    
    This demonstrates how to use BaddyBugs Session Replay in your Laravel application.
    The @baddybugs directive automatically injects all necessary configuration.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ config('app.name') }}</title>

    {{-- 
        BaddyBugs Monitoring + Session Replay
        
        This single directive injects:
        1. Trace ID for correlation
        2. Frontend monitoring config
        3. Session replay config (if enabled)
        4. JavaScript API (window.Baddybugs)
    --}}
    @baddybugs

    {{-- Fonts --}}
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

    {{-- Scripts --}}
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    
    @livewireStyles
</head>
<body class="font-sans antialiased">
    <div class="min-h-screen bg-gray-100">
        {{-- Navigation --}}
        @include('layouts.navigation')

        {{-- Page Heading --}}
        @if (isset($header))
            <header class="bg-white shadow">
                <div class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8">
                    {{ $header }}
                </div>
            </header>
        @endif

        {{-- Page Content --}}
        <main>
            {{ $slot }}
        </main>
    </div>

    @livewireScripts

    {{-- 
        Optional: Custom JavaScript to interact with BaddyBugs API
        
        The @baddybugs directive exposes window.Baddybugs with useful methods.
    --}}
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Check if session replay is enabled
            const replayConfig = window.Baddybugs.getSessionReplayConfig();
            
            if (replayConfig.enabled) {
                console.log('🎬 Session Replay Active');
                console.log('📋 Trace ID:', replayConfig.trace_id);
                console.log('🔒 Privacy Mode:', replayConfig.privacy_mode);
                console.log('📊 Sampling Rate:', replayConfig.sampling_rate);
                
                // Example: Show a badge for admins when session is being recorded
                @auth
                    @if(auth()->user()->isAdmin())
                        const badge = document.createElement('div');
                        badge.className = 'fixed bottom-4 right-4 bg-red-500 text-white px-3 py-1 rounded-full text-xs';
                        badge.textContent = '🔴 Recording';
                        badge.title = 'Session is being recorded for debugging';
                        document.body.appendChild(badge);
                    @endif
                @endauth
            }
            
            // Example: Record custom events
            document.querySelectorAll('[data-track-click]').forEach(element => {
                element.addEventListener('click', function() {
                    if (window.Baddybugs) {
                        window.Baddybugs.record('custom_click', {
                            element: this.dataset.trackClick,
                            trace_id: window.Baddybugs.getTraceId()
                        });
                    }
                });
            });
        });
    </script>
</body>
</html>
