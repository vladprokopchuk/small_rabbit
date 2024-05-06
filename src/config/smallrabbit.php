<?php

return [
    'host'                        => env('RABBITMQ_HOST', '192.168.90.9'),
    'port'                        => env('RABBITMQ_PORT', 5672),
    'user'                        => env('RABBITMQ_USER', 'user'),
    'password'                    => env('RABBITMQ_PASSWORD', 'password'),
    'queue'                       => env('RABBITMQ_QUEUE', 'default_queue'),
    'log_errors'                  => true,
    'save_not_processed_messages' => false,
];
