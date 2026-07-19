<?php

namespace App\Data;

use App\Models\Account;
use App\Models\Sheet;

final readonly class AccountDeletionSummary
{
    public function __construct(
        public int $draft,
        public int $open,
        public int $closed,
        public int $archived,
    ) {}

    public static function for(Account $account): self
    {
        $ownedSheets = Sheet::query()->where('owner_id', $account->id);
        $now = now();

        return new self(
            draft: (clone $ownedSheets)->where('state', Sheet::STATE_DRAFT)->count(),
            open: (clone $ownedSheets)
                ->where('state', Sheet::STATE_PUBLISHED)
                ->where('deadline_at', '>', $now)
                ->count(),
            closed: (clone $ownedSheets)
                ->where(function ($query) use ($now): void {
                    $query->where('state', Sheet::STATE_CLOSED)
                        ->orWhere(function ($query) use ($now): void {
                            $query->where('state', Sheet::STATE_PUBLISHED)
                                ->where('deadline_at', '<=', $now);
                        });
                })
                ->count(),
            archived: (clone $ownedSheets)->where('state', Sheet::STATE_ARCHIVED)->count(),
        );
    }

    /** @return array{draft: int, open: int, closed: int, archived: int} */
    public function toArray(): array
    {
        return [
            'draft' => $this->draft,
            'open' => $this->open,
            'closed' => $this->closed,
            'archived' => $this->archived,
        ];
    }
}
