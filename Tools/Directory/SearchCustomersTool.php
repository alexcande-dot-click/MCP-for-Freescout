<?php

namespace Modules\McpForFreescout\Tools\Directory;

use App\Customer;
use App\User;
use Modules\McpForFreescout\Mcp\Tool;
use Modules\McpForFreescout\Support\Presenter;

class SearchCustomersTool extends Tool
{
    const DEFAULT_LIMIT = 20;
    const MAX_LIMIT = 50;

    public function name(): string
    {
        return 'search_customers';
    }

    public function description(): string
    {
        return 'Search customers by name, email address or company. '
            .'Unless you are an administrator, only customers with conversations in your mailboxes are found.';
    }

    public function inputSchema(): array
    {
        return [
            'type'                 => 'object',
            'properties'           => [
                'query' => ['type' => 'string', 'description' => 'Part of a name, email address or company.'],
                'limit' => [
                    'type'        => 'integer',
                    'description' => 'Maximum number of customers to return (default '.self::DEFAULT_LIMIT.', at most '.self::MAX_LIMIT.').',
                    'minimum'     => 1,
                    'maximum'     => self::MAX_LIMIT,
                ],
            ],
            'required'             => ['query'],
            'additionalProperties' => false,
        ];
    }

    public function handle(array $arguments, User $user): array
    {
        $query = trim($this->requireString($arguments, 'query'));
        $limit = $this->optionalIntInRange($arguments, 'limit', self::DEFAULT_LIMIT, 1, self::MAX_LIMIT);

        $like = '%'.\Helper::sqlEscapeLike(mb_strtolower($query)).'%';
        $operator = \Helper::sqlLikeOperator();
        $prefix = \DB::getTablePrefix();
        $fullName = \Helper::isPgSql()
            ? '('.$prefix.'customers.first_name || '."' '".' || '.$prefix.'customers.last_name)'
            : 'CONCAT('.$prefix.'customers.first_name, " ", '.$prefix.'customers.last_name)';

        $customers = Customer::with('emails')
            ->where(function ($match) use ($operator, $like, $fullName) {
                $match->where('customers.first_name', $operator, $like)
                    ->orWhere('customers.last_name', $operator, $like)
                    ->orWhere(\DB::raw($fullName), $operator, $like)
                    ->orWhere('customers.company', $operator, $like)
                    ->orWhereIn('customers.id', function ($emails) use ($operator, $like) {
                        $emails->select('customer_id')->from('emails')->where('email', $operator, $like);
                    });
            });

        if (!$user->isAdmin()) {
            $mailboxIds = $user->mailboxesIdsCanView();
            $customers->whereIn('customers.id', function ($conversations) use ($mailboxIds) {
                $conversations->select('customer_id')->from('conversations')->whereIn('mailbox_id', $mailboxIds);
            });
        }

        $found = $customers->orderBy('customers.first_name')
            ->orderBy('customers.last_name')
            ->orderBy('customers.id')
            ->limit($limit)
            ->get();

        $presented = [];
        foreach ($found as $customer) {
            $presented[] = Presenter::customer($customer);
        }

        return ['customers' => $presented];
    }
}
