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
            '/advertise/status/'.str_repeat('a', 48),
        ];

        foreach ($pages as $page) {
            $this->get($page)->assertOk();
        }
    }
}
