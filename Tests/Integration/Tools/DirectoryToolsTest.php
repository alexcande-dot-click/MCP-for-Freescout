<?php

namespace Modules\McpForFreescout\Tests\Integration\Tools;

use App\User;
use Modules\McpForFreescout\Mcp\ToolError;
use Modules\McpForFreescout\Tests\Fixtures;
use Modules\McpForFreescout\Tests\TestCase;
use Modules\McpForFreescout\Tools\Directory\GetCustomerTool;
use Modules\McpForFreescout\Tools\Directory\ListUsersTool;
use Modules\McpForFreescout\Tools\Directory\SearchCustomersTool;

class DirectoryToolsTest extends TestCase
{
    public function test_search_customers_scoped_to_user_mailboxes()
    {
        $user = Fixtures::user();
        $mine = Fixtures::mailbox([], [$user]);
        $other = Fixtures::mailbox([], [Fixtures::user()]);

        $visible = Fixtures::customer('zebra-visible@example.test');
        $hidden = Fixtures::customer('zebra-hidden@example.test');
        $unlinked = Fixtures::customer('zebra-unlinked@example.test');
        Fixtures::conversation($mine, $visible);
        Fixtures::conversation($other, $hidden);

        $result = (new SearchCustomersTool())->handle(['query' => 'zebra'], $user);

        $this->assertSame([(int) $visible->id], array_column($result['customers'], 'id'));
        $this->assertSame(['zebra-visible@example.test'], $result['customers'][0]['emails']);
        $this->assertNotContains((int) $unlinked->id, array_column($result['customers'], 'id'));
    }

    public function test_search_customers_admin_sees_all_and_matches_name_and_email()
    {
        $admin = Fixtures::user([], User::ROLE_ADMIN);
        $byEmail = Fixtures::customer('quokka-mail@example.test');
        $byName = Fixtures::customer();
        $byName->first_name = 'Quokka';
        $byName->save();

        $ids = array_column((new SearchCustomersTool())->handle(['query' => 'quokka'], $admin)['customers'], 'id');

        $this->assertContains((int) $byEmail->id, $ids);
        $this->assertContains((int) $byName->id, $ids);
    }

    public function test_search_customers_treats_like_wildcards_literally()
    {
        $admin = Fixtures::user([], User::ROLE_ADMIN);
        $customer = Fixtures::customer('wildcard-check@example.test');

        $result = (new SearchCustomersTool())->handle(['query' => '%'], $admin);

        $this->assertNotContains((int) $customer->id, array_column($result['customers'], 'id'));
    }

    public function test_search_customers_limit_is_applied_and_capped()
    {
        $admin = Fixtures::user([], User::ROLE_ADMIN);
        for ($i = 0; $i < 3; $i++) {
            Fixtures::customer('limitcheck'.$i.'@example.test');
        }
        $tool = new SearchCustomersTool();

        $this->assertCount(2, $tool->handle(['query' => 'limitcheck', 'limit' => 2], $admin)['customers']);
        $this->assertCount(3, $tool->handle(['query' => 'limitcheck', 'limit' => 500], $admin)['customers']);
    }

    public function test_search_customers_requires_a_query()
    {
        $this->expectException(ToolError::class);
        $this->expectExceptionMessage('query');

        (new SearchCustomersTool())->handle([], Fixtures::user());
    }

    public function test_get_customer_returns_the_card()
    {
        $user = Fixtures::user();
        $customer = Fixtures::customer('card@example.test');
        $customer->company = 'Acme';
        $customer->phones = json_encode([['value' => '+15550100', 'type' => 1]]);
        $customer->save();
        Fixtures::conversation(Fixtures::mailbox([], [$user]), $customer);

        $result = (new GetCustomerTool())->handle(['customer_id' => $customer->id], $user);

        $this->assertSame((int) $customer->id, $result['id']);
        $this->assertSame('Test Customer', $result['name']);
        $this->assertSame(['card@example.test'], $result['emails']);
        $this->assertSame(['+15550100'], $result['phones']);
        $this->assertSame('Acme', $result['company']);
    }

