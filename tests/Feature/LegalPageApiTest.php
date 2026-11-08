<?php

namespace Tests\Feature;

use App\Models\LegalPage;
use App\Http\Middleware\AdminTokenMiddleware;
use App\Http\Middleware\AuthTokenMiddleware;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LegalPageApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('legal_pages', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('eyebrow')->nullable();
            $table->string('title');
            $table->string('effective_date')->nullable();
            $table->text('intro')->nullable();
            $table->json('sections');
            $table->boolean('is_published')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('legal_pages');
        parent::tearDown();
    }

    public function test_public_endpoint_returns_only_published_legal_pages(): void
    {
        LegalPage::create($this->payload(['slug' => 'published-page', 'is_published' => true]));
        LegalPage::create($this->payload(['slug' => 'draft-page', 'is_published' => false]));

        $this->getJson('/api/v4/legal-pages')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', 'published-page');

        $this->getJson('/api/v4/legal-pages/draft-page')->assertNotFound();
    }

    public function test_admin_can_create_and_update_a_legal_page(): void
    {
        $this->withoutMiddleware([
            AuthTokenMiddleware::class,
            AdminTokenMiddleware::class,
        ]);

        $created = $this->postJson('/api/v4/admin/legal-pages', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.title', 'Test policy');

        $id = $created->json('data.id');

        $this->putJson("/api/v4/admin/legal-pages/{$id}", [
            'title' => 'Updated policy',
            'is_published' => true,
        ])->assertOk()->assertJsonPath('data.title', 'Updated policy');

        $this->assertDatabaseHas('legal_pages', [
            'id' => $id,
            'title' => 'Updated policy',
            'is_published' => true,
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'slug' => 'test-policy',
            'eyebrow' => 'Legal',
            'title' => 'Test policy',
            'effective_date' => 'Effective today',
            'intro' => 'A test legal page.',
            'sections' => [
                ['heading' => 'First section', 'body' => 'Section body.'],
            ],
            'is_published' => false,
            'sort_order' => 1,
        ], $overrides);
    }
}
