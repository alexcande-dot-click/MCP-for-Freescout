<?php

namespace Modules\McpForFreescout\Providers;

use App\Option;
use Illuminate\Support\ServiceProvider;
use Modules\McpForFreescout\Entities\McpToken;
use Modules\McpForFreescout\Mcp\ToolRegistry;
use Modules\McpForFreescout\Support\Attachments;
use Modules\McpForFreescout\Support\CurlRemoteFetcher;
use Modules\McpForFreescout\Support\DraftChoices;
use Modules\McpForFreescout\Support\RemoteFetcher;
use Modules\McpForFreescout\Tools\Attachments\AddAttachmentsTool;
use Modules\McpForFreescout\Tools\Attachments\GetAttachmentTool;
use Modules\McpForFreescout\Tools\Attachments\RemoveAttachmentTool;
use Modules\McpForFreescout\Tools\Conversations\AddNoteTool;
use Modules\McpForFreescout\Tools\Conversations\DeleteConversationTool;
use Modules\McpForFreescout\Tools\Conversations\GetConversationTool;
use Modules\McpForFreescout\Tools\Conversations\ListConversationsTool;
use Modules\McpForFreescout\Tools\Conversations\RestoreConversationTool;
use Modules\McpForFreescout\Tools\Conversations\SearchConversationsTool;
use Modules\McpForFreescout\Tools\Conversations\StarConversationTool;
use Modules\McpForFreescout\Tools\Conversations\UpdateConversationTool;
use Modules\McpForFreescout\Tools\Directory\GetCustomerTool;
use Modules\McpForFreescout\Tools\Directory\ListUsersTool;
use Modules\McpForFreescout\Tools\Directory\SearchCustomersTool;
use Modules\McpForFreescout\Tools\Drafts\CreateConversationTool;
use Modules\McpForFreescout\Tools\Drafts\DiscardDraftTool;
use Modules\McpForFreescout\Tools\Drafts\ReplyToConversationTool;
use Modules\McpForFreescout\Tools\Drafts\SendDraftTool;
use Modules\McpForFreescout\Tools\Drafts\UpdateDraftTool;
use Modules\McpForFreescout\Tools\Mailboxes\GetMailboxTool;
use Modules\McpForFreescout\Tools\Mailboxes\ListMailboxesTool;
use Modules\McpForFreescout\Tools\Mailboxes\UpdateMailboxTool;
use Modules\McpForFreescout\Tools\SavedReplies\CreateSavedReplyTool;
use Modules\McpForFreescout\Tools\SavedReplies\DeleteSavedReplyTool;
use Modules\McpForFreescout\Tools\SavedReplies\GetSavedReplyTool;
use Modules\McpForFreescout\Tools\SavedReplies\ListSavedRepliesTool;
use Modules\McpForFreescout\Tools\SavedReplies\UpdateSavedReplyTool;
use Modules\McpForFreescout\Tools\SendLater\CancelScheduledSendTool;
use Modules\McpForFreescout\Tools\Signatures\CreateSignatureTool;
use Modules\McpForFreescout\Tools\Signatures\DeleteSignatureTool;
use Modules\McpForFreescout\Tools\Signatures\ListSignaturesTool;
use Modules\McpForFreescout\Tools\Signatures\UpdateSignatureTool;
use Modules\McpForFreescout\Tools\Tags\AddTagsTool;
use Modules\McpForFreescout\Tools\Tags\CreateTagTool;
use Modules\McpForFreescout\Tools\Tags\DeleteTagTool;
use Modules\McpForFreescout\Tools\Tags\ListTagsTool;
use Modules\McpForFreescout\Tools\Tags\RemoveTagsTool;
use Modules\McpForFreescout\Tools\Tags\UpdateTagTool;

// Module alias.
define('MCP_FOR_FREESCOUT_MODULE', 'mcpforfreescout');

class McpForFreescoutServiceProvider extends ServiceProvider
{
    /**
     * Indicates if loading of the provider is deferred.
     *
     * @var bool
     */
    protected $defer = false;

    /**
     * Boot the module.
     *
     * @return void
     */
    public function boot()
    {
        $this->registerConfig();
        $this->registerViews();
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
        $this->hooks();
    }

    /**
     * Register the module.
     *
     * @return void
     */
    public function register()
    {
        $this->registerTranslations();
        $this->registerToolRegistry();
        $this->app->bind(RemoteFetcher::class, CurlRemoteFetcher::class);
    }

