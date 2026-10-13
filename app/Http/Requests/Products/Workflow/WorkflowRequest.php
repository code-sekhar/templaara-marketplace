<?php

namespace App\Http\Requests\Products\Workflow;

use Illuminate\Foundation\Http\FormRequest;

class WorkflowRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }
}
