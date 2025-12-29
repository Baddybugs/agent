<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Support\Facades\View;
use BaddyBugs\Agent\Directives\FrontendDirectives;

class FrontendDirectivesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        
        // Enable BaddyBugs for tests
        config(['baddybugs.enabled' => true]);
        config(['baddybugs.frontend_enabled' => true]);
        config(['baddybugs.endpoint' => 'https://test.example.com']);
        config(['baddybugs.api_key' => 'test-api-key']);
        config(['baddybugs.project_id' => 'test-project']);
        
        // Register the directive
        FrontendDirectives::register();
    }

    /** @test */
    public function baddybugs_directive_renders_meta_tags()
    {
        // Share a trace_id as the middleware would
        View::share('baddybugs_trace_id', 'test-trace-id-123');
        View::share('baddybugs_config', [
            'endpoint' => 'https://test.example.com',
            'api_key' => 'test-api-key',
            'project_id' => 'test-project',
        ]);
        
        $view = view('test-baddybugs-directive');
        $html = $view->render();
        
        // Check for trace_id meta tag
        $this->assertStringContainsString('name="baddybugs-trace-id"', $html);
        $this->assertStringContainsString('content="test-trace-id-123"', $html);
        
        // Check for config meta tag
        $this->assertStringContainsString('name="baddybugs-config"', $html);
    }

    /** @test */
    public function baddybugs_directive_renders_javascript_api()
    {
        View::share('baddybugs_trace_id', 'test-trace-id-123');
        View::share('baddybugs_config', [
            'endpoint' => 'https://test.example.com',
        ]);
        
        $view = view('test-baddybugs-directive');
        $html = $view->render();
        
        // Check for window.Baddybugs API
        $this->assertStringContainsString('window.Baddybugs', $html);
        $this->assertStringContainsString('getTraceId', $html);
        $this->assertStringContainsString('getConfig', $html);
        $this->assertStringContainsString('record', $html);
    }

    /** @test */
    public function baddybugs_directive_does_not_render_when_disabled()
    {
        config(['baddybugs.enabled' => false]);
        
        View::share('baddybugs_trace_id', 'test-trace-id-123');
        
        $view = view('test-baddybugs-directive');
        $html = $view->render();
        
        // Should not render anything
        $this->assertStringNotContainsString('baddybugs-trace-id', $html);
        $this->assertStringNotContainsString('window.Baddybugs', $html);
    }

    /** @test */
    public function baddybugs_directive_handles_missing_trace_id_gracefully()
    {
        // Don't share trace_id
        View::share('baddybugs_config', [
            'endpoint' => 'https://test.example.com',
        ]);
        
        $view = view('test-baddybugs-directive');
        $html = $view->render();
        
        // Should still render but with empty trace_id
        $this->assertStringContainsString('window.Baddybugs', $html);
    }

    /** @test */
    public function baddybugs_directive_escapes_html_properly()
    {
        View::share('baddybugs_trace_id', '<script>alert("xss")</script>');
        View::share('baddybugs_config', [
            'endpoint' => '<script>alert("xss")</script>',
        ]);
        
        $view = view('test-baddybugs-directive');
        $html = $view->render();
        
        // Should be escaped
        $this->assertStringNotContainsString('<script>alert("xss")</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    /**
     * Create a test view for the directive
     */
    protected function createTestView()
    {
        $viewPath = resource_path('views/test-baddybugs-directive.blade.php');
        
        if (!file_exists(dirname($viewPath))) {
            mkdir(dirname($viewPath), 0755, true);
        }
        
        file_put_contents($viewPath, '@baddybugs');
        
        return $viewPath;
    }

    protected function tearDown(): void
    {
        // Clean up test view
        $viewPath = resource_path('views/test-baddybugs-directive.blade.php');
        if (file_exists($viewPath)) {
            unlink($viewPath);
        }
        
        parent::tearDown();
    }
}