    /**
     * Bind the tool registry as a singleton.
     *
     * The tool class list is collected through the `mcpforfreescout.tools` filter
     * when the registry is first resolved, so modules that hook into it while
     * booting are included.
     *
     * @return void
     */
    protected function registerToolRegistry()
    {
        $this->app->singleton(ToolRegistry::class, function () {
            $registry = new ToolRegistry();

            // A class offered twice (a module hooking in more than once) is registered once.
            foreach (array_unique(\Eventy::filter('mcpforfreescout.tools', [])) as $toolClass) {
                $registry->register($toolClass);
            }

            return $registry;
        });
    }

    /**
     * Register FreeScout hooks.
     *
     * @return void
     */
    public function hooks()
    {
        // Tokens must not outlive the user they act as.
        \Eventy::addAction('user.deleted', function ($deletedUser) {
            McpToken::where('user_id', $deletedUser->id)->delete();
        });

        \Eventy::addFilter('mcpforfreescout.tools', function ($tools) {
            return array_merge($tools, $this->toolClasses());
        });

        // The choices made for a draft are spent once it has been sent.
        foreach (['conversation.user_replied', 'conversation.created_by_user'] as $sentHook) {
            \Eventy::addAction($sentHook, function ($conversation, $thread) {
                if ($thread && DraftChoices::get($thread)) {
                    DraftChoices::clear($thread);
                }
            }, 20, 2);
        }

        $this->assetHooks();
        $this->draftChoicesHooks();
        $this->menuHooks();
        $this->settingsHooks();
    }

    /**
     * The tools this module provides.
     *
     * @return string[]
     */
    private function toolClasses(): array
    {
        return [
            ListMailboxesTool::class,
            GetMailboxTool::class,
            ListUsersTool::class,
            SearchCustomersTool::class,
            GetCustomerTool::class,
            ListConversationsTool::class,
            SearchConversationsTool::class,
            GetConversationTool::class,
            UpdateConversationTool::class,
            AddNoteTool::class,
            DeleteConversationTool::class,
            RestoreConversationTool::class,
            StarConversationTool::class,
            GetAttachmentTool::class,
            ReplyToConversationTool::class,
            CreateConversationTool::class,
            UpdateDraftTool::class,
            AddAttachmentsTool::class,
            RemoveAttachmentTool::class,
            DiscardDraftTool::class,
            SendDraftTool::class,
            UpdateMailboxTool::class,
            ListSignaturesTool::class,
            CreateSignatureTool::class,
            UpdateSignatureTool::class,
            DeleteSignatureTool::class,
            ListSavedRepliesTool::class,
            GetSavedReplyTool::class,
            CreateSavedReplyTool::class,
            UpdateSavedReplyTool::class,
            DeleteSavedReplyTool::class,
            ListTagsTool::class,
            AddTagsTool::class,
            RemoveTagsTool::class,
            CreateTagTool::class,
            UpdateTagTool::class,
            DeleteTagTool::class,
            CancelScheduledSendTool::class,
        ];
    }

    /**
     * Load the module's CSS and JavaScript and initialise the token pages.
     *
     * @return void
     */
    protected function assetHooks()
    {
        \Eventy::addFilter('stylesheets', function ($styles) {
            $styles[] = \Module::getPublicPath(MCP_FOR_FREESCOUT_MODULE).'/css/module.css';

            return $styles;
        });

        \Eventy::addFilter('javascripts', function ($javascripts) {
            $javascripts[] = \Module::getPublicPath(MCP_FOR_FREESCOUT_MODULE).'/js/laroute.js';
            $javascripts[] = \Module::getPublicPath(MCP_FOR_FREESCOUT_MODULE).'/js/module.js';

            return $javascripts;
        });

        \Eventy::addAction('javascript', function () {
            if (\Route::is('mcpforfreescout.tokens.profile') || \Route::is('mcpforfreescout.tokens.admin')) {
                echo 'mcpInitTokens();';
            }
            if (\Route::is('conversations.view')) {
                echo 'mcpffInitDraftChoices();';
            }
        });
    }

    /**
     * Hand the choices an agent made for its drafts to the reply editor.
     *
     * Existing conversations list their drafts after the threads; a draft of a new
     * conversation opens in the create form, which has its own hook.
     *
     * @return void
     */
    protected function draftChoicesHooks()
    {
        foreach (['conversation.after_threads', 'new_conversation_form.after'] as $hook) {
            \Eventy::addAction($hook, function ($conversation) {
                echo view('mcpforfreescout::partials.draft_choices', ['conversation' => $conversation])->render();
            });
        }
    }

