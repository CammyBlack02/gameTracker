<?php

namespace GameTracker\Cli\Commands\Items;

use GameTracker\Cli\Command;
use GameTracker\Cli\Context;
use GameTracker\Cli\UsageException;
use GameTracker\Cli\Http\HttpClient;
use GameTracker\Cli\UserResolver;
use GameTracker\Services\ItemsService;

final class GetCommand implements Command
{
    public const NAME = 'items get';

    public static function name(): string
    {
        return self::NAME;
    }

    public static function description(): string
    {
        return 'Show one item by id';
    }

    public static function allowedOptions(): array
    {
        return ['admin'];
    }

    public function run(array $args, Context $ctx): int
    {
        $id = $args[0] ?? null;

        if ($id === null) {
            throw new UsageException('usage: gt items get <id>');
        }

        if (!preg_match('/^\d+$/', $id)) {
            throw new UsageException("item id must be a positive integer, got '{$id}'");
        }

        if ($ctx->http) {

            return $this->overHttp($ctx, 'api/v2/items/get.php', ['id' => (string)($args[0] ?? '')]);

        }


        $user = UserResolver::resolve($ctx->pdo, $ctx->userRef);
        $isAdmin = $ctx->flag('admin') && ($user['role'] ?? '') === 'admin';

        $item = ItemsService::get($ctx->pdo, (int)$user['id'], (int)$id, $isAdmin);

        $ctx->output->record($item);

        return 0;
    }

    /**
     * Run this command against a v2 endpoint instead of the service layer.
     *
     * Forwards every command-specific option as a query parameter. A valueless
     * flag stays valueless so ArrayOptions::flag() reads it the same way
     * Context::flag() does.
     */
    private function overHttp(Context $ctx, string $path, array $extra = []): int
    {
        $client = HttpClient::fromEnvironment();

        $query = $extra;
        foreach ($ctx->options as $key => $value) {
            $query[$key] = $value === true ? true : (string)$value;
        }

        $ctx->output->record($client->get($path, $query));

        return 0;
    }
}
