<?php

namespace App\Console\Commands;

use App\Models\Board;
use App\Models\Card;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

class ConsumeKanbanEvents extends Command
{
    protected $signature = 'rabbitmq:consume-kanban-events';

    protected $description = 'Consome eventos da fila kanban_events e sincroniza o Read Model do Kanban';

    public function handle(): int
    {
        $host = env('RABBITMQ_HOST', 'rabbitmq');
        $port = (int) env('RABBITMQ_PORT', 5672);
        $user = env('RABBITMQ_USER', 'guest');
        $password = env('RABBITMQ_PASSWORD', 'guest');
        $vhost = env('RABBITMQ_VHOST', '/');
        $queue = 'kanban_events';

        $this->info("Conectando ao RabbitMQ em {$host}:{$port}...");

        $connection = null;
        $channel = null;

        try {
            $connection = new AMQPStreamConnection(
                $host,
                $port,
                $user,
                $password,
                $vhost,
                false,
                'AMQPLAIN',
                null,
                'en_US',
                3.0,
                3.0,
                null,
                false,
                60
            );
            $channel = $connection->channel();

            $channel->queue_declare($queue, false, true, false, false);
            $channel->basic_qos(0, 1, false);

            $this->info("Aguardando mensagens na fila [{$queue}]. Pressione CTRL+C para sair.");

            $channel->basic_consume(
                $queue,
                '',
                false,
                false,
                false,
                false,
                function (AMQPMessage $msg) {
                    $this->processMessage($msg);
                }
            );

            while ($channel->is_consuming()) {
                $channel->wait();
            }

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Erro de conexão ou execução: '.$e->getMessage());
            Log::error('Erro fatal no comando rabbitmq:consume-kanban-events', [
                'error' => $e->getMessage(),
            ]);

            return Command::FAILURE;
        } finally {
            if ($channel !== null) {
                try {
                    $channel->close();
                } catch (\Throwable) {
                }
            }
            if ($connection !== null) {
                try {
                    $connection->close();
                } catch (\Throwable) {
                }
            }
        }
    }

    private function processMessage(AMQPMessage $msg): void
    {
        $this->info('Mensagem recebida em kanban_events!');
        Log::info('rabbitmq:consume-kanban-events — Evento recebido', ['body' => $msg->body]);

        try {
            $evento = $this->decodeEvent($msg->body);

            if (! is_array($evento) || empty($evento['tipo'])) {
                $this->error('Payload inválido ou incompleto. Mensagem descartada (ACK).');
                Log::warning('Payload inválido na fila kanban_events', ['body' => $msg->body]);
                $msg->ack();

                return;
            }

            $tipo = $evento['tipo'];
            $payload = is_array($evento['payload'] ?? null) ? $evento['payload'] : [];

            DB::transaction(function () use ($tipo, $payload, $evento) {
                match ($tipo) {
                    'CardCriadoEvent', 'CardAtualizadoEvent' => $this->upsertCard($payload),
                    'CardMovidoEvent' => $this->moveCard($payload),
                    'CardDeletadoEvent' => $this->deleteCard($payload),
                    default => Log::warning('Tipo de evento Kanban ignorado', [
                        'tipo' => $tipo,
                        'eventId' => $evento['eventId'] ?? null,
                    ]),
                };

                Cache::forget('boards:all');
            });

            $msg->ack();
            $this->info("Evento {$tipo} processado e confirmado (ACK).");
        } catch (\Throwable $e) {
            $this->error('Erro ao processar mensagem: '.$e->getMessage());
            Log::error('Erro no processamento do evento Kanban', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $msg->nack(true);
        }
    }

    private function decodeEvent(string $body): mixed
    {
        $decoded = json_decode($body, true);

        if (is_string($decoded)) {
            $decoded = json_decode($decoded, true);
        }

        return $decoded;
    }

    private function upsertCard(array $payload): void
    {
        $sourceId = $this->sourceIdFromPayload($payload);

        if ($sourceId === null) {
            throw new \InvalidArgumentException('Evento de card sem id/source_id.');
        }

        $board = Board::firstOrCreate(['title' => 'Kanban']);
        $attributes = $this->mapCardAttributes($payload, $board, $sourceId);

        $card = Card::query()->where('source_id', $sourceId)->first();

        if ($card) {
            unset($attributes['position']);
            $card->update($attributes);
            $this->info("Card source_id={$sourceId} atualizado no Read Model.");

            return;
        }

        Card::create($attributes);
        $this->info("Card source_id={$sourceId} criado no Read Model.");
    }

    private function moveCard(array $payload): void
    {
        $sourceId = $this->sourceIdFromPayload($payload);
        $columnId = $payload['newColumnId'] ?? $payload['columnId'] ?? $payload['column_id'] ?? null;

        if ($sourceId === null || empty($columnId)) {
            throw new \InvalidArgumentException('CardMovidoEvent sem cardId ou newColumnId.');
        }

        $card = Card::query()->where('source_id', $sourceId)->first();

        if (! $card) {
            $this->warn("Card source_id={$sourceId} não encontrado para movimentação. Ignorando.");

            return;
        }

        $card->update(['column_id' => $columnId]);
        $this->info("Card source_id={$sourceId} movido para {$columnId}.");
    }

    private function deleteCard(array $payload): void
    {
        $sourceId = $this->sourceIdFromPayload($payload);

        if ($sourceId === null) {
            throw new \InvalidArgumentException('CardDeletadoEvent sem cardId.');
        }

        $deleted = Card::query()->where('source_id', $sourceId)->delete();

        if ($deleted) {
            $this->info("Card source_id={$sourceId} removido do Read Model.");

            return;
        }

        $this->warn("Card source_id={$sourceId} não encontrado para exclusão. Ignorando.");
    }

    private function sourceIdFromPayload(array $payload): ?string
    {
        $id = $payload['id'] ?? $payload['cardId'] ?? $payload['source_id'] ?? null;

        if ($id === null || $id === '') {
            return null;
        }

        return (string) $id;
    }

    private function mapCardAttributes(array $payload, Board $board, string $sourceId): array
    {
        $position = (int) Card::where('board_id', $board->id)->max('position');

        return [
            'board_id' => $board->id,
            'title' => $payload['title'] ?? 'Sem título',
            'description' => $payload['description'] ?? null,
            'position' => $position + 1,
            'column_id' => $payload['columnId'] ?? $payload['column_id'] ?? 'backlog',
            'priority' => (int) ($payload['priority'] ?? 0),
            'assignee' => $payload['assignee'] ?? null,
            'tags' => $payload['tags'] ?? [],
            'source_id' => $sourceId,
        ];
    }
}
