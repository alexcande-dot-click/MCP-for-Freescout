<?php

namespace Modules\McpForFreescout\Mcp;

use App\User;

/**
 * Holds the tools the server can expose.
 */
class ToolRegistry
{
    /**
     * @var Tool[] Tools indexed by name.
     */
    private $tools = [];

    /**
     * Register a tool by class name; the instance is built by the service container.
     *
     * @param string $toolClass Fully qualified name of a Tool subclass.
     *
     * @throws \InvalidArgumentException If the class is not a Tool or its name is already taken.
     */
    public function register(string $toolClass): void
    {
        if (!is_subclass_of($toolClass, Tool::class)) {
            throw new \InvalidArgumentException($toolClass.' is not a '.Tool::class.' subclass.');
        }

        $tool = app()->make($toolClass);
        if (isset($this->tools[$tool->name()])) {
            throw new \InvalidArgumentException('A tool named "'.$tool->name().'" is already registered.');
        }

        $this->tools[$tool->name()] = $tool;
    }

    /**
     * Tools available to a user, sorted by name.
     *
     * @return Tool[]
     */
    public function available(User $user): array
    {
        $available = array_filter($this->tools, function (Tool $tool) use ($user) {
            return $tool->isAvailable($user);
        });
        ksort($available, SORT_STRING);

        return array_values($available);
    }

    /**
     * Find a tool by name, if it exists and is available to the user.
     */
    public function find(string $name, User $user): ?Tool
    {
        if (!isset($this->tools[$name]) || !$this->tools[$name]->isAvailable($user)) {
            return null;
        }

        return $this->tools[$name];
    }
}
