<?php

namespace Tests\Feature;

use Tests\TestCase;

class PublicPagesTest extends TestCase
{
    public function test_public_website_pages_are_available(): void
    {
        $pages = [
            '/',
            '/about-us',
            '/contact-us',
            '/download',
            '/terms-and-conditions',
            '/privacy-policy',
            '/refund-and-cancellation',
            '/return-policy',
            '/shipping-policy',
            '/copyright-policy',
            '/advertising-terms',
            '/faq',
            '/advertise',
            '/account/stats',
            '/analytics/nexframe',
            '/advertise/status/'.str_repeat('a', 48),
        ];

        foreach ($pages as $page) {
            $this->get($page)->assertOk();
        }
    }

    public function test_stats_page_has_a_retry_when_javascript_cannot_load(): void
    {
        $this->get('/account/stats?app=1')->assertOk()
            ->assertSee('Loading your account')
            ->assertSee('href="/account/stats?app=1"', false)
            ->assertSee('Enable JavaScript in your browser');
    }
}
