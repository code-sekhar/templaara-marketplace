<?php

namespace App\Services\Products\Workflow;

use App\Enums\ProductFeedbackSection;
use App\Enums\ProductWorkflowAction;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class WorkflowInput
{
    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function validate(ProductWorkflowAction $action, array $data): array
    {
        $rules = [];
        foreach (['actor_id', 'reviewed_by', 'submitted_by', 'published_by', 'created_by', 'status', 'decision', 'product_id', 'product_submission_id'] as $field) {
            $rules[$field] = ['prohibited'];
        }
        $rules['summary'] = $action === ProductWorkflowAction::Reject ? ['required', 'string', 'min:10', 'max:5000'] : ['nullable', 'string', 'max:5000'];
        $rules['feedback'] = $action === ProductWorkflowAction::RequestChanges ? ['required', 'array', 'list', 'min:1', 'max:30'] : ['prohibited'];
        $rules['feedback.*'] = ['array:section,message'];
        $rules['feedback.*.section'] = ['required', Rule::enum(ProductFeedbackSection::class)];
        $rules['feedback.*.message'] = ['required', 'string', 'min:10', 'max:5000'];

        return Validator::make($data, $rules, [
            'feedback.required' => 'Add at least one actionable feedback item when requesting changes.',
            'feedback.*.message.min' => 'Explain each requested change in at least 10 characters.',
            'summary.required' => 'Explain why this product is rejected.',
        ])->validate();
    }
}
