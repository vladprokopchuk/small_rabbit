<?php

namespace SmallRabbit;

use Illuminate\Support\ServiceProvider;
use SmallRabbit\Services\RabbitHandlerInterface;
use SmallRabbit\Services\RabbitHandler;
use SmallRabbit\Commands\MessageConsumerCommand;

class SmallRabbitServiceProvider extends ServiceProvider {

    public function register()
    {
        $this->app->singleton(RabbitHandlerInterface::class, function ($app) {
            return RabbitHandler::getInstance();
        });

        $this->app->alias(RabbitHandlerInterface::class, 'rabbit');
        $this->mergeConfigFrom(
            __DIR__.'/config/smallrabbit.php', 'smallrabbit'
        );
    }

    public function boot()
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                MessageConsumerCommand::class
            ]);
        }
        $this->publishes([
            __DIR__.'/config/smallrabbit.php' => config_path('smallrabbit.php'),
            __DIR__.'/database/migrations' => database_path('migrations')
        ], 'smallrabbit');
    }

}
