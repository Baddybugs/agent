<?php

namespace Tests\Unit\Middleware;

use Tests\TestCase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use BaddyBugs\Agent\BaddyBugs;
use BaddyBugs\Agent\Middleware\InjectTraceIdMiddleware;

class InjectTraceIdMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        
        // Reset config for each test
        config(['baddybugs.enabled' => true]);
        config(['baddybugs.frontend_enabled' => true]);
        config(['baddybugs.expose_trace_id' => true]);
    }

    /** @test */
    public function it_injects_trace_id_into_views_when_enabled()
    {
        $middleware = new InjectTraceIdMiddleware();
        $request = Request::create('/test');
        
        $middleware->handle($request, function ($req) {
            // Check that trace_id was shared with views
            $sharedData = view()->getShared();
            
            $this->assertArrayHasKey('baddybugs_trace_id', $sharedData);
            $this->assertIsString($sharedData['baddybugs_trace_id']);
            $this->assertNotEmpty($sharedData['baddybugs_trace_id']);
            
            return new Response('OK');
        });
    }

    /** @test */
    public function it_injects_config_into_views_when_enabled()
    {
        config(['baddybugs.endpoint' => 'https://test.example.com']);
        config(['baddybugs.api_key' => 'test-api-key']);
        config(['baddybugs.project_id' => 'test-project']);
        
        $middleware = new InjectTraceIdMiddleware();
        $request = Request::create('/test');
        
        $middleware->handle($request, function ($req) {
            $sharedData = view()->getShared();
            
            $this->assertArrayHasKey('baddybugs_config', $sharedData);
            $this->assertIsArray($sharedData['baddybugs_config']);
            $this->assertEquals('https://test.example.com', $sharedData['baddybugs_config']['endpoint']);
            $this->assertEquals('test-api-key', $sharedData['baddybugs_config']['api_key']);
            $this->assertEquals('test-project', $sharedData['baddybugs_config']['project_id']);
            
            return new Response('OK');
        });
    }

    /** @test */
    public function it_does_not_inject_when_baddybugs_disabled()
    {
        config(['baddybugs.enabled' => false]);
        
        $middleware = new InjectTraceIdMiddleware();
        $request = Request::create('/test');
        
        $middleware->handle($request, function ($req) {
            $sharedData = view()->getShared();
            
            $this->assertArrayNotHasKey('baddybugs_trace_id', $sharedData);
            
            return new Response('OK');
        });
    }

    /** @test */
    public function it_does_not_inject_when_frontend_disabled()
    {
        config(['baddybugs.frontend_enabled' => false]);
        
        $middleware = new InjectTraceIdMiddleware();
        $request = Request::create('/test');
        
        $middleware->handle($request, function ($req) {
            $sharedData = view()->getShared();
            
            $this->assertArrayNotHasKey('baddybugs_trace_id', $sharedData);
            
            return new Response('OK');
        });
    }

    /** @test */
    public function it_does_not_inject_when_expose_trace_id_disabled()
    {
        config(['baddybugs.expose_trace_id' => false]);
        
        $middleware = new InjectTraceIdMiddleware();
        $request = Request::create('/test');
        
        $middleware->handle($request, function ($req) {
            $sharedData = view()->getShared();
            
            $this->assertArrayNotHasKey('baddybugs_trace_id', $sharedData);
            
            return new Response('OK');
        });
    }

    /** @test */
    public function it_handles_missing_baddybugs_instance_gracefully()
    {
        // This tests that the middleware doesn't crash if BaddyBugs isn't properly initialized
        $middleware = new InjectTraceIdMiddleware();
        $request = Request::create('/test');
        
        $response = $middleware->handle($request, function ($req) {
            return new Response('OK');
        });
        
        $this->assertEquals(200, $response->getStatusCode());
    }

    /** @test */
    public function it_uses_correct_trace_id_from_baddybugs_instance()
    {
        $baddybugs = app(BaddyBugs::class);
        $expectedTraceId = $baddybugs->getTraceId();
        
        $middleware = new InjectTraceIdMiddleware();
        $request = Request::create('/test');
        
        $middleware->handle($request, function ($req) use ($expectedTraceId) {
            $sharedData = view()->getShared();
            
            $this->assertEquals($expectedTraceId, $sharedData['baddybugs_trace_id']);
            
            return new Response('OK');
        });
    }
}
