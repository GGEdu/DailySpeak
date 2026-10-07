<?php

namespace App\Http\Requests;

use App\Models\NewsSource;
use App\Services\News\RssFeedReader;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Throwable;

class StoreNewsSourceRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request (the route requires `can:admin`).
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'feed_url' => [
                'bail',
                'required',
                'string',
                'max:2048',
                'url:http,https',
                Rule::unique(NewsSource::class, 'feed_url'),
                $this->readableFeed(...),
            ],
        ];
    }

    /**
     * Only save feeds the harvester can actually read.
     */
    private function readableFeed(string $attribute, string $url, Closure $fail): void
    {
        try {
            $items = app(RssFeedReader::class)->read($url);
        } catch (Throwable) {
            $fail('No RSS feed could be read at this URL.');

            return;
        }

        if ($items->isEmpty()) {
            $fail('The feed does not contain any articles.');
        }
    }
}
