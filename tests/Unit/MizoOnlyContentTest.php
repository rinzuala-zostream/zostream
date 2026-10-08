<?php

namespace Tests\Unit;

use App\Support\MizoOnlyContent;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MizoOnlyContentTest extends TestCase
{
    public function test_trusted_identity_takes_precedence_over_client_supplied_user_ids(): void
    {
        $request = Request::create('/search?user_id=query-user', 'GET');
        $request->headers->set('X-User-Id', 'header-user');
        $request->merge(['auth_user_id' => MizoOnlyContent::USER_ID]);

        $this->assertSame(MizoOnlyContent::USER_ID, MizoOnlyContent::userId($request));
        $this->assertTrue(MizoOnlyContent::appliesTo(MizoOnlyContent::userId($request)));
    }

    #[DataProvider('unrestrictedUsers')]
    public function test_restriction_does_not_apply_to_other_users(string $userId): void
    {
        $this->assertFalse(MizoOnlyContent::appliesTo($userId));
    }

    public static function unrestrictedUsers(): array
    {
        return [[''], ['guest'], ['another-user']];
    }
}
