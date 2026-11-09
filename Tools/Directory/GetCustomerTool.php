<?php

namespace Modules\McpForFreescout\Tools\Directory;

use App\User;
use Modules\McpForFreescout\Mcp\Tool;
use Modules\McpForFreescout\Support\Lookup;
use Modules\McpForFreescout\Support\Presenter;

class GetCustomerTool extends Tool
{
    public function name(): string
    {
        return 'get_customer';
    }

    public function description(): string
    {
        return 'Get one customer: name, email addresses, phone numbers and company. '
            .'Unless you are an administrator, only customers with conversations in your mailboxes can be read.';
    }

    public function inputSchema(): array
    {
        return [
            'type'                 => 'object',
            'properties'           => [
                'customer_id' => ['type' => 'integer', 'description' => 'Customer id, from search_customers or a conversation.'],
            ],
            'required'             => ['customer_id'],
            'additionalProperties' => false,
        ];
    }

    public function handle(array $arguments, User $user): array
    {
        $customer = Lookup::customer($this->requireInt($arguments, 'customer_id'), $user);

        return Presenter::customer($customer);
    }
}
