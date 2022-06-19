<?php

namespace Amp\Mysql;

use Amp\Cancellation;
use Amp\DeferredFuture;
use Amp\Socket\EncryptableSocket;
use Amp\Sql\TransactionIsolation;
use Amp\Sql\TransactionIsolationLevel;
use Revolt\EventLoop;

final class Connection implements Link
{
    private readonly Internal\Processor $processor;

    private ?DeferredFuture $busy = null;

    /** @var \Closure():void Function used to release connection after a transaction has completed. */
    private readonly \Closure $release;

    public static function initialize(
        EncryptableSocket $socket,
        MysqlConfig $config,
        ?Cancellation $cancellation = null,
    ): self {
        $processor = new Internal\Processor($socket, $config);
        $processor->connect($cancellation);
        return new self($processor);
    }

    private function __construct(Internal\Processor $processor)
    {
        $this->processor = $processor;

        $busy = &$this->busy;
        $this->release = static function () use (&$busy): void {
            \assert($busy instanceof DeferredFuture);
            $busy->complete();
            $busy = null;
        };
    }

    /**
     * @return bool False if the connection has been closed.
     */
    public function isClosed(): bool
    {
        return $this->processor->isClosed();
    }

    /**
     * @return int Timestamp of the last time this connection was used.
     */
    public function getLastUsedAt(): int
    {
        return $this->processor->getLastUsedAt();
    }

    public function isReady(): bool
    {
        return $this->processor->isReady();
    }

    public function setCharset(string $charset, string $collate = ""): void
    {
        $this->processor->setCharset($charset, $collate)->await();
    }

    public function close(): void
    {
        // Send close command if connection is not already in a closed or closing state
        if (!$this->processor->isClosed()) {
            $this->processor->sendClose()->await();
        }
    }

    public function onClose(\Closure $onClose): void
    {
        $this->processor->onClose($onClose);
    }

    public function useDb(string $db): void
    {
        $this->processor->useDb($db)->await();
    }

    public function query(string $sql): Result
    {
        while ($this->busy) {
            $this->busy->getFuture()->await();
        }

        return $this->processor->query($sql)->await();
    }

    public function beginTransaction(
        TransactionIsolation $isolation = TransactionIsolationLevel::Committed
    ): Transaction {
        while ($this->busy) {
            $this->busy->getFuture()->await();
        }

        $this->busy = $deferred = new DeferredFuture;

        try {
            $this->processor->query("SET SESSION TRANSACTION ISOLATION LEVEL " . $isolation->toSql())->await();
            $this->processor->query("START TRANSACTION")->await();
        } catch (\Throwable $exception) {
            $this->busy = null;
            $deferred->complete();
            throw $exception;
        }

        return new Internal\ConnectionTransaction($this->processor, $this->release, $isolation);
    }

    public function ping(): void
    {
        $this->processor->ping()->await();
    }

    public function prepare(string $sql): Statement
    {
        while ($this->busy) {
            $this->busy->getFuture()->await();
        }

        return $this->processor->prepare($sql)->await();
    }

    public function execute(string $sql, array $params = []): Result
    {
        $statement = $this->prepare($sql);
        return $statement->execute($params);
    }

    public function __destruct()
    {
        $processor = $this->processor;
        EventLoop::queue(static fn () => $processor->unreference());
    }
}
