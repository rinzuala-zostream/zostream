<?php

namespace Tests\Feature;

use App\Models\SessionTokenModel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AdminMovieApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('session_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('user_id');
            $table->string('access_token');
            $table->string('refresh_token')->nullable();
            $table->dateTime('access_expires_at');
            $table->dateTime('refresh_expires_at')->nullable();
            $table->string('device_id')->nullable();
            $table->timestamps();
        });

        Schema::create('admin_users', function (Blueprint $table) {
            $table->id();
            $table->string('admin_uid')->unique();
            $table->timestamps();
        });

        Schema::create('movie', function (Blueprint $table) {
            $table->increments('num');
            $table->string('id')->unique();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('director')->nullable();
            $table->string('duration')->nullable();
            $table->string('cover_img')->nullable();
            $table->string('poster')->nullable();
            $table->string('genre')->nullable();
            $table->text('url')->nullable();
            $table->text('dash_url')->nullable();
            $table->text('hls_url')->nullable();
            $table->text('trailer')->nullable();
            $table->text('subtitle')->nullable();
            $table->date('release_on')->nullable();
            $table->string('status')->nullable();
            $table->boolean('isEnable')->default(true);
            $table->boolean('isMizo')->default(true);
            $table->boolean('isAgeRestricted')->default(false);
            $table->boolean('isSeason')->default(false);
        });

        DB::table('session_tokens')->insert([
            'user_id' => 'admin-user',
            'access_token' => SessionTokenModel::digest('admin-access-token'),
            'access_expires_at' => now()->addHour(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('admin_users')->insert([
            'admin_uid' => 'admin-user',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_admin_search_finds_draft_movies_by_director(): void
    {
        DB::table('movie')->insert([
            'id' => 'draft-movie',
            'title' => 'Hidden Catalogue Entry',
            'description' => 'Not visible in the public catalogue.',
            'director' => 'Jane Searchable',
            'duration' => '95',
            'genre' => 'Drama',
            'status' => 'Draft',
            'isSeason' => false,
        ]);

        $response = $this->withHeaders($this->adminHeaders())
            ->getJson('/api/v4/admin/catalog/items/search?q=Searchable');

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.count', 1)
            ->assertJsonPath('data.data.0.id', 'draft-movie')
            ->assertJsonPath('data.data.0.title', 'Hidden Catalogue Entry');
    }

    public function test_admin_search_accepts_a_single_character_query(): void
    {
        DB::table('movie')->insert([
            'id' => 'x-movie',
            'title' => 'X',
            'status' => 'Published',
            'isSeason' => false,
        ]);

        $this->withHeaders($this->adminHeaders())
            ->getJson('/api/v4/admin/catalog/items/search?q=X')
            ->assertOk()
            ->assertJsonPath('data.count', 1);
    }

    public function test_legacy_search_uses_full_catalogue_for_authenticated_admin(): void
    {
        config(['app.api_key' => 'test-api-key']);

        DB::table('movie')->insert([
            'id' => 'supernatural-draft',
            'title' => 'Supernatural',
            'status' => 'Draft',
            'isSeason' => true,
        ]);

        $this->withHeaders([
            ...$this->adminHeaders(),
            'X-Api-Key' => 'test-api-key',
        ])->getJson('/api/search?q=superna')
            ->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('data.0.id', 'supernatural-draft');
    }

    public function test_v4_public_search_keeps_customer_list_contract_for_authenticated_admin(): void
    {
        DB::table('movie')->insert([
            'id' => 'supernatural-v4-draft',
            'title' => 'Supernatural Legacy',
            'status' => 'Draft',
            'isSeason' => true,
        ]);

        DB::table('movie')->insert([
            'id' => 'supernatural-v4-published',
            'title' => 'Supernatural Published',
            'status' => 'Published',
            'isEnable' => true,
            'isMizo' => true,
            'isAgeRestricted' => false,
            'isSeason' => true,
        ]);

        $this->withHeaders($this->adminHeaders())
            ->getJson('/api/v4/catalog/items/search?q=superna')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', 'supernatural-v4-published');
    }

    public function test_admin_movie_search_paginates_results(): void
    {
        foreach (['Alpha One', 'Alpha Three', 'Alpha Two'] as $index => $title) {
            DB::table('movie')->insert([
                'id' => 'alpha-'.$index,
                'title' => $title,
                'status' => 'Published',
                'isSeason' => false,
            ]);
        }

        $this->withHeaders($this->adminHeaders())
            ->getJson('/api/v4/admin/catalog/items/search?q=Alpha&per_page=2&page=2')
            ->assertOk()
            ->assertJsonPath('data.count', 3)
            ->assertJsonPath('data.pagination.current_page', 2)
            ->assertJsonPath('data.pagination.last_page', 2)
            ->assertJsonCount(1, 'data.data');
    }

    public function test_admin_can_load_a_movie_for_editing(): void
    {
        DB::table('movie')->insert([
            'id' => 'editable-movie',
            'title' => 'Editable Movie',
            'director' => 'Admin Director',
            'status' => 'Draft',
            'isSeason' => false,
        ]);

        $this->withHeaders($this->adminHeaders())
            ->getJson('/api/v4/admin/catalog/items/editable-movie?type=movie')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', 'editable-movie')
            ->assertJsonPath('data.title', 'Editable Movie');
    }

    public function test_admin_movie_links_are_decrypted_for_editing(): void
    {
        $url = 'https://cdn.zostream.in/Normal/Test/movie.mpd';
        $key = hash(
            'sha256',
            'd4c6198dabafb243b0d043a3c33a9fe171f81605158c267c7dfe5f66df29559a',
            true
        );
        $iv = random_bytes(16);
        $encrypted = base64_encode($iv.openssl_encrypt($url, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv));

        DB::table('movie')->insert([
            'id' => 'encrypted-link-movie',
            'title' => 'Encrypted Link Movie',
            'url' => $encrypted,
            'dash_url' => $encrypted,
            'status' => 'Published',
            'isSeason' => false,
        ]);

        $this->withHeaders($this->adminHeaders())
            ->getJson('/api/v4/admin/catalog/items/encrypted-link-movie/links?type=movie')
            ->assertOk()
            ->assertJsonPath('data.links.url', $url)
            ->assertJsonPath('data.links.dash_url', $url);
    }

    private function adminHeaders(): array
    {
        return [
            'Authorization' => 'Bearer admin-access-token',
            'X-Client-Platform' => 'admin',
            'X-Client-Version' => 'test',
            'X-Device-Type' => 'browser',
        ];
    }
}
