<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreNewsSourceRequest;
use App\Jobs\FetchNewsSource;
use App\Models\NewsSource;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin page to manage the RSS feeds read by `news:fetch`.
 */
class NewsSourceController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/Sources', [
            'sources' => NewsSource::query()
                ->withCount('articles')
                ->orderBy('name')
                ->get()
                ->map(fn (NewsSource $source) => [
                    'id' => $source->id,
                    'name' => $source->name,
                    'feed_url' => $source->feed_url,
                    'category' => $source->category,
                    'is_active' => $source->is_active,
                    'articles_count' => $source->articles_count,
                    'last_fetched_at' => $source->last_fetched_at,
                    'last_error' => $source->last_error,
                ]),
            'categories' => collect(config('news.categories'))
                ->map(fn (string $label, string $key) => ['key' => $key, 'label' => $label])
                ->values(),
        ]);
    }

    public function store(StoreNewsSourceRequest $request): RedirectResponse
    {
        $source = NewsSource::create($request->validated());

        return back()->with('status', "{$source->name} added. It will be read in the next daily run.");
    }

    /**
     * Pause or resume a source.
     */
    public function update(Request $request, NewsSource $source): RedirectResponse
    {
        $source->update($request->validate([
            'is_active' => ['sometimes', 'required', 'boolean'],
            'category' => ['sometimes', 'required', Rule::in(array_keys(config('news.categories')))],
        ]));

        return back();
    }

    /**
     * Delete a source; its articles stay in the feed.
     */
    public function destroy(NewsSource $source): RedirectResponse
    {
        $source->delete();

        return back()->with('status', "{$source->name} deleted.");
    }

    /**
     * Harvest a single source now, on its own queue (see FetchNewsSource for why it is not `news:fetch`).
     */
    public function fetch(NewsSource $source): RedirectResponse
    {
        FetchNewsSource::dispatch($source->id);

        return back()->with('status', "Fetching {$source->name}… new articles will appear in the feed as they are summarised.");
    }
}
