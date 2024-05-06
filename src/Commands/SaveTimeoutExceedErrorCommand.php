<?php


namespace SmallRabbit\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SaveTimeoutExceedErrorCommand extends Command {

    protected $signature = 'messages:error
                            {payload : Error payload to save}';

    protected $description = 'Save error to DB';


    /**
     * @return void
     */
    public function handle(): void
    {
        $error = $this->argument('payload');
        if(!empty($error))
        {
            $arr = unserialize($error);
            if (is_array($error))
            {
                $handler = app('rabbit');
                if(config('smallrabbit.save_not_processed_messages')){
                    DB::table('not_processed_messages')->insert([
                        'payload' => $error['payload'],
                        'error' => $error['error'],
                        'consumer_class' => $error['class'],
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }

    }


}


