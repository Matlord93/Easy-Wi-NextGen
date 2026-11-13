<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Module\Core\Application\AgentNonceStore;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

final class AgentNonceStoreTest extends TestCase
{
    public function testNonceScopeAndExpiry(): void
    {
        $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $db->executeStatement('CREATE TABLE agent_request_nonces (nonce_key VARCHAR(64) PRIMARY KEY, expires_at BIGINT NOT NULL)');
        $store = new AgentNonceStore($db);
        self::assertTrue($store->consume('one', 'same', time() + 300));
        self::assertFalse($store->consume('one', 'same', time() + 300));
        self::assertTrue($store->consume('two', 'same', time() + 300));
        $db->executeStatement('UPDATE agent_request_nonces SET expires_at = :expiry', ['expiry' => time() - 1]);
        self::assertTrue($store->consume('one', 'same', time() + 300));
    }

    public function testOnlyOneConcurrentProcessCanConsumeANonce(): void
    {
        $dir = sys_get_temp_dir().'/agent-nonce-'.bin2hex(random_bytes(8));
        mkdir($dir, 0700);
        $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $dir.'/nonce.db']);
        $db->executeStatement('CREATE TABLE agent_request_nonces (nonce_key VARCHAR(64) PRIMARY KEY, expires_at BIGINT NOT NULL)');
        $autoload = var_export(dirname(__DIR__, 2).'/vendor/autoload.php', true);
        file_put_contents($dir.'/worker.php', '<?php require '.$autoload.'; $db = Doctrine\DBAL\DriverManager::getConnection(["driver"=>"pdo_sqlite","path"=>$argv[1]]); $db->executeStatement("PRAGMA busy_timeout=5000"); $deadline=microtime(true)+5; while (!is_file($argv[2])) {if(microtime(true)>$deadline)exit(2);usleep(1000);} echo (new App\Module\Core\Application\AgentNonceStore($db))->consume("node", "same-nonce", time()+300) ? "accepted" : "replayed";');
        $workers = [];
        try {
            for ($index = 0; $index < 4; ++$index) {
                $worker = new Process([PHP_BINARY, '-c', php_ini_loaded_file(), $dir.'/worker.php', $dir.'/nonce.db', $dir.'/start']);
                $worker->start();
                $workers[] = $worker;
            }
            touch($dir.'/start');
            $accepted = 0;
            foreach ($workers as $worker) {
                $worker->wait();
                self::assertTrue($worker->isSuccessful(), $worker->getErrorOutput());
                self::assertContains($worker->getOutput(), ['accepted', 'replayed']);
                $accepted += 'accepted' === $worker->getOutput() ? 1 : 0;
            }
            self::assertSame(1, $accepted);
        } finally {
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop();
                }
            }
            $db->close();
            (new Filesystem())->remove($dir);
        }
    }
}
