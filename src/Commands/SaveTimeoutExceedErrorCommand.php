<?php


namespace SmallRabbit\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SaveTimeoutExceedErrorCommand extends Command {

    protected $signature = 'messages:error
                            {--data=: Error payload to save}';

    protected $description = 'Save error to DB';


    /**
     * @return void
     */
    public function handle(): void
    {
        $error = $this->option('data');
        if(!empty($error))
        {
            $arr = json_decode($error, true);

            if (is_array($arr))
            {
                if(config('smallrabbit.save_not_processed_messages')){
                    DB::table('not_processed_messages')->insert([
                        'payload' => $arr['payload'],
                        'error' => $arr['error'],
                        'consumer_class' => $arr['class'],
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }
    }


}


