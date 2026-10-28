<?php

declare(strict_types=1);

namespace App\Contracts;

interface AIManagerInterface
{
    public function evaluateTransferOffer(array $offer,array $player,string $perspective): array;
    public function chooseDailyActions(int $saveId,string $date): array;
}
