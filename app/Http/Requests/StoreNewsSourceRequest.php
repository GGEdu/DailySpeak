<?php

namespace App\Http\Requests;

use App\Models\NewsSource;
use App\Services\News\FeedItem;
use App\Services\News\RssFeedReader;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Throwable;

class StoreNewsSourceRequest extends FormRequest
{
    /** The articles of the feed, kept from validation so the usage check does not download it again. */
    private ?Collection $feedItems = null;

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
            'category' => ['required', 'string', Rule::in(array_keys(config('news.categories')))],
        ];
    }

    /**
     * The articles of the feed, once validation has read it.
     *
     * @return Collection<int, FeedItem>
     */
    public function feedItems(): Collection
    {
        return $this->feedItems ?? collect();
    }

    /**
     * Only save feeds the harvester can actually read, and never from a non-public address.
     *
     * Every failure gets the same message, private addresses included: a different message would tell an
     * admin which hosts are on the internal network (see the S-17 finding).
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

            return;
        }

        $this->feedItems = $items;
    }
}
