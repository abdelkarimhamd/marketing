<?php

namespace App\Services;

use App\Models\Lead;
use Illuminate\Support\Arr;

class VariableRenderingService
{
    /**
     * Render template variables from context data.
     *
     * Supported syntax: {{first_name}}, {{company}}, {{lead.city}}.
     *
     * @param array<string, mixed> $variables
     */
    public function renderString(string $template, array $variables): string
    {
        return (string) preg_replace_callback(
            '/\{\{\s*([a-zA-Z0-9_.-]+)\s*\}\}/',
            function (array $matches) use ($variables): string {
                $key = (string) ($matches[1] ?? '');
                $value = Arr::get($variables, $key);

                if (is_array($value) || is_object($value)) {
                    return '';
                }

                if ($value === null) {
                    return '';
                }

                return (string) $value;
            },
            $template
        );
    }

    /**
     * Render all string values in an array payload recursively.
     *
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $variables
     * @return array<string, mixed>
     */
    public function renderArray(array $payload, array $variables): array
    {
        $rendered = [];

        foreach ($payload as $key => $value) {
            if (is_string($value)) {
                $rendered[$key] = $this->renderString($value, $variables);
                continue;
            }

            if (is_array($value)) {
                $rendered[$key] = $this->renderArray($value, $variables);
                continue;
            }

            $rendered[$key] = $value;
        }

        return $rendered;
    }

    /**
     * Build default variable context from a lead model.
     *
     * @return array<string, mixed>
     */
    public function variablesFromLead(Lead $lead): array
    {
        $fullName = trim((string) ($lead->first_name ?? '').' '.(string) ($lead->last_name ?? ''));

        return [
            'id' => $lead->id,
            'first_name' => $lead->first_name,
            'last_name' => $lead->last_name,
            'full_name' => $fullName,
            'email' => $lead->email,
            'phone' => $lead->phone,
            'company' => $lead->company,
            'city' => $lead->city,
            'interest' => $lead->interest,
            'service' => $lead->service,
            'status' => $lead->status,
            'source' => $lead->source,
            'lead' => $lead->toArray(),
        ];
    }
}
