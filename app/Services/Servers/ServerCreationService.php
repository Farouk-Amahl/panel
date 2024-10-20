<?php

namespace App\Services\Servers;

use App\Enums\ServerState;
use App\Models\ServerVariable;
use Ramsey\Uuid\Uuid;
use Illuminate\Support\Arr;
use App\Models\User;
use Webmozart\Assert\Assert;
use App\Models\Server;
use Illuminate\Support\Collection;
use Illuminate\Database\ConnectionInterface;
use App\Models\Objects\DeploymentObject;
use App\Repositories\Daemon\DaemonServerRepository;
use App\Exceptions\Http\Connection\DaemonConnectionException;
use App\Models\Egg;

class ServerCreationService
{
    public function __construct(
        private ConnectionInterface $connection,
        private DaemonServerRepository $daemonServerRepository,
        private ServerDeletionService $serverDeletionService,
        private VariableValidatorService $validatorService
    ) {
    }

    /**
     * Create a server on the Panel and trigger a request to the Daemon to begin the server creation process.
     * This function will attempt to set as many additional values as possible given the input data.
     */
    public function handle(array $data, ?DeploymentObject $deployment = null, bool $validateVariables = true): Server
    {
        if (!isset($data['oom_killer']) && isset($data['oom_disabled'])) {
            $data['oom_killer'] = !$data['oom_disabled'];
        }

        $egg = Egg::query()->findOrFail($data['egg_id']);

        // Fill missing fields from egg
        $data['image'] = $data['image'] ?? collect($egg->docker_images)->first();
        $data['startup'] = $data['startup'] ?? $egg->startup;

        Assert::false(empty($data['node_id']));

        $eggVariableData = $this->validatorService
            ->setUserLevel(User::USER_LEVEL_ADMIN)
            ->handle(Arr::get($data, 'egg_id'), Arr::get($data, 'environment', []), $validateVariables);

        // Due to the design of the Daemon, we need to persist this server to the disk
        // before we can actually create it on the Daemon.
        //
        // If that connection fails out we will attempt to perform a cleanup by just
        // deleting the server itself from the system.
        /** @var Server $server */
        $server = $this->connection->transaction(function () use ($data, $eggVariableData) {
            // Create the server and assign any additional allocations to it.
            $server = $this->createModel($data);

            $this->storeEggVariables($server, $eggVariableData);

            return $server;
        }, 5);

        try {
            $this->daemonServerRepository->setServer($server)->create(
                Arr::get($data, 'start_on_completion', true) ?? true,
            );
        } catch (DaemonConnectionException $exception) {
            $this->serverDeletionService->withForce()->handle($server);

            throw $exception;
        }

        return $server;
    }

    /**
     * Store the server in the database and return the model.
     *
     * @throws \App\Exceptions\Model\DataValidationException
     */
    private function createModel(array $data): Server
    {
        $uuid = $this->generateUniqueUuidCombo();

        return Server::create([
            'external_id' => Arr::get($data, 'external_id'),
            'uuid' => $uuid,
            'uuid_short' => substr($uuid, 0, 8),
            'node_id' => Arr::get($data, 'node_id'),
            'name' => Arr::get($data, 'name'),
            'description' => Arr::get($data, 'description') ?? '',
            'status' => ServerState::Installing,
            'skip_scripts' => Arr::get($data, 'skip_scripts') ?? isset($data['skip_scripts']),
            'owner_id' => Arr::get($data, 'owner_id'),
            'memory' => Arr::get($data, 'memory'),
            'swap' => Arr::get($data, 'swap'),
            'disk' => Arr::get($data, 'disk'),
            'io' => Arr::get($data, 'io'),
            'cpu' => Arr::get($data, 'cpu'),
            'threads' => Arr::get($data, 'threads'),
            'oom_killer' => Arr::get($data, 'oom_killer') ?? false,
            'ports' => Arr::get($data, 'ports') ?? [],
            'egg_id' => Arr::get($data, 'egg_id'),
            'startup' => Arr::get($data, 'startup'),
            'image' => Arr::get($data, 'image'),
            'database_limit' => Arr::get($data, 'database_limit') ?? 0,
            'allocation_limit' => Arr::get($data, 'allocation_limit') ?? 0,
            'backup_limit' => Arr::get($data, 'backup_limit') ?? 0,
            'docker_labels' => Arr::get($data, 'docker_labels'),
        ]);
    }

    /**
     * Process environment variables passed for this server and store them in the database.
     */
    private function storeEggVariables(Server $server, Collection $variables): void
    {
        $now = now();

        $records = $variables->map(function ($result) use ($server, $now) {
            return [
                'server_id' => $server->id,
                'variable_id' => $result->id,
                'variable_value' => $result->value ?? '',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        })->toArray();

        if (!empty($records)) {
            ServerVariable::query()->insert($records);
        }
    }

    /**
     * Create a unique UUID and UUID-Short combo for a server.
     */
    private function generateUniqueUuidCombo(): string
    {
        $uuid = Uuid::uuid4()->toString();

        $shortUuid = str($uuid)->substr(0, 8);
        if (Server::query()->where('uuid', $uuid)->orWhere('uuid_short', $shortUuid)->exists()) {
            return $this->generateUniqueUuidCombo();
        }

        return $uuid;
    }
}
