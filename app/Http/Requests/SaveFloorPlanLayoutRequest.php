<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\RespondsWithJsonErrors;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PUT /api/{owner|manager}/branches/{branchUuid}/floor-plans/{planUuid}/layout
 *
 * The full layout: every table and element that should exist afterwards.
 * Rows with a uuid are updated, rows without one are created, anything left
 * out is removed. Table status is not part of this payload (the POS owns it).
 */
class SaveFloorPlanLayoutRequest extends FormRequest
{
    use RespondsWithJsonErrors;

    public function rules(): array
    {
        $limits = config('floor_plan.limits');
        $assets = config('floor_plan.assets');

        $tableKeys   = array_keys(array_filter($assets, fn ($a) => $a['category'] === 'table'));
        $elementKeys = array_keys(array_filter($assets, fn ($a) => $a['category'] !== 'table'));

        // x/y is the top-left of the *unrotated* box, which the client rotates around its
        // centre. A quarter-turned wall therefore sits at a negative x or y when it hugs the
        // left/top edge, by at most half its length.
        $minPos = -($limits['canvas_max'] / 2);

        return [
            'tables'                    => ['present', 'array', 'max:' . $limits['max_tables']],
            'tables.*.uuid'             => ['nullable', 'string', 'max:64', 'distinct'],
            'tables.*.table_name'       => ['required', 'string', 'max:60'],
            'tables.*.capacity'         => ['required', 'integer', 'min:1', 'max:' . $limits['max_table_capacity']],
            'tables.*.asset_key'        => ['nullable', 'string', Rule::in($tableKeys)],
            'tables.*.x_location'       => ['required', 'numeric', "min:$minPos"],
            'tables.*.y_location'       => ['required', 'numeric', "min:$minPos"],
            'tables.*.rotation'         => ['nullable', 'numeric', 'min:0', 'max:360'],

            'elements'                  => ['present', 'array', 'max:' . $limits['max_elements']],
            'elements.*.uuid'           => ['nullable', 'string', 'max:64', 'distinct'],
            'elements.*.category'       => ['required', 'string', Rule::in($this->elementCategories())],
            'elements.*.asset_key'      => ['nullable', 'string', Rule::in($elementKeys)],
            'elements.*.label'          => ['nullable', 'string', 'max:120'],
            'elements.*.x_location'     => ['required', 'numeric', "min:$minPos"],
            'elements.*.y_location'     => ['required', 'numeric', "min:$minPos"],
            'elements.*.width'          => ['nullable', 'numeric', 'min:' . $limits['element_min_size'], 'max:' . $limits['canvas_max']],
            'elements.*.height'         => ['nullable', 'numeric', 'min:' . $limits['element_min_size'], 'max:' . $limits['canvas_max']],
            'elements.*.rotation'       => ['nullable', 'numeric', 'min:0', 'max:360'],
            'elements.*.z_index'        => ['nullable', 'integer', 'min:-1000', 'max:1000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            if ($v->errors()->isNotEmpty()) {
                return;
            }

            $seen = [];

            foreach ($this->input('tables', []) as $i => $table) {
                $name = mb_strtolower(trim($table['table_name']));

                if (isset($seen[$name])) {
                    $v->errors()->add("tables.$i.table_name", 'Table names must be unique on a floor plan.');
                }

                $seen[$name] = true;
            }

            $assets = config('floor_plan.assets');
            $drawn  = config('floor_plan.drawn_categories');

            foreach ($this->input('elements', []) as $i => $element) {
                if (in_array($element['category'], $drawn, true)) {
                    // Drawn by the client as a rectangle: size instead of an image.
                    if (! empty($element['asset_key'])) {
                        $v->errors()->add("elements.$i.asset_key", 'This kind of element is drawn, so it has no asset.');
                    }

                    foreach (['width', 'height'] as $size) {
                        if (! isset($element[$size])) {
                            $v->errors()->add("elements.$i.$size", "A {$element['category']} needs a $size.");
                        }
                    }

                    continue;
                }

                if (empty($element['asset_key'])) {
                    $v->errors()->add("elements.$i.asset_key", 'Choose an asset.');
                } elseif (($assets[$element['asset_key']]['category'] ?? null) !== $element['category']) {
                    $v->errors()->add("elements.$i.asset_key", 'This asset does not belong to that category.');
                }
            }
        });
    }

    /** @return array<int, string> */
    private function elementCategories(): array
    {
        $fromAssets = array_filter(
            array_column(config('floor_plan.assets'), 'category'),
            fn ($c) => $c !== 'table'
        );

        return array_values(array_unique([...$fromAssets, ...config('floor_plan.drawn_categories')]));
    }

    public function messages(): array
    {
        return [
            'tables.present'               => 'Send the full list of tables (an empty list removes them all).',
            'elements.present'             => 'Send the full list of elements (an empty list removes them all).',
            'tables.*.table_name.required' => 'Every table needs a name.',
            'tables.*.asset_key.in'        => 'Unknown table asset.',
            'elements.*.asset_key.in'      => 'Unknown element asset.',
        ];
    }
}
