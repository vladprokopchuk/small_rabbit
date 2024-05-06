## SmallRabbit

SmallRabbit is a simple wrapper for php-amqplib/php-amqplib library to handle RabbitMQ messaging in Laravel application.

#### Installation
```shell
composer require prokopchuk/small-rabbit
php artisan vendor:publish --tag=smallrabbit
```
add to .env file RabbitMQ server connection credentials:
```shell
#rabbitmq
RABBITMQ_HOST=xxx.xxx.xxx.xxx
RABBITMQ_PORT=5672
RABBITMQ_USER=user
RABBITMQ_PASSWORD=password
RABBITMQ_QUEUE=default_queue
```
in config/smallrabbit.php file update options to your need
run migrations
```shell
php artisan migrate
```
#### How to use:
Commands available via Rabbit facade, example Rabbit::send("Hello world", 'my_queue');
Also container includes SmallRabbit\Services\RabbitHandlerInterface or smallrabit alias to get it from service container.
If You plan to use consumers handler need add consumer classes array to AppServiceProvider:
```shell
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Rabbit::setConsumers([
            'orders' => OrdersConsumer::class
        ]);
    }
    
```
To handle message consuming in worker exists artisan command, example:
```shell
php artisan messages:consume orders
```
with options:
```shell
php artisan messages:consume orders --tries=3 --max-time=30
```
Consumer classes should extend SmallRabbit\Utils\AbstractConsume class.
Message handling logic place in handle method.

```shell
<?php


namespace App\Consumers;

use SmallRabbit\Utils\AbstractConsumer;

class OrdersConsumer extends AbstractConsumer{

    public function handle():void
    {
        // work with message to Your need
        echo $this->message;
    }
}
```
