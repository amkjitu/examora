<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Restricts the practice bank to what the current user is allowed to see:
 * the shared bank, plus their own private questions.
 *
 * This is a global scope on purpose. The bug it replaces was a `meta` marker
 * that every query had to remember to honour — and one that none of them did.
 * Making the safe behaviour the default means a new query site cannot leak by
 * omission; it has to opt out deliberately via `Question::withAllOwners()`.
 *
 * Outside an HTTP request (queue jobs, console commands, Filament admin) there
 * is no authenticated user, so nothing private is visible. Callers that
 * legitimately need every row — the admin, the importance scorer — opt out.
 */
class VisibleQuestionScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $table = $model->getTable();
        $userId = auth()->id();

        $builder->where(function (Builder $query) use ($table, $userId) {
            $query->whereNull("{$table}.owner_id");

            if ($userId !== null) {
                $query->orWhere("{$table}.owner_id", $userId);
            }
        });
    }
}
