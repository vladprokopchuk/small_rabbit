<?php


namespace SmallRabbit\Utils;

use Illuminate\Support\Facades\Log;
use League\CommonMark\Extension\CommonMark\Node\Block\ThematicBreak;
use PhpAmqpLib\Message\AMQPMessage;
use SmallRabbit\Services\Rabbit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;

abstract class AbstractConsumer {

    public ?string $queue;
    protected int $maxTime = 30;
    protected int $tries = 3;
    public ?AMQPMessage $amqpMessage;
    public ?string $message;

    /**
     * Handle using consumer class in message consuming as callable
     *
     * @param AMQPMessage $message
     * @return void
     */
    final function __invoke(AMQPMessage $message)
    {
        $this->amqpMessage = $message;
        $this->message = $message->body;
        register_shutdown_function([$this, 'handleShutdown']);
        $attempt = 0;
        while ($attempt < $this->tries)
        {
            set_time_limit($this->maxTime);
            try
            {

                return $this->handle($message);
                $this->amqpMessage = $message;
                break;
            } catch (\Throwable $e)
            {
                $attempt++;
                if ($attempt >= $this->tries)
                {
                    $this->log("Max attempts reached for consumer:" . $this::class);
                    $this->logToDB($this->message, 'Max attempts reached for consumer. Error:' . $e->getMessage());
                } else
                {
                    $this->log("Attempt #{$attempt} failed for consumer:" . $this::class);
                }
                $this->log("Consumer" . $this::class . "error: ", [$e->getMessage()]);
            }
        };
        set_time_limit(0);
    }

    /**
     * Should be implemented in app consumer class
     */
    public abstract function handle();

    /**
     * Logging timeout exceeding for message consuming
     *
     * @return void
     */
    public function handleShutdown(): void
    {
        $error = error_get_last();
        if ($error && $error['type'] === E_ERROR && strpos($error['message'], 'Maximum execution time') !== false)
        {
            $this->log('Worker timeout limit exсeeded for job processing: ' . $this::class);
//            $this->logToDB($this->message, 'Worker timeout limit exсeeded');
            Process::run(PHP_BINARY . ' artisan messages:error ' . serialize([
                    'error'   => 'Worker timeout limit exceeded',
                    'payload' => $this->message,
                    'class'   => $this::class,
                ]))->output();
        }
    }

    /**
     * Set timeout value in seconds
     *
     * @param int $maxTime
     */
    public function setMaxTime(int $maxTime): void
    {
        $this->maxTime = $maxTime;
    }

    /**
     * Set tries number before consider handling failed
     *
     * @param int $tries
     */
    public function setTries(int $tries): void
    {
        $this->tries = $tries;
    }

    /**
     * Log message consuming handling error
     *
     * @param string $message
     * @param array $payload
     * @return void
     */
    protected function log(string $message, array $payload = []): void
    {
        if (app('rabbit')->isLogEnabled())
        {
            Log::error($message, $payload);
        }
    }

    /**
     * Log message consuming handling error to database table
     *
     * @param string $payload
     * @param string $error
     * @param string|null $class
     * @return void
     */
    protected function logToDB(string $payload, string $error, string $class = null): void
    {
        if (config('smallrabbit.save_not_processed_messages'))
        {
            if (empty($class))
            {
                $class = $this::class;
            }
            DB::table('not_processed_messages')->insert([
                'payload'        => $payload,
                'error'          => $error,
                'consumer_class' => $class,
                'created_at'     => now(),
                'updated_at'     => now(),
            ]);
        }


    }

}
