<?php

namespace App\Http\Requests;

use App\Models\Checklist;
use App\Models\ChecklistItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreTestCaseRequest extends FormRequest
{
    private ?Checklist $checklist = null;

    public function authorize(): bool
    {
        $routeChecklist = $this->route('checklist');
        $checklistId = $routeChecklist instanceof Checklist ? (int) $routeChecklist->id : (int) $routeChecklist;

        if ($checklistId <= 0) {
            return false;
        }

        $this->checklist = Checklist::query()
            ->with(['userStories.project', 'project.testers'])
            ->find($checklistId);

        if ($this->checklist === null || $this->checklist->userStories->isEmpty()) {
            return false;
        }

        $user = $this->user();
        if (!$user) {
            return false;
        }

        if ($user->hasRole('admin') || $user->hasRole('chef')) {
            return true;
        }

        if ($user->hasRole('testeur')) {
            if ($this->checklist->project) {
                return $this->checklist->project
                    ->testers()
                    ->where('users.id', $user->id)
                    ->exists();
            }

            return (int) $this->checklist->created_by === (int) $user->id;
        }

        return false;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'min:3', 'max:255'],
            'description' => ['required', 'string', 'min:20', 'max:4000'],
            'priority' => ['required', Rule::in(['Low', 'Medium', 'High'])],
            'criticality' => ['required', Rule::in(['Minor', 'Major', 'Critical'])],
        ];
    }

    public function messages(): array
    {
        return [
            'title.required' => 'Le titre du cas de test est obligatoire.',
            'title.min' => 'Le titre doit contenir au moins :min caracteres.',
            'title.max' => 'Le titre ne peut pas depasser :max caracteres.',
            'description.required' => 'La description est obligatoire.',
            'description.min' => "La description doit contenir au moins :min caracteres pour permettre a l'agent de generer un script pertinent.",
            'description.max' => 'La description ne peut pas depasser :max caracteres.',
            'priority.required' => 'La priorite est obligatoire.',
            'priority.in' => 'La priorite doit etre Low, Medium ou High.',
            'criticality.required' => 'La criticite est obligatoire.',
            'criticality.in' => 'La criticite doit etre Minor, Major ou Critical.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $title = $this->input('title');

            if (!is_string($title) || trim($title) === '' || $this->checklist === null) {
                return;
            }

            $existsQuery = $this->checklist
                ->items()
                ->whereRaw('LOWER(title) = ?', [mb_strtolower(trim($title))]);

            $routeItem = $this->route('item');
            $editingId = $routeItem instanceof ChecklistItem ? (int) $routeItem->id : (int) $routeItem;

            if ($editingId > 0) {
                $existsQuery->where('id', '!=', $editingId);
            }

            if ($existsQuery->exists()) {
                $v->errors()->add('title', 'Un cas de test portant ce titre existe deja dans cette checklist.');
            }
        });
    }

    public function validatedWithContext(): array
    {
        $base = $this->validated();

        $userStory = $this->checklist?->userStories->first();
        $project = $this->checklist?->project ?? $userStory?->project;

        return array_merge($base, [
            '_context' => [
                'checklist_id' => (int) $this->checklist?->id,
                'user_story_id' => (int) $userStory?->id,
                'project_id' => (int) $project?->id,
                'project_app_url' => (string) $project?->app_url,
            ],
        ]);
    }
}