    public function test_get_customer_denied_when_only_linked_to_other_mailboxes()
    {
        $user = Fixtures::user();
        Fixtures::mailbox([], [$user]);
        $customer = Fixtures::customer('elsewhere@example.test');
        Fixtures::conversation(Fixtures::mailbox([], [Fixtures::user()]), $customer);

        $this->expectException(ToolError::class);
        $this->expectExceptionMessage('Customer not found or not accessible.');

        (new GetCustomerTool())->handle(['customer_id' => $customer->id], $user);
    }

    public function test_get_customer_denied_without_conversations_for_non_admin()
    {
        $user = Fixtures::user();
        Fixtures::mailbox([], [$user]);
        $customer = Fixtures::customer('loner@example.test');

        $this->expectException(ToolError::class);
        $this->expectExceptionMessage('Customer not found or not accessible.');

        (new GetCustomerTool())->handle(['customer_id' => $customer->id], $user);
    }

    public function test_get_customer_allowed_when_linked_to_an_accessible_mailbox()
    {
        $user = Fixtures::user();
        $customer = Fixtures::customer('linked@example.test');
        Fixtures::conversation(Fixtures::mailbox([], [$user]), $customer);

        $result = (new GetCustomerTool())->handle(['customer_id' => $customer->id], $user);

        $this->assertSame((int) $customer->id, $result['id']);
    }

    public function test_get_customer_allowed_for_admin_without_conversations()
    {
        $admin = Fixtures::user([], User::ROLE_ADMIN);
        $customer = Fixtures::customer('unlinked@example.test');

        $result = (new GetCustomerTool())->handle(['customer_id' => $customer->id], $admin);

        $this->assertSame((int) $customer->id, $result['id']);
    }

    public function test_get_customer_unknown_id()
    {
        $this->expectException(ToolError::class);
        $this->expectExceptionMessage('Customer not found or not accessible.');

        (new GetCustomerTool())->handle(['customer_id' => 999999999], Fixtures::user());
    }

    public function test_list_users_without_mailbox_lists_users_sharing_the_callers_mailboxes()
    {
        $user = Fixtures::user();
        $colleague = Fixtures::user();
        $stranger = Fixtures::user();
        Fixtures::mailbox([], [$user, $colleague]);
        Fixtures::mailbox([], [$stranger]);

        $result = (new ListUsersTool())->handle([], $user);

        $ids = array_column($result['users'], 'id');
        $this->assertContains((int) $colleague->id, $ids);
        $this->assertNotContains((int) $stranger->id, $ids);
        $this->assertSame(['id', 'first_name', 'last_name', 'email', 'role'], array_keys($result['users'][0]));
    }

    public function test_list_users_for_a_mailbox_includes_admins_and_skips_disabled_users()
    {
        $user = Fixtures::user();
        $disabled = Fixtures::user(['status' => User::STATUS_DISABLED]);
        $admin = Fixtures::user([], User::ROLE_ADMIN);
        $mailbox = Fixtures::mailbox([], [$user, $disabled]);

        $result = (new ListUsersTool())->handle(['mailbox_id' => $mailbox->id], $user);

        $ids = array_column($result['users'], 'id');
        $this->assertContains((int) $user->id, $ids);
        $this->assertContains((int) $admin->id, $ids);
        $this->assertNotContains((int) $disabled->id, $ids);
    }

    public function test_list_users_denies_inaccessible_mailbox()
    {
        $mailbox = Fixtures::mailbox([], [Fixtures::user()]);

        $this->expectException(ToolError::class);
        $this->expectExceptionMessage('Mailbox not found or not accessible.');

        (new ListUsersTool())->handle(['mailbox_id' => $mailbox->id], Fixtures::user());
    }

    public function test_admin_lists_all_active_users()
    {
        $admin = Fixtures::user([], User::ROLE_ADMIN);
        $loner = Fixtures::user();
        $disabled = Fixtures::user(['status' => User::STATUS_DISABLED]);

        $ids = array_column((new ListUsersTool())->handle([], $admin)['users'], 'id');

        $this->assertContains((int) $loner->id, $ids);
        $this->assertNotContains((int) $disabled->id, $ids);
    }
}
