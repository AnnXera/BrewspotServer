<?php

namespace App\Services;

use App\Http\Resources\BranchTableResource;
use App\Http\Resources\FloorPlanResource;
use App\Models\CafeBranch;
use App\Models\FloorPlan;
use App\Models\User;
use App\Repository\FloorPlanRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Floor plans of a branch (several per branch, one active), their layout
 * (tables + decor), and table status.
 *
 * Everything is scoped through the branch resolved by `branch.access` (or the
 * POS device's branch), so a uuid from another branch always reads as "not found".
 *
 * Results carry an `http` key that the controller turns into the status code.
 */
class FloorPlanService
{
    public function __construct(
        private readonly FloorPlanRepository $repo
    ) {}

    // ── Catalog ──────────────────────────────────────────────────────────

    public function assets(): array
    {
        $assets = [];

        foreach (config('floor_plan.assets') as $key => $meta) {
            $assets[] = ['key' => $key, ...$meta];
        }

        return [
            'success'              => true,
            'assets'               => $assets,
            'drawn_categories'     => config('floor_plan.drawn_categories'),
            'table_statuses'       => config('floor_plan.table_statuses'),
            'reservation_statuses' => config('floor_plan.reservation_statuses'),
            'reservation_rules'    => config('floor_plan.reservations'),
            'limits'               => config('floor_plan.limits'),
        ];
    }

    // ── Plans ────────────────────────────────────────────────────────────

    public function list(CafeBranch $branch): array
    {
        return [
            'success'     => true,
            'floor_plans' => FloorPlanResource::collection($this->repo->listForBranch($branch->branch_id)),
        ];
    }

    public function show(CafeBranch $branch, string $planUuid): array
    {
        $plan = $this->repo->findForBranch($planUuid, $branch->branch_id);

        if (! $plan) {
            return $this->notFound();
        }

        return ['success' => true, 'floor_plan' => $this->detail($plan)];
    }

    public function create(CafeBranch $branch, array $data): array
    {
        if ($this->pointsOutside($data['boundary_points'] ?? null, (float) $data['canvas_width'], (float) $data['canvas_height'])) {
            return $this->invalid('boundary_points', 'Boundary points must be inside the canvas.');
        }

        $plan = $this->repo->create($branch->branch_id, $data);

        return [
            'success'    => true,
            'http'       => 201,
            'message'    => "Floor plan \"{$plan->floorplan_name}\" created.",
            'floor_plan' => $this->detail($plan),
        ];
    }

    public function update(CafeBranch $branch, string $planUuid, array $data): array
    {
        $plan = $this->repo->findForBranch($planUuid, $branch->branch_id);

        if (! $plan) {
            return $this->notFound();
        }

        $width  = (float) ($data['canvas_width'] ?? $plan->canvas_width);
        $height = (float) ($data['canvas_height'] ?? $plan->canvas_height);

        if ($plan->tables()->max('x_location') > $width || $plan->elements()->max('x_location') > $width
            || $plan->tables()->max('y_location') > $height || $plan->elements()->max('y_location') > $height) {
            return $this->invalid('canvas_width', 'Tables or decor sit outside the new canvas size. Move them first.');
        }

        $points = array_key_exists('boundary_points', $data) ? $data['boundary_points'] : $plan->boundary_points;

        if ($this->pointsOutside($points, $width, $height)) {
            return $this->invalid('boundary_points', 'Boundary points must be inside the canvas.');
        }

        $this->repo->update($plan, $data);

        return ['success' => true, 'message' => 'Floor plan updated.', 'floor_plan' => $this->detail($plan)];
    }

    public function delete(User $actor, CafeBranch $branch, string $planUuid): array
    {
        $plan = $this->repo->findForBranch($planUuid, $branch->branch_id);

        if (! $plan) {
            return $this->notFound();
        }

        if ($this->repo->planHasUpcomingReservations($plan)) {
            return ['success' => false, 'http' => 409, 'message' => 'This floor plan has upcoming reservations. Cancel or move them first.'];
        }

        $this->repo->delete($plan);

        Log::info('Floor plan deleted.', ['plan_uuid' => $plan->uuid, 'branch_uuid' => $branch->uuid, 'by_uuid' => $actor->uuid]);

        return ['success' => true, 'message' => "Floor plan \"{$plan->floorplan_name}\" deleted."];
    }

    public function activate(User $actor, CafeBranch $branch, string $planUuid): array
    {
        $plan = $this->repo->findForBranch($planUuid, $branch->branch_id);

        if (! $plan) {
            return $this->notFound();
        }

        $this->repo->activate($plan);

        Log::info('Floor plan activated.', ['plan_uuid' => $plan->uuid, 'branch_uuid' => $branch->uuid, 'by_uuid' => $actor->uuid]);

        return ['success' => true, 'message' => "\"{$plan->floorplan_name}\" is now the active floor plan.", 'floor_plan' => $this->detail($plan)];
    }

    // ── Layout ───────────────────────────────────────────────────────────

    /**
     * Replace the layout in one transaction: update rows with a uuid, create
     * rows without one, remove the rest. Tables are soft deleted and can't be
     * removed while they hold an upcoming reservation.
     */
    public function saveLayout(CafeBranch $branch, string $planUuid, array $tables, array $elements): array
    {
        $plan = $this->repo->findForBranch($planUuid, $branch->branch_id);

        if (! $plan) {
            return $this->notFound();
        }

        $errors = $this->outOfCanvas($plan, 'tables', $tables) + $this->outOfCanvas($plan, 'elements', $elements);

        if ($errors) {
            return ['success' => false, 'http' => 422, 'message' => 'Validation failed.', 'errors' => $errors];
        }

        try {
            DB::transaction(function () use ($plan, $tables, $elements) {
                $this->syncTables($plan, $tables);
                $this->syncElements($plan, $elements);
            });
        } catch (\DomainException $e) {
            return ['success' => false, 'http' => $e->getCode(), 'message' => $e->getMessage()];
        }

        return ['success' => true, 'message' => 'Layout saved.', 'floor_plan' => $this->detail($plan)];
    }

    private function syncTables(FloorPlan $plan, array $rows): void
    {
        $existing = $plan->tables()->get()->keyBy('uuid');
        $kept     = [];

        foreach ($rows as $i => $row) {
            if (! empty($row['uuid'])) {
                $table = $existing->get($row['uuid']) ?? throw new \DomainException("tables.$i.uuid does not belong to this floor plan.", 422);
                $table->update($this->tableAttributes($row, false));
                $kept[] = $table->uuid;
            } else {
                $kept[] = $plan->tables()->create($this->tableAttributes($row, true))->uuid;
            }
        }

        foreach ($existing->reject(fn ($table) => in_array($table->uuid, $kept, true)) as $table) {
            if ($this->repo->hasUpcomingReservations($table)) {
                throw new \DomainException("Table \"{$table->table_name}\" has upcoming reservations and can't be removed.", 409);
            }

            $table->delete();
        }
    }

    private function syncElements(FloorPlan $plan, array $rows): void
    {
        $existing = $plan->elements()->get()->keyBy('uuid');
        $kept     = [];

        foreach ($rows as $i => $row) {
            $attributes = [
                'category'   => $row['category'],
                'asset_key'  => $row['asset_key'] ?? null,
                'label'      => $row['label'] ?? null,
                'x_location' => $row['x_location'],
                'y_location' => $row['y_location'],
                'width'      => $row['width'] ?? null,
                'height'     => $row['height'] ?? null,
                'rotation'   => $row['rotation'] ?? 0,
                'z_index'    => $row['z_index'] ?? 0,
            ];

            if (! empty($row['uuid'])) {
                $element = $existing->get($row['uuid']) ?? throw new \DomainException("elements.$i.uuid does not belong to this floor plan.", 422);
                $element->update($attributes);
                $kept[] = $element->uuid;
            } else {
                $kept[] = $plan->elements()->create($attributes)->uuid;
            }
        }

        $plan->elements()->whereNotIn('uuid', $kept)->delete();
    }

    /** Status is only set on create here; later changes go through updateTableStatus(). */
    private function tableAttributes(array $row, bool $creating): array
    {
        $attributes = [
            'table_name' => trim($row['table_name']),
            'capacity'   => (int) $row['capacity'],
            'asset_key'  => $row['asset_key'] ?? null,
            'x_location' => $row['x_location'],
            'y_location' => $row['y_location'],
            'rotation'   => $row['rotation'] ?? 0,
        ];

        return $creating ? $attributes + ['status' => 'available'] : $attributes;
    }

    private function pointsOutside(?array $points, float $width, float $height): bool
    {
        foreach ($points ?? [] as $point) {
            if ((float) $point['x'] > $width || (float) $point['y'] > $height) {
                return true;
            }
        }

        return false;
    }

    private function outOfCanvas(FloorPlan $plan, string $field, array $rows): array
    {
        $errors = [];

        foreach ($rows as $i => $row) {
            if ((float) $row['x_location'] > (float) $plan->canvas_width) {
                $errors["$field.$i.x_location"] = ['This is outside the canvas.'];
            }

            if ((float) $row['y_location'] > (float) $plan->canvas_height) {
                $errors["$field.$i.y_location"] = ['This is outside the canvas.'];
            }
        }

        return $errors;
    }

    // ── Table status ─────────────────────────────────────────────────────

    public function updateTableStatus(CafeBranch $branch, string $tableUuid, string $status): array
    {
        $table = $this->repo->findTableForBranch($tableUuid, $branch->branch_id);

        if (! $table) {
            return ['success' => false, 'http' => 404, 'message' => 'Table not found.'];
        }

        $table->update(['status' => $status]);

        return ['success' => true, 'message' => "{$table->table_name} is now {$status}.", 'table' => new BranchTableResource($table)];
    }

    // ── Register view ────────────────────────────────────────────────────

    /** The active plan with each table's next booking, or null when none is active. */
    public function activeForRegister(CafeBranch $branch): array
    {
        $plan = $this->repo->findActiveForBranch($branch->branch_id);

        if (! $plan) {
            return ['success' => true, 'floor_plan' => null];
        }

        $this->loadLayout($plan);

        $next = $this->repo->nextReservationsByTable($plan);

        foreach ($plan->tables as $table) {
            $table->setRelation('upcomingReservation', $next->get($table->table_id));
        }

        return ['success' => true, 'floor_plan' => new FloorPlanResource($plan)];
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function detail(FloorPlan $plan): FloorPlanResource
    {
        return new FloorPlanResource($this->loadLayout($plan));
    }

    private function loadLayout(FloorPlan $plan): FloorPlan
    {
        return $plan->load([
            'tables'   => fn ($q) => $q->orderBy('table_name'),
            'elements' => fn ($q) => $q->orderBy('z_index')->orderBy('element_id'),
        ]);
    }

    private function notFound(): array
    {
        return ['success' => false, 'http' => 404, 'message' => 'Floor plan not found.'];
    }

    private function invalid(string $field, string $message): array
    {
        return ['success' => false, 'http' => 422, 'message' => 'Validation failed.', 'errors' => [$field => [$message]]];
    }
}
