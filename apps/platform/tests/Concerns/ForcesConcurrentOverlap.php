<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/**
 * Parent-process side of a deterministic two-process race; the child
 * side is Tests\Support\Concurrency\HeldTransaction (read its docblock).
 *
 * raceWithHeldHolder() proves genuine overlap instead of assuming it:
 * the holder's write is uncommitted, the contender is positively
 * observed BLOCKED ON A LOCK in pg_stat_activity, and only then is the
 * holder allowed to commit. The deadlines below only bound how long a
 * broken run may hang; they are not the race window, and a timeout
 * fails with both processes' output rather than passing.
 */
trait ForcesConcurrentOverlap
{
    /**
     * @param  array<int, string>  $holderCommand
     * @param  array<int, string>  $contenderCommand
     * @return array{0: string, 1: string} [holder output, contender output]
     */
    protected function raceWithHeldHolder(array $holderCommand, array $contenderCommand): array
    {
        $dir = sys_get_temp_dir().'/race_'.bin2hex(random_bytes(8));
        mkdir($dir);
        $sessionName = 'race_contender_'.bin2hex(random_bytes(6));

        $holder = new Process($holderCommand, null, ['CONCURRENCY_HOLD_DIR' => $dir]);
        $holder->setTimeout(180);
        $contender = null;

        try {
            $holder->start();

            $deadline = microtime(true) + 90;
            while (! file_exists($dir.'/acted')) {
                if (! $holder->isRunning() || microtime(true) > $deadline) {
                    $this->fail("The holder process never reached its held write.\nOutput: ".$holder->getOutput()."\nError: ".$holder->getErrorOutput());
                }
                usleep(2_000);
            }

            $contender = new Process($contenderCommand, null, ['CONCURRENCY_SESSION_NAME' => $sessionName]);
            $contender->setTimeout(180);
            $contender->start();

            $blocked = false;
            $deadline = microtime(true) + 90;
            while (microtime(true) < $deadline) {
                $row = DB::connection('pgsql_admin')->selectOne(
                    'select wait_event_type from pg_stat_activity where application_name = ?',
                    [$sessionName],
                );
                if ($row !== null && $row->wait_event_type === 'Lock') {
                    $blocked = true;
                    break;
                }
                if (! $contender->isRunning()) {
                    break;
                }
                usleep(2_000);
            }

            $this->assertTrue(
                $blocked,
                "The contender was never observed blocked on the holder's uncommitted write -- overlap not proven.\n".
                'Contender output: '.$contender->getOutput()."\nError: ".$contender->getErrorOutput(),
            );
        } finally {
            touch($dir.'/release');
            $holder->wait();
            $contender?->wait();
            @unlink($dir.'/acted');
            @unlink($dir.'/release');
            @rmdir($dir);
        }

        return [trim($holder->getOutput()), trim($contender->getOutput())];
    }
}
