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
            $table->date('release_on')->nullable();
            $table->string('status')->nullable();
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
