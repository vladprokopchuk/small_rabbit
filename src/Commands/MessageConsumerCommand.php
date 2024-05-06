<?php


namespace SmallRabbit\Commands;

use Illuminate\Console\Command;
use Illuminate\Validation\Rule;
use SmallRabbit\Services\Rabbit;
use SmallRabbit\Services\RabbitHandlerInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use SmallRabbit\Utils\SmallRabbitException;

class MessageConsumerCommand extends Command {

    protected $signature = 'messages:consume
                            {queue : RabbitMQ queue name}
                            {--tries= : number of retries for failed message processing}
                            {--max-time= : number of retries for failed message processing}';

    protected $description = 'Consume message from RabbitMQ: queue --tries= --max-time=';


    /**
     * @param RabbitHandlerInterface $handler
     * @return void
     * @throws SmallRabbitException
     */
    public function handle(RabbitHandlerInterface $handler): void
    {
        $queue = $this->argument('queue') ?? null;
        $tries = $this->option('tries') ?? 0;
        $max_time = $this->option('max-time') ?? 60;
        $this->validateArguments($queue, $tries, $max_time);
        $consumer_handlers = $handler->getConsumers();
        $consumer_class = collect($consumer_handlers)->first(function (string $value, string $key)use($queue) {
            return $key == $queue;
        });

        $consumer_handler = new $consumer_class();
        if ($max_time)
        {
            $consumer_handler->setMaxTime($max_time);
        }
        if ($tries)
        {
            $consumer_handler->setTries($tries);
        }
        $handler->consume($queue, $consumer_handler);
    }

    /**
     * Validate queue worker command arguments
     *
     * @param $queue
     * @param $tries
     * @param $max_execution_time
     * @return void
     */
    protected function validateArguments($queue, $tries, $max_execution_time): void
    {
        $this->error('');
        $validator = Validator::make(
            [
                'queue' => $queue,
                'tries' => $tries,
                'max_time' => $max_execution_time,
            ],
            [
                'queue' => 'string',
                'tries' => 'nullable|integer|min:0|max:20',
                'max_time' => 'nullable|integer|min:1|max:3600',
            ]
        );
        if ($validator->fails()) {
            if (Rabbit::isLogEnabled())
            {
                $this->error('Validation failed: ' . $validator->errors()->first());
                Log::error('Worker initialization error: ' . $validator->errors()->first(), [
                    'exception' => $validator->errors()
                ]);
                exit(1);
            }

            exit("Worker initialization error\n");
        }
    }

}


