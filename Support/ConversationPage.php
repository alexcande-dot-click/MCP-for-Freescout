<?php

namespace Modules\McpForFreescout\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Turns a conversations query into one page of summaries.
 *
 * Shared by the tools that list conversations, so they agree on ordering and on
 * the shape of the result: `{conversations, page, per_page, total}`.
 */
final class ConversationPage
{
    const DEFAULT_PER_PAGE = 25;
    const MAX_PER_PAGE = 100;
    const MAX_PAGE = 100000;

    /**
     * Run a conversations query for one page, newest reply first.
     *
     * The query may carry its own ordering, joins and grouping (the search query
     * does); its ordering is replaced and `total` counts conversations, not rows.
     *
     * @param Builder|Relation $query
     */
    public static function present($query, int $page, int $perPage): array
    {
        if ($query instanceof Relation) {
            $query = $query->getQuery();
        }

        $query->getQuery()->orders = null;

        $total = (int) \DB::table(\DB::raw('('.$query->toSql().') as mcp_total'))
            ->mergeBindings($query->getQuery())
            ->count();

        $conversations = $query
            ->with('customer')
            ->orderBy('conversations.last_reply_at', 'desc')
            ->orderBy('conversations.id', 'desc')
            ->skip(($page - 1) * $perPage)
            ->take($perPage)
            ->get();

        $presented = [];
        foreach ($conversations as $conversation) {
            $presented[] = Presenter::conversationSummary($conversation);
        }

        return [
            'conversations' => $presented,
            'page'          => $page,
            'per_page'      => $perPage,
            'total'         => $total,
        ];
    }
}
