<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     */
    public function test_the_application_returns_a_successful_response(): void
    {
        $response = $this->get('/');

        $response
            ->assertStatus(200)
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Welcome')
                ->where('canLogin', true)
                ->where('canRegister', true)
                ->missing('laravelVersion')
                ->missing('phpVersion')
            );
    }

    public function test_reverse_proxy_https_headers_generate_https_asset_and_route_urls(): void
    {
        $response = $this->withHeaders([
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-Host' => 'example.snapdeploy.app',
            'X-Forwarded-Port' => '443',
        ])->get('/');

        $response->assertStatus(200);

        $html = $response->getContent();
        $this->assertStringContainsString('https://example.snapdeploy.app', $html);
        $this->assertStringNotContainsString('http://example.snapdeploy.app', $html);
    }

    public function test_force_https_scheme_ensures_https_urls_when_proxy_header_is_absent(): void
    {
        URL::forceScheme('https');

        try {
            $response = $this->get('http://example.snapdeploy.app/');
            $response->assertStatus(200);

            $html = $response->getContent();
            $this->assertStringContainsString('https://example.snapdeploy.app', $html);
            $this->assertStringNotContainsString('http://example.snapdeploy.app', $html);
        } finally {
            URL::forceScheme(null);
        }
    }
}
