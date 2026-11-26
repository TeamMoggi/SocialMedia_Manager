<?php

namespace App\Console\Commands;

use App\Domain\System\IdleWatch;
use Illuminate\Console\Command;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Runs the app on 127.0.0.1 only while it is used: starts PHP's built-in server, and stops it
 * (and itself) once nobody has used the app for --idle minutes, or when the server ends.
 */
class ServeCommand extends Command
{
    protected $signature = 'smm:serve {--port= : Port (default SMM_PORT)} {--idle= : Minutes without use before stopping (default 30)}';

    protected $description = 'Run the app until it has not been used for a while';

    /** PHP's server logs every address; the LinkedIn sign-in code and state in it are hidden (SM-011). */
    public static function redact(string $output): string
    {
        return (string) preg_replace('/([?&](?:code|state)=)[^&\s]+/', '$1[hidden]', $output);
    }

    public function handle(): int
    {
        $port = (int) ($this->option('port') ?: config('smm.port'));
        $idle = (int) ($this->option('idle') ?: config('smm.idle_minutes'));
        $php = (new PhpExecutableFinder)->find(false) ?: PHP_BINARY;
        $router = base_path('vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php');

        // PHP's server must start inside the public folder, with Laravel's router given by full path.
        $server = new Process([$php, '-S', "127.0.0.1:{$port}", $router], public_path(), null, null, null);
        $log = fopen(app('smm.data_dir').DIRECTORY_SEPARATOR.'logs'.DIRECTORY_SEPARATOR.'server.log', 'ab');
        $server->start(function (string $type, string $output) use ($log): void {
            fwrite($log, self::redact($output));
        });

        IdleWatch::touch();
        $this->info("SocialMedia Manager runs at http://127.0.0.1:{$port} and stops after {$idle} minutes without use.");

        while ($server->isRunning()) {
            sleep(15);
            clearstatcache();
            $last = @filemtime(IdleWatch::activityFile()) ?: time();
            if (IdleWatch::shouldStop($last, time(), $idle)) {
                $this->info('Not used for '.$idle.' minutes: stopping.');
                $server->stop(5);
                break;
            }
        }
        fclose($log);

        return self::SUCCESS;
    }
}
