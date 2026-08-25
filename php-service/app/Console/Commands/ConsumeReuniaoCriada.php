<?php

namespace App\Console\Commands;

use App\Models\Board;
use App\Models\Card;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

class ConsumeReuniaoCriada extends Command
{
    protected $signature = 'rabbitmq:consume-reuniao-criada';

    protected $description = 'Consome eventos da fila reuniao_criada e cria os cards correspondentes no Kanban';

    public function handle(): int
    {
        $host = env('RABBITMQ_HOST', 'rabbitmq');
        $port = (int) env('RABBITMQ_PORT', 5672);
        $user = env('RABBITMQ_USER', 'guest');
        $password = env('RABBITMQ_PASSWORD', 'guest');
        $vhost = env('RABBITMQ_VHOST', '/');
        $queue = 'reuniao_criada';

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
            Log::error('Erro fatal no comando rabbitmq:consume-reuniao-criada', [
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
        $this->info('Mensagem recebida!');
        Log::info('rabbitmq:consume-reuniao-criada — Evento recebido', ['body' => $msg->body]);

        try {
            $payload = json_decode($msg->body, true);

            if (! is_array($payload) || empty($payload['id']) || empty($payload['titulo'])) {
                $this->error('Payload inválido ou incompleto. Mensagem descartada (ACK).');
                Log::warning('Payload inválido na fila reuniao_criada', ['body' => $msg->body]);
                $msg->ack();

                return;
            }

            DB::transaction(function () use ($payload) {
                $reuniaoExistente = DB::table('reunioes_read')->where('id', $payload['id'])->exists();

                if ($reuniaoExistente) {
                    $this->info("Reunião {$payload['id']} já processada. Idempotência: ignorando.");
                    Log::warning("Reunião {$payload['id']} já processada. Ignorando...");

                    return;
                }

                $dataReuniao = $this->parseDataReuniao($payload['data_reuniao'] ?? null);

                DB::table('reunioes_read')->insert([
                    'id' => $payload['id'],
                    'titulo' => $payload['titulo'],
                    'data_reuniao' => $dataReuniao,
                    'organizador_nome' => $payload['organizador_nome'] ?? 'Desconhecido',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $this->info('Reunião salva em reunioes_read.');

                $board = Board::firstOrCreate(['title' => 'Reuniões']);

                $position = (int) Card::where('board_id', $board->id)->max('position');

                Card::create([
                    'board_id' => $board->id,
                    'title' => 'Reunião: '.$payload['titulo'],
                    'description' => "Organizador: ".($payload['organizador_nome'] ?? 'Desconhecido')."\nData: ".$dataReuniao,
                    'position' => $position + 1,
                    'column_id' => 'backlog',
                    'priority' => 0,
                    'assignee' => $payload['organizador_nome'] ?? null,
                    'tags' => ['reuniao'],
                    'source_id' => 'reuniao:'.$payload['id'],
                ]);

                $this->info("Card criado no Kanban Board 'Reuniões'.");

                Cache::forget("reuniao:item:{$payload['id']}");
                Cache::forget('boards:all');
            });

            $msg->ack();
            $this->info('Mensagem processada e confirmada (ACK).');
        } catch (\Throwable $e) {
            $this->error('Erro ao processar mensagem: '.$e->getMessage());
            Log::error('Erro no processamento do evento de reunião', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $msg->nack(true);
        }
    }

    private function parseDataReuniao(mixed $value): string
    {
        if (empty($value)) {
            return now()->toDateTimeString();
        }

        try {
            return Carbon::parse($value)->toDateTimeString();
        } catch (\Throwable) {
            return now()->toDateTimeString();
        }
    }
}
