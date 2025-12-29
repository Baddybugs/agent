<?php

namespace BaddyBugs\Agent\Directives;

use Illuminate\Support\Facades\Blade;

/**
 * Frontend Blade Directives
 * 
 * Provides the @baddybugs directive for easy integration of frontend monitoring.
 */
class FrontendDirectives
{
    /**
     * Register all frontend directives.
     *
     * @return void
     */
    public static function register(): void
    {
        // Register @baddybugs directive
        Blade::directive('baddybugs', function () {
            return static::getBaddyBugsDirectiveCode();
        });
    }

    /**
     * Generate the code for the @baddybugs directive.
     *
     * @return string
     */
    protected static function getBaddyBugsDirectiveCode(): string
    {
        return <<<'PHP'
<?php
    // Only output if frontend monitoring is enabled
    if (config('baddybugs.enabled') && config('baddybugs.frontend_enabled')) {
        $traceId = $baddybugs_trace_id ?? '';
        $baseConfig = $baddybugs_config ?? [];
        
        // Build complete frontend configuration
        $config = [
            'apiKey' => $baseConfig['api_key'] ?? config('baddybugs.api_key'),
            'projectId' => $baseConfig['project_id'] ?? config('baddybugs.project_id'),
            'endpoint' => config('baddybugs.endpoint'),
            'debug' => config('app.debug'),
            'traceId' => $traceId,
            'webVitals' => [
                'enabled' => config('baddybugs.frontend_web_vitals_enabled', true),
                'sampling' => config('baddybugs.frontend_web_vitals_sampling_rate', 1.0),
            ],
            
            // Session Replay
            'sessionReplay' => config('baddybugs.session_replay'),
            
            // Network Monitoring
            'network' => config('baddybugs.frontend.network'),
            
            // Console Capture
            'console' => config('baddybugs.frontend.console'),
            
            // User Frustration (Rage/Dead Clicks)
            'frustration' => config('baddybugs.frontend.frustration'),
            
            // Breadcrumbs
            'breadcrumbs' => config('baddybugs.frontend.breadcrumbs'),
            
            // Offline Support
            'offline' => config('baddybugs.frontend.offline'),
        ];

        $configJson = json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        
        echo "\n<!-- BaddyBugs Frontend Monitoring -->\n";
        
        echo '<meta name="baddybugs-trace-id" content="' . e($traceId) . '">' . "\n";
        
        // Inject config meta tag
        echo '<meta name="baddybugs-config" content="' . e($configJson) . '">' . "\n";
        
        // Inject minimal window.Baddybugs API
        echo <<<'SCRIPT'
<script>
  // BaddyBugs Frontend API (Minimal Stub)
  // This provides basic functionality for Livewire monitoring
  // For full frontend monitoring (Inertia, Vue, React), use @baddybugs/js-sdk
  (function() {
    'use strict';
    
    // Don't override if full SDK is already loaded
    if (window.Baddybugs && window.Baddybugs._isFullSDK) {
      return;
    }
    
    // Track if we've shown the SDK warning
    let sdkWarningShown = false;
    
    window.Baddybugs = {
      
      /**
       * Indicate this is the stub, not full SDK
       */
      _isStub: true,
      _isFullSDK: false,
      
      /**
       * Get the current trace ID
       * @returns {string|null}
       */
      getTraceId: function() {
        const meta = document.querySelector('meta[name="baddybugs-trace-id"]');
        return meta ? meta.content : null;
      },
      
      /**
       * Get the BaddyBugs configuration
       * @returns {object}
       */
      getConfig: function() {
        const meta = document.querySelector('meta[name="baddybugs-config"]');
        if (!meta) return {};
        
        try {
          return JSON.parse(meta.content);
        } catch (e) {
          console.error('[BaddyBugs] Failed to parse config:', e);
          return {};
        }
      },
      
      /**
       * Get the Session Replay configuration
       * @returns {object}
       */
      getSessionReplayConfig: function() {
        const meta = document.querySelector('meta[name="baddybugs-session-replay-config"]');
        if (!meta) return { enabled: false };
        
        try {
          return JSON.parse(meta.content);
        } catch (e) {
          console.error('[BaddyBugs] Failed to parse session replay config:', e);
          return { enabled: false };
        }
      },
      
      /**
       * Record a custom event (stub for future JS SDK)
       * @param {string} event - Event name
       * @param {object} payload - Event data
       */
      record: function(event, payload) {
        // Validate parameters
        if (!event || typeof event !== 'string') {
          console.error('[BaddyBugs] record() requires event name as string');
          return;
        }
        
        // Default payload
        if (typeof payload === 'undefined') {
          payload = {};
        }
        
        // Ensure payload is an object
        if (typeof payload !== 'object' || payload === null) {
          console.error('[BaddyBugs] record() payload must be an object');
          return;
        }
        
        // Initialize buffer if needed
        if (!this._buffer) {
          this._buffer = [];
        }
        
        // Add to buffer
        this._buffer.push({
          event: event,
          payload: payload,
          trace_id: this.getTraceId(),
          timestamp: Date.now()
        });
        
        // Prevent memory leaks - trim buffer if too large
        if (this._buffer.length > 100) {
          this._buffer.shift();
        }
        
        // Show SDK warning only once
        if (!sdkWarningShown && console && console.warn) {
          console.warn(
            '[BaddyBugs] JS SDK not loaded. Events are buffered but not sent. ' +
            'Install @baddybugs/js-sdk for full frontend monitoring. ' +
            'Buffered events:', this._buffer.length
          );
          sdkWarningShown = true;
        }
      },
      
      /**
       * Get buffered events (for debugging or SDK migration)
       * @returns {array}
       */
      getBuffer: function() {
        return this._buffer || [];
      },
      
      /**
       * Clear the event buffer
       */
      clearBuffer: function() {
        this._buffer = [];
      },
      
      /**
       * Check if this is the stub or full SDK
       * @returns {boolean}
       */
      isStub: function() {
        return this._isStub === true;
      },
      
      /**
       * Get SDK version/type info
       * @returns {object}
       */
      getSDKInfo: function() {
        return {
          type: this._isStub ? 'stub' : 'full',
          version: this._version || 'unknown',
          bufferedEvents: this._buffer ? this._buffer.length : 0
        };
      },
      
      initWebVitals: function() {
        var cfg = this.getConfig();
        var enabled = cfg && cfg.webVitals && cfg.webVitals.enabled !== false;
        var rate = cfg && cfg.webVitals && typeof cfg.webVitals.sampling !== 'undefined' ? Number(cfg.webVitals.sampling) : 1;
        if (!enabled) return;
        if (isNaN(rate) || rate <= 0) return;
        if (rate < 1 && Math.random() > rate) return;
        var lcpValue = null;
        var clsValue = 0;
        var inpValue = null;
        try {
          if ('PerformanceObserver' in window) {
            var lcpObserver = new PerformanceObserver(function(list) {
              var entries = list.getEntries();
              if (entries && entries.length) {
                var last = entries[entries.length - 1];
                lcpValue = last.startTime;
              }
            });
            try { lcpObserver.observe({ type: 'largest-contentful-paint', buffered: true }); } catch(e) {}
            var clsObserver = new PerformanceObserver(function(list) {
              list.getEntries().forEach(function(entry) {
                if (!entry.hadRecentInput) {
                  clsValue += entry.value;
                }
              });
            });
            try { clsObserver.observe({ type: 'layout-shift', buffered: true }); } catch(e) {}
            if (PerformanceObserver.supportedEntryTypes && PerformanceObserver.supportedEntryTypes.indexOf('event') !== -1) {
              var inpObserver = new PerformanceObserver(function(list) {
                var entries = list.getEntries();
                entries.forEach(function(entry) {
                  var dur = entry.duration || 0;
                  if (inpValue === null || dur > inpValue) {
                    inpValue = dur;
                  }
                });
              });
              try { inpObserver.observe({ type: 'event', buffered: true, durationThreshold: 40 }); } catch(e) {}
            }
          }
        } catch (e) {}
        var flush = function() {
          window.Baddybugs.record('web_vitals', {
            lcp_ms: lcpValue !== null ? Math.round(lcpValue) : null,
            cls: clsValue,
            inp_ms: inpValue !== null ? Math.round(inpValue) : null,
            url: location.href
          });
        };
        if (document.readyState === 'complete') {
          setTimeout(flush, 0);
        } else {
          window.addEventListener('load', function() { setTimeout(flush, 0); });
        }
      },
      
      // Internal buffer for events  
      _buffer: [],
      _version: '1.0.0-stub'
    };
    
    // Expose globally
    if (typeof window !== 'undefined') {
      window.Baddybugs = window.Baddybugs;
    }
    
    // Allow full SDK to replace this stub
    Object.defineProperty(window, 'BaddybugsStub', {
      value: window.Baddybugs,
      writable: false,
      configurable: false
    });
    
    try { window.Baddybugs.initWebVitals(); } catch(e) {}
  })();
</script>

SCRIPT;
        
        echo "<!-- /BaddyBugs -->\n";
    }
?>
PHP;
    }
}
