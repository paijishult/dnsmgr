<?php
namespace app\lib;

/** Staging must be local; commit submits one provider transaction. */
interface AtomicDns
{
    public function beginAtomicBatch(): void;
    public function commitAtomicBatch(): bool;
    public function cancelAtomicBatch(): void;
}