    /**
     * Add the "MCP tokens" menu entries.
     *
     * @return void
     */
    protected function menuHooks()
    {
        // Entry in the profile menu of a user, for the user and for administrators.
        \Eventy::addAction('user.profile.menu.after_profile', function ($user) {
            $authUser = auth()->user();
            if (!$authUser || ((int) $authUser->id !== (int) $user->id && !$authUser->isAdmin())) {
                return;
            }

            echo '<li'.(\Route::currentRouteName() == 'mcpforfreescout.tokens.profile' ? ' class="active"' : '').'>'
                .'<a href="'.e(route('mcpforfreescout.tokens.profile', ['id' => $user->id])).'">'
                .'<i class="glyphicon glyphicon-lock"></i> '.e(__('MCP tokens')).'</a></li>';
        });

        // Overview of all tokens in the Manage menu.
        \Eventy::addAction('menu.manage.append', function () {
            if (!auth()->user() || !auth()->user()->isAdmin()) {
                return;
            }

            echo '<li class="'.\App\Misc\Helper::menuSelectedHtml('mcpforfreescout').'">'
                .'<a href="'.e(route('mcpforfreescout.tokens.admin')).'">'.e(__('MCP tokens')).'</a></li>';
        });

        // Keep the Manage menu highlighted on the module's pages.
        \Eventy::addFilter('menu.selected', function ($menu) {
            $menu['manage']['users'][] = 'mcpforfreescout.tokens.profile';
            $menu['manage']['mcpforfreescout'] = ['mcpforfreescout.tokens.admin'];

            return $menu;
        });
    }

    /**
     * Add the "MCP" section to the application settings.
     *
     * @return void
     */
    protected function settingsHooks()
    {
        \Eventy::addFilter('settings.sections', function ($sections) {
            $sections[MCP_FOR_FREESCOUT_MODULE] = ['title' => __('MCP'), 'icon' => 'link', 'order' => 400];

            return $sections;
        });

        \Eventy::addFilter('settings.section_settings', function ($settings, $section) {
            if ($section != MCP_FOR_FREESCOUT_MODULE) {
                return $settings;
            }

            return [
                'mcpforfreescout.enabled'             => Option::get('mcpforfreescout.enabled'),
                'mcpforfreescout.max_attachment_size' => Attachments::maxSize(),
            ];
        }, 20, 2);

        // The form posts the size in megabytes; the option stores bytes.
        \Eventy::addFilter('settings.before_save', function ($request, $section) {
            $key = 'mcpforfreescout.max_attachment_size';
            $posted = $request->input('settings', []);
            if ($section == MCP_FOR_FREESCOUT_MODULE && is_array($posted) && array_key_exists($key, $posted)) {
                $posted[$key] = Attachments::bytesFromMegabytes($posted[$key]);
                $request->merge(['settings' => $posted]);
            }

            return $request;
        }, 20, 2);

        \Eventy::addFilter('settings.view', function ($view, $section) {
            return $section == MCP_FOR_FREESCOUT_MODULE ? 'mcpforfreescout::settings' : $view;
        }, 20, 2);
    }

    /**
     * Register config.
     *
     * @return void
     */
    protected function registerConfig()
    {
        $this->publishes([
            __DIR__.'/../Config/config.php' => config_path('mcpforfreescout.php'),
        ], 'config');
        $this->mergeConfigFrom(
            __DIR__.'/../Config/config.php',
            'mcpforfreescout'
        );
    }

    /**
     * Register views.
     *
     * @return void
     */
    public function registerViews()
    {
        $viewPath = resource_path('views/modules/mcpforfreescout');

        $sourcePath = __DIR__.'/../Resources/views';

        $this->publishes([
            $sourcePath => $viewPath,
        ], 'views');

        $this->loadViewsFrom(array_merge(array_map(function ($path) {
            return $path.'/modules/mcpforfreescout';
        }, \Config::get('view.paths')), [$sourcePath]), 'mcpforfreescout');
    }

    /**
     * Register translations.
     *
     * @return void
     */
    public function registerTranslations()
    {
        $this->loadJsonTranslationsFrom(__DIR__.'/../Resources/lang');
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array
     */
    public function provides()
    {
        return [];
    }
}
