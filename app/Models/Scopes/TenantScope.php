<?php

namespace App\Models\Scopes;

use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        /** @var TenantContext $context */
        $context = app(TenantContext::class);

        if ($context->isBypassed()) {
            return;
        }

        if ($context->restaurantId() === null) {
            // No tenant context established (e.g. unauthenticated, or super
            // admin acting without an explicit restaurant selected). Force an
            // impossible condition rather than silently returning everyone's
            // data, so a missing middleware fails closed, not open.
            $builder->whereRaw('1 = 0');

            return;
        }

        $builder->where($model->getQualifiedTenantColumn(), $context->restaurantId());
    }
}
