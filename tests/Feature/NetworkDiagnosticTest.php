<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Mockery;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

class NetworkDiagnosticTest extends TestCase
{
    public function test_page_logs_visit_and_provides_uncached_signed_test_urls(): void
    {
        $logger = Mockery::mock(LoggerInterface::class);
        Log::shouldReceive('channel')->with('network_diagnostics')->once()->andReturn($logger);
        $logger->shouldReceive('info')->once()->with('page_opened', Mockery::on(
            fn ($context) => Str::isUuid($context['reference']) && ! isset($context['cookie'])
        ));

        $response = $this->get('/network-check')->assertOk()
            ->assertViewIs('network-check')->assertSee('Support reference');

        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('signature=', $response->viewData('probeUrl'));
        $this->assertStringContainsString('signature=', $response->viewData('reportUrl'));
    }

    public function test_signed_api_probe_logs_reachability_without_authentication(): void
    {
        $id = (string) Str::uuid();
        $logger = Mockery::mock(LoggerInterface::class);
        Log::shouldReceive('channel')->with('network_diagnostics')->once()->andReturn($logger);
        $logger->shouldReceive('info')->once()->with('probe_reached', Mockery::on(
            fn ($context) => $context['reference'] === $id
        ));

        $url = URL::temporarySignedRoute('network-check.probe', now()->addMinutes(5), ['id' => $id], absolute: false);
        $this->getJson($url)->assertOk()->assertJson(['ok' => true, 'reference' => $id]);
    }

    public function test_browser_report_is_validated_and_saved_with_same_reference(): void
    {
        $id = (string) Str::uuid();
        $logger = Mockery::mock(LoggerInterface::class);
        Log::shouldReceive('channel')->with('network_diagnostics')->once()->andReturn($logger);
        $logger->shouldReceive('info')->once()->with('browser_report', Mockery::on(
            fn ($context) => $context['reference'] === $id
                && count($context['client_reported']['tests']) === 3
                && ! isset($context['client_reported']['token'])
        ));

        $url = URL::temporarySignedRoute('network-check.report', now()->addMinutes(5), ['id' => $id], absolute: false);
        $this->postJson($url, [
            'online' => true,
            'token' => 'must-not-be-logged',
            'tests' => array_fill(0, 3, ['ok' => false, 'status' => null, 'duration_ms' => 10000, 'error' => 'timeout']),
        ])->assertOk()->assertJson(['saved' => true, 'reference' => $id]);
    }

    public function test_missing_expired_and_modified_signatures_are_rejected(): void
    {
        $id = (string) Str::uuid();
        $this->getJson('/api/network-check/'.$id.'/probe')->assertForbidden();
        $expired = URL::temporarySignedRoute('network-check.probe', now()->subMinute(), ['id' => $id], absolute: false);
        $this->getJson($expired)->assertForbidden();
        $url = URL::temporarySignedRoute('network-check.report', now()->addMinutes(5), ['id' => $id], absolute: false);
        $this->postJson(str_replace($id, (string) Str::uuid(), $url), [])->assertForbidden();
    }

    public function test_report_rejects_unbounded_or_unknown_test_fields(): void
    {
        $url = URL::temporarySignedRoute('network-check.report', now()->addMinutes(5), ['id' => (string) Str::uuid()], absolute: false);
        $this->postJson($url, [
            'online' => true,
            'tests' => array_fill(0, 3, ['ok' => true, 'duration_ms' => 60001, 'arbitrary_data' => 'rejected']),
        ])->assertUnprocessable()->assertJsonValidationErrors(['tests.0', 'tests.0.duration_ms']);
    }
}
