<?php

namespace BaddyBugs\Agent\Collectors;

use BaddyBugs\Agent\BaddyBugs;
use Illuminate\Support\Facades\Validator;

/**
 * Form Collector
 * 
 * Tracks form submissions and validation:
 * - Fields submitted
 * - Validation errors by field
 * - Form completion rates
 * - Input patterns
 * - Validation performance
 */
class FormCollector implements CollectorInterface
{
    protected BaddyBugs $baddybugs;
    protected array $validationAttempts = [];

    public function __construct(BaddyBugs $baddybugs)
    {
        $this->baddybugs = $baddybugs;
    }

    public function boot(): void
    {
        if (!config('baddybugs.form_tracking_enabled', true)) {
            return;
        }

        // Track validation failures
        Validator::resolver(function ($translator, $data, $rules, $messages, $customAttributes) {
            return new class($translator, $data, $rules, $messages, $customAttributes) extends \Illuminate\Validation\Validator {
                public function fails()
                {
                    $result = parent::fails();
                    
                    if ($result && app()->bound(BaddyBugs::class)) {
                        app(FormCollector::class)->trackValidationFailure($this);
                    }
                    
                    return $result;
                }
            };
        });

        // Track on request termination
        app()->terminating(function () {
            $this->collectFormData();
        });
    }

    public function trackValidationFailure($validator): void
    {
        $this->validationAttempts[] = [
            'timestamp' => microtime(true),
            'errors' => $validator->errors()->toArray(),
            'failed_rules' => $validator->failed(),
            'data_keys' => array_keys($validator->getData()),
        ];
    }

    protected function collectFormData(): void
    {
        $request = request();
        
        // Only track POST/PUT/PATCH requests
        if (!in_array($request->method(), ['POST', 'PUT', 'PATCH'])) {
            return;
        }

        $data = $request->except(['_token', '_method', 'password', 'password_confirmation']);
        
        if (empty($data) && empty($this->validationAttempts)) {
            return;
        }

        $formData = [
            'route' => $request->route() ? $request->route()->getName() : null,
            'url' => $request->path(),
            'method' => $request->method(),
            'field_count' => count($data),
            'fields_submitted' => array_keys($data),
            'has_validation_errors' => !empty($this->validationAttempts),
            'validation_attempts_count' => count($this->validationAttempts),
        ];

        // Add validation error details
        if (!empty($this->validationAttempts)) {
            $allErrors = [];
            $errorFields = [];
            
            foreach ($this->validationAttempts as $attempt) {
                foreach ($attempt['errors'] as $field => $errors) {
                    $errorFields[] = $field;
                    $allErrors[$field] = $errors;
                }
            }

            $formData['validation_errors'] = $allErrors;
            $formData['error_fields'] = array_unique($errorFields);
            $formData['error_field_count'] = count(array_unique($errorFields));
            $formData['total_error_count'] = array_sum(array_map('count', $allErrors));
        }

        // Detect patterns
        $formData['likely_form_type'] = $this->detectFormType($data, $request);
        $formData['contains_file_upload'] = $request->hasFile(array_keys($request->allFiles()));

        $this->baddybugs->record('form', 'submission', $formData);
    }

    protected function detectFormType(array $data, $request): string
    {
        $fields = array_keys($data);
        $fieldsStr = implode(',', $fields);

        // Login form
        if (preg_match('/email|username|login/', $fieldsStr) && preg_match('/password/', $fieldsStr)) {
            return 'login';
        }

        // Registration form
        if (preg_match('/name/', $fieldsStr) && preg_match('/email/', $fieldsStr) && preg_match('/password/', $fieldsStr)) {
            return 'registration';
        }

        // Contact form
        if (preg_match('/message|comment/', $fieldsStr) && preg_match('/email|name/', $fieldsStr)) {
            return 'contact';
        }

        // Search form
        if (preg_match('/search|query|q/', $fieldsStr) && count($fields) <= 3) {
            return 'search';
        }

        // Payment/Checkout
        if (preg_match('/card|payment|billing/', $fieldsStr)) {
            return 'payment';
        }

        // Settings/Profile
        if (preg_match('/settings|profile|preferences/', $request->path())) {
            return 'settings';
        }

        return 'generic';
    }
}
