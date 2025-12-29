<?php

namespace BaddyBugs\Agent\Middleware;

use BaddyBugs\Agent\BaddyBugs;
use Closure;
use Illuminate\Http\Request;

/**
 * Deployment Detection Middleware
 * 
 * Automatically detects new deployments and triggers deployment_started event.
 * Only triggers once per deployment change.
 */
class DetectDeployment
{
    protected BaddyBugs $baddybugs;

    public function __construct(BaddyBugs $baddybugs)
    {
        $this->baddybugs = $baddybugs;
    }

    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        // Check if auto-detection is enabled
        if (!config('baddybugs.auto_detect_deployment', true)) {
            return $next($request);
        }

        // Check if regression analysis is enabled
        if (!config('baddybugs.regression_analysis_enabled', true)) {
            return $next($request);
        }

        // Get deployment tracker
        $deploymentTracker = $this->baddybugs->getDeploymentTracker();

        if (!$deploymentTracker) {
            return $next($request);
        }

        // Check if this is a new deployment
        if ($deploymentTracker->isNewDeployment()) {
            // Trigger deployment event
            $this->baddybugs->deployment(
                $deploymentTracker->getDeploymentTag(),
                [
                    'auto_detected' => true,
                    'detection_method' => $deploymentTracker->getDeploymentSource(),
                    'first_request_url' => $request->fullUrl(),
                    'first_request_method' => $request->method(),
                ]
            );
        }

        return $next($request);
    }
}